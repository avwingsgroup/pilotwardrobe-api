<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Integrations\ProviderException;
use Illuminate\Support\Facades\Log;

/**
 * Tries providers in order (Termii → Twilio). Only failures that mean "this provider
 * did not accept the message" fall through; once any provider returns a message id
 * we stop, so a message is never sent twice. Exactly-once across job retries is
 * guaranteed one level up by the `notification_deliveries` row.
 *
 * @phpstan-type Result array{provider: string, message_id: string}
 */
final class FailoverSmsSender
{
    /** @param list<SmsProvider> $providers */
    public function __construct(private readonly array $providers) {}

    /** @return array{provider: string, message_id: string} */
    public function send(string $to, string $message): array
    {
        $last = null;

        foreach ($this->providers as $provider) {
            try {
                $id = $provider->send($to, $message);

                return ['provider' => $provider->name(), 'message_id' => $id];
            } catch (ProviderException $e) {
                $last = $e;
                Log::warning('sms.provider_failed', [
                    'provider' => $provider->name(),
                    'retryable' => $e->retryable,
                    'status' => $e->httpStatus,
                ]);
            }
        }

        throw $last ?? ProviderException::permanent('sms', 'No SMS provider configured.');
    }
}
