<?php

declare(strict_types=1);

namespace App\Integrations\Push;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;

/**
 * Firebase Cloud Messaging HTTP v1. Authenticates with a service-account JWT exchanged
 * for an OAuth2 access token (cached until shortly before expiry).
 */
final class FcmPushProvider implements PushProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(private readonly array $config) {}

    public function send(string $deviceToken, string $title, string $body, array $data = []): string
    {
        $credentials = $this->credentials();
        $projectId = $this->config['project_id'] ?: $credentials['project_id'];

        $response = ProviderHttp::client('fcm', 10)
            ->withToken($this->accessToken($credentials))
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

        if ($response->successful()) {
            return (string) $response->json('name');
        }

        $errorCode = $response->json('error.details.0.errorCode') ?? $response->json('error.status');
        if ($response->status() === 404 || in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            throw new InvalidDeviceToken((string) $errorCode);
        }

        throw ProviderException::fromStatus('fcm', $response->status(), (string) $errorCode);
    }

    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm:access_token', now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $assertion = JWT::encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key'], 'RS256');

            $response = ProviderHttp::send('fcm', 'oauth.token', fn () => ProviderHttp::client('fcm', 10)->asForm()
                ->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]));

            return (string) $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string, project_id: string} */
    private function credentials(): array
    {
        $raw = (string) ($this->config['credentials'] ?? '');
        if ($raw === '') {
            throw ProviderException::permanent('fcm', 'FIREBASE_CREDENTIALS is not configured.');
        }
        $json = str_starts_with(trim($raw), '{') ? $raw : (is_file($raw) ? (string) file_get_contents($raw) : base64_decode($raw, true));
        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded) || empty($decoded['private_key']) || empty($decoded['client_email'])) {
            throw ProviderException::permanent('fcm', 'FIREBASE_CREDENTIALS is not a valid service-account JSON.');
        }

        return $decoded;
    }
}
