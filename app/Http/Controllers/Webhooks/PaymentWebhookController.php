<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\InvalidWebhookSignature;
use App\Jobs\Webhooks\ProcessPaymentWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * `POST /webhooks/{gateway}` — gateway → Laravel only, never called by the SPA.
 *
 *  1. Authenticate (signature/hash/verification API). Forged → 400, nothing stored.
 *  2. Store once per (provider, event id). Retries of a known event → 200, no reprocessing.
 *  3. Process asynchronously; processing re-verifies with the PSP before changing anything.
 */
final class PaymentWebhookController
{
    private const GATEWAYS = ['paystack', 'flutterwave', 'monnify', 'stripe', 'paypal'];

    public function __invoke(Request $request, string $gateway, GatewayRegistry $gateways): JsonResponse
    {
        if (! in_array($gateway, self::GATEWAYS, true)) {
            throw ApiException::notFound();
        }

        try {
            $notification = $gateways->get($gateway)->parseWebhook($request);
        } catch (InvalidWebhookSignature) {
            Log::warning('webhooks.invalid_signature', ['provider' => $gateway, 'ip' => $request->ip()]);

            throw new ApiException(ErrorCode::UNKNOWN, 'Invalid signature.', status: 400);
        }

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => WebhookEvent::newPrefixedId(),
            'provider' => $gateway,
            'event_id' => mb_substr($notification->eventId, 0, 191),
            'event_type' => mb_substr($notification->eventType, 0, 120),
            'reference' => $notification->reference,
            'signature_valid' => true,
            'payload' => json_encode($notification->payload),
            'headers' => json_encode(array_intersect_key($request->headers->all(), array_flip(['user-agent', 'content-type']))),
            'status' => $notification->kind === 'ignored' ? 'ignored' : 'received',
            'request_id' => Context::get('request_id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        if ($notification->kind !== 'ignored') {
            $event = WebhookEvent::query()->where('provider', $gateway)->where('event_id', mb_substr($notification->eventId, 0, 191))->first();
            ProcessPaymentWebhook::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
