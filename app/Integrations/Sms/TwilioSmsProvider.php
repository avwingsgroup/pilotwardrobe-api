<?php

declare(strict_types=1);

namespace App\Integrations\Sms;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;

/** Twilio Programmable Messaging (fallback SMS). */
final class TwilioSmsProvider implements SmsProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $to, string $message): string
    {
        if (empty($this->config['account_sid']) || empty($this->config['auth_token'])) {
            throw ProviderException::permanent('twilio', 'Twilio credentials are not configured.');
        }

        $sid = $this->config['account_sid'];
        $payload = ['To' => $to, 'Body' => $message];
        if (! empty($this->config['messaging_service_sid'])) {
            $payload['MessagingServiceSid'] = $this->config['messaging_service_sid'];
        } else {
            $payload['From'] = $this->config['from'];
        }

        $response = ProviderHttp::send('twilio', 'sms.send', fn () => ProviderHttp::client('twilio', 10)
            ->withBasicAuth($sid, $this->config['auth_token'])
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload));

        return (string) $response->json('sid');
    }
}
