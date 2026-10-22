<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Integrations\ProviderHttp;
use App\Models\User;
use App\Models\WebauthnCredential;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use lbuchs\WebAuthn\WebAuthn;
use Throwable;

/**
 * Control Tower sign-in (AD1): step 1 Google Workspace ID token restricted to the company
 * domain, step 2 a WebAuthn security-key assertion. A staff member without a registered
 * key enrols one in step 2 (only possible right after a valid Google step).
 */
final class StaffAuthenticator
{
    private const GOOGLE_CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @return array{email: string, sub: string} */
    public function verifyGoogleIdToken(string $idToken): array
    {
        try {
            $keys = Cache::remember('google:jwks', now()->addHours(6), function () {
                return ProviderHttp::send('google', 'jwks', fn () => ProviderHttp::readClient('google', 10)->get(self::GOOGLE_CERTS))->json();
            });
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($keys));
        } catch (Throwable) {
            throw ApiException::validation(['id_token' => 'Google sign-in failed. Please try again.']);
        }

        $domain = (string) config('platform.staff_email_domain');
        $valid = in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['aud'] ?? null) === config('services.google.staff_client_id')
            && ($claims['email_verified'] ?? false) === true
            && ($claims['hd'] ?? null) === $domain
            && Str::endsWith(mb_strtolower((string) ($claims['email'] ?? '')), '@'.$domain);

        if (! $valid) {
            throw ApiException::forbidden('Use your company Google account to access the Control Tower.');
        }

        return ['email' => mb_strtolower((string) $claims['email']), 'sub' => (string) $claims['sub']];
    }

    /** Creates the step-2 challenge with either assertion or registration options. */
    public function beginSecondFactor(User $staff): array
    {
        $webauthn = $this->webauthn();
        $credentials = WebauthnCredential::query()->where('user_id', $staff->id)->get();
        $challengeId = 'wa_'.Str::lower(Str::random(24));

        if ($credentials->isEmpty()) {
            $options = $webauthn->getCreateArgs(
                hash('sha256', $staff->id, true), $staff->email, $staff->displayName(), 60, false, 'required', true,
            );
            $mode = 'register';
        } else {
            $ids = $credentials->map(fn ($c) => $this->b64urlDecode($c->credential_id))->all();
            $options = $webauthn->getGetArgs($ids, 60, true, true, true, true, false, 'required');
            $mode = 'assert';
        }

        Cache::put("staff_challenge:{$challengeId}", [
            'user_id' => $staff->id,
            'mode' => $mode,
            'challenge' => base64_encode($webauthn->getChallenge()->getBinaryString()),
        ], now()->addMinutes(5));

        return ['challenge_id' => $challengeId, 'mode' => $mode, 'public_key' => json_decode((string) json_encode($options), true)['publicKey'] ?? []];
    }

    /** @param array<string, mixed> $credential the PublicKeyCredential JSON (base64url fields) */
    public function completeSecondFactor(string $challengeId, array $credential): User
    {
        $state = Cache::pull("staff_challenge:{$challengeId}");
        if (! is_array($state)) {
            throw ApiException::sessionExpired('Your sign-in attempt expired. Start again.');
        }

        $staff = User::query()->findOrFail($state['user_id']);
        $challenge = base64_decode($state['challenge']);
        $response = (array) ($credential['response'] ?? []);
        $webauthn = $this->webauthn();

        try {
            if ($state['mode'] === 'register') {
                $data = $webauthn->processCreate(
                    $this->b64urlDecode((string) ($response['clientDataJSON'] ?? '')),
                    $this->b64urlDecode((string) ($response['attestationObject'] ?? '')),
                    $challenge, true, true, false,
                );
                WebauthnCredential::query()->create([
                    'user_id' => $staff->id,
                    'name' => 'Security key',
                    'credential_id' => $this->b64urlEncode($data->credentialId),
                    'public_key' => $data->credentialPublicKey,
                    'sign_count' => (int) ($data->signatureCounter ?? 0),
                    'last_used_at' => now(),
                ]);
            } else {
                $id = (string) ($credential['rawId'] ?? $credential['id'] ?? '');
                $stored = WebauthnCredential::query()->where('user_id', $staff->id)->get()
                    ->first(fn ($c) => hash_equals($c->credential_id, $this->b64urlEncode($this->b64urlDecode($id))));
                if ($stored === null) {
                    throw ApiException::forbidden('This security key is not registered to your account.');
                }

                $webauthn->processGet(
                    $this->b64urlDecode((string) ($response['clientDataJSON'] ?? '')),
                    $this->b64urlDecode((string) ($response['authenticatorData'] ?? '')),
                    $this->b64urlDecode((string) ($response['signature'] ?? '')),
                    $stored->public_key,
                    $challenge,
                    $stored->sign_count ?: null,
                    true,
                );
                $stored->forceFill(['sign_count' => (int) ($webauthn->getSignatureCounter() ?? $stored->sign_count), 'last_used_at' => now()])->save();
            }
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw ApiException::validation(['credential' => 'Security key verification failed. Try again.']);
        }

        return $staff;
    }

    private function webauthn(): WebAuthn
    {
        return new WebAuthn((string) config('services.webauthn.rp_name'), (string) config('services.webauthn.rp_id'), null, true);
    }

    private function b64urlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4));
    }

    private function b64urlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
