<?php

declare(strict_types=1);

namespace App\Integrations\Kyc;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Prembly (IdentityPass) adapter.
 *
 *  1. Government-database lookup of the document number (NIN, BVN, passport,
 *     driver's licence, voter's card) → name/DOB comparison with what the user typed.
 *  2. Face comparison of the selfie against the document image.
 *
 * Endpoint paths differ by product and plan, so they are configurable in
 * config/kyc.php. Requests carry `x-api-key` and `app-id` headers. Any document
 * type without a lookup endpoint (e.g. foreign passports) is left to human review.
 */
final class PremblyKycProvider implements KycProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'prembly';
    }

    public function runChecks(KycCheckRequest $request): KycCheckResult
    {
        $checks = [];
        $extracted = [];
        $reference = null;

        $endpoint = config('kyc.prembly.lookup_endpoints.'.$request->documentType);
        if ($endpoint && $request->documentNumber && in_array($request->documentCountry, [null, 'NG'], true)) {
            $response = ProviderHttp::send('prembly', 'lookup.'.$request->documentType, fn () => $this->http()->post($endpoint, array_filter([
                'number' => $request->documentNumber,
                'last_name' => $request->lastName,
                'dob' => $request->dateOfBirth,
            ])));

            $found = (bool) $response->json('status') && in_array($response->json('response_code'), ['00', '0', 0], true);
            $data = (array) ($response->json('data') ?? []);
            $reference = (string) ($response->json('verification.reference') ?? $response->json('reference_id') ?? '') ?: null;

            $checks[] = [
                'label' => 'Document lookup',
                'result' => $found ? 'Record found' : 'No matching record',
                'passed' => $found,
            ];

            if ($found) {
                $docFirst = (string) ($data['firstname'] ?? $data['first_name'] ?? '');
                $docLast = (string) ($data['lastname'] ?? $data['last_name'] ?? $data['surname'] ?? '');
                $docDob = (string) ($data['birthdate'] ?? $data['dateOfBirth'] ?? $data['date_of_birth'] ?? '');

                $extracted[] = $this->compare('Full name', trim($docFirst.' '.$docLast), trim($request->firstName.' '.$request->lastName));
                if ($request->dateOfBirth) {
                    $extracted[] = $this->compareDates('Date of birth', $docDob, $request->dateOfBirth);
                }
                $nameOk = $extracted[0]['match'];
                $checks[] = ['label' => 'Name match', 'result' => $nameOk ? 'Match' : 'Mismatch', 'passed' => $nameOk];
            }
        } else {
            $checks[] = ['label' => 'Document lookup', 'result' => 'Not available for this document — manual review', 'passed' => false];
        }

        if ($request->selfieImageUrl && $request->documentImageUrl && ($faceEndpoint = config('kyc.prembly.face_endpoint'))) {
            $response = ProviderHttp::send('prembly', 'face.comparison', fn () => $this->http()->post($faceEndpoint, [
                'image_one' => $request->documentImageUrl,
                'image_two' => $request->selfieImageUrl,
            ]));
            $confidence = (float) ($response->json('confidence') ?? $response->json('data.confidence') ?? 0);
            $passed = $confidence >= (float) config('kyc.face_match_threshold');
            $checks[] = ['label' => 'Face match', 'result' => number_format($confidence, 1).'%', 'passed' => $passed];
        }

        return new KycCheckResult($checks, $extracted, $reference);
    }

    private function compare(string $field, string $document, string $profile): array
    {
        $normalise = fn (string $v) => collect(preg_split('/\s+/', Str::upper(Str::ascii($v))) ?: [])->filter()->sort()->values()->all();

        return ['field' => $field, 'document' => $document ?: null, 'profile' => $profile, 'match' => $normalise($document) === $normalise($profile)];
    }

    private function compareDates(string $field, string $document, string $profile): array
    {
        $parse = function (string $v): ?string {
            try {
                return $v === '' ? null : Carbon::parse($v)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        };

        return ['field' => $field, 'document' => $document ?: null, 'profile' => $profile, 'match' => $parse($document) !== null && $parse($document) === $parse($profile)];
    }

    private function http(): PendingRequest
    {
        if (empty($this->config['api_key']) || empty($this->config['app_id'])) {
            throw ProviderException::permanent('prembly', 'PREMBLY_API_KEY / PREMBLY_APP_ID are not configured.');
        }

        return ProviderHttp::client('prembly', 30)
            ->baseUrl($this->config['base_url'])
            ->withHeaders(['x-api-key' => $this->config['api_key'], 'app-id' => $this->config['app_id']]);
    }
}
