<?php

declare(strict_types=1);

namespace App\Jobs\Verification;

use App\Integrations\Kyc\KycCheckRequest;
use App\Integrations\Kyc\KycProvider;
use App\Integrations\Media\MediaStorage;
use App\Integrations\ProviderException;
use App\Models\KycVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs the automated identity checks after submission. Results are advisory for the AD5
 * reviewer; a provider outage leaves the case in the queue for fully manual review.
 */
final class RunKycChecks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public readonly string $kycId)
    {
        $this->onQueue('default');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(KycProvider $provider, MediaStorage $storage): void
    {
        $kyc = KycVerification::query()->with(['documentFront', 'selfie'])->find($this->kycId);
        if ($kyc === null || $kyc->checks_status === 'completed') {
            return;
        }

        try {
            $result = $provider->runChecks(new KycCheckRequest(
                reference: $kyc->code,
                firstName: (string) $kyc->legal_first_name,
                lastName: (string) $kyc->legal_last_name,
                dateOfBirth: $kyc->date_of_birth,
                documentType: (string) $kyc->document_type,
                documentNumber: $kyc->document_number,
                documentCountry: $kyc->document_country,
                documentImageUrl: $kyc->documentFront ? $storage->temporaryUrl($kyc->documentFront, 600) : null,
                selfieImageUrl: $kyc->selfie ? $storage->temporaryUrl($kyc->selfie, 600) : null,
            ));
        } catch (ProviderException $e) {
            if ($e->retryable) {
                throw $e;
            }
            $kyc->forceFill(['checks_status' => 'failed', 'automated_checks' => [['label' => 'Automated checks', 'result' => 'Unavailable — review manually', 'passed' => false]]])->save();

            return;
        }

        $kyc->forceFill([
            'provider' => $provider->name(),
            'provider_reference' => $result->providerReference,
            'automated_checks' => $result->checks,
            'extracted' => $result->extracted,
            'checks_status' => 'completed',
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        KycVerification::query()->whereKey($this->kycId)->update(['checks_status' => 'failed']);
    }
}
