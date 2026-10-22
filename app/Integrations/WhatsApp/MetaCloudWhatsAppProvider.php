<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;

/** WhatsApp Business Cloud API (graph.facebook.com/{version}/{phone_number_id}/messages). */
final class MetaCloudWhatsAppProvider implements WhatsAppProvider
{
    public function __construct(private readonly array $config) {}

    public function sendTemplate(string $to, string $template, string $language, array $bodyParameters = []): string
    {
        if (empty($this->config['access_token']) || empty($this->config['phone_number_id'])) {
            throw ProviderException::permanent('whatsapp', 'WhatsApp Cloud API credentials are not configured.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($to, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];
        if ($bodyParameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $v) => ['type' => 'text', 'text' => mb_substr($v, 0, 1000)], $bodyParameters),
            ]];
        }

        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $this->config['api_version'], $this->config['phone_number_id']);
        $response = ProviderHttp::send('whatsapp', 'messages.template', fn () => ProviderHttp::client('whatsapp', 10)
            ->withToken($this->config['access_token'])
            ->post($url, $payload));

        return (string) $response->json('messages.0.id');
    }
}
