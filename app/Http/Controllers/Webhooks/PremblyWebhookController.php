<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\KycVerification;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Prembly verification callbacks (e.g. the hosted liveness widget). Authenticated with an
 * HMAC-SHA512 of the raw body using PREMBLY_WEBHOOK_SECRET; the header name is
 * configurable because it depends on the Prembly product in use (see docs/integrations.md).
 * The result is attached to the KYC case as an advisory check for the reviewer.
 */
final class PremblyWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.prembly.webhook_secret');
        $header = (string) config('kyc.prembly.signature_header', 'x-prembly-signature');
        $expected = hash_hmac('sha512', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header($header, ''))) {
            throw new ApiException(ErrorCode::UNKNOWN, 'Invalid signature.', status: 400);
        }

        $reference = (string) ($request->json('reference') ?? $request->json('data.reference') ?? $request->json('verification.reference') ?? '');
        $eventId = (string) ($request->json('id') ?? hash('sha256', $request->getContent()));

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => WebhookEvent::newPrefixedId(),
            'provider' => 'prembly',
            'event_id' => mb_substr($eventId, 0, 191),
            'event_type' => mb_substr((string) ($request->json('event') ?? 'verification'), 0, 120),
            'reference' => $reference ?: null,
            'signature_valid' => true,
            // Identity payloads are not retained beyond the summary below (data minimisation).
            'payload' => json_encode(['status' => $request->json('status') ?? $request->json('verification.status')]),
            'status' => 'processed',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0 || $reference === '') {
            return response()->json(['received' => true]);
        }

        $kyc = KycVerification::query()->where('code', $reference)->orWhere('provider_reference', $reference)->first();
        if ($kyc !== null) {
            $status = strtoupper((string) ($request->json('status') ?? $request->json('verification.status') ?? ''));
            $checks = array_values(array_filter($kyc->automated_checks ?? [], fn ($c) => ($c['label'] ?? '') !== 'Liveness'));
            $checks[] = ['label' => 'Liveness', 'result' => $status ?: 'Unknown', 'passed' => in_array($status, ['VERIFIED', 'SUCCESS', 'PASSED', 'TRUE'], true)];
            $kyc->forceFill(['automated_checks' => $checks])->save();
        }

        return response()->json(['received' => true]);
    }
}
