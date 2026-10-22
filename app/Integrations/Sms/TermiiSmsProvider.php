<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;

/** Termii (primary SMS) — POST {base}/api/sms/send. */
final class TermiiSmsProvider implements SmsProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'termii';
    }

    public function send(string $to, string $message): string
    {
        if (empty($this->config['api_key'])) {
            throw ProviderException::permanent('termii', 'TERMII_API_KEY is not configured.');
        }

        $response = ProviderHttp::send('termii', 'sms.send', fn () => ProviderHttp::client('termii', 10)
            ->post(rtrim($this->config['base_url'], '/').'/api/sms/send', [
                'api_key' => $this->config['api_key'],
                'to' => ltrim($to, '+'),
                'from' => $this->config['sender_id'],
                'sms' => $message,
                'type' => 'plain',
                'channel' => $this->config['channel'] ?? 'dnd',
            ]));

        $id = $response->json('message_id');
        if (! $id) {
            // Termii returns 200 with an error body for some rejections (e.g. invalid sender id).
            throw ProviderException::permanent('termii', 'Unexpected response: '.mb_substr($response->body(), 0, 200));
        }

        return (string) $id;
    }
}
