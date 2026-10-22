<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\NotificationDelivery;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * WhatsApp Cloud API webhooks: the GET verification handshake, and signed POSTs carrying
 * message delivery statuses (sent / delivered / read / failed) for our template messages.
 */
final class WhatsAppWebhookController
{
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
        if ($token === '' || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, $signature)) {
            throw new ApiException(ErrorCode::UNKNOWN, 'Invalid signature.', status: 400);
        }

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'id' => WebhookEvent::newPrefixedId(),
            'provider' => 'whatsapp',
            'event_id' => hash('sha256', $request->getContent()),
            'event_type' => 'statuses',
            'signature_valid' => true,
            'payload' => $request->getContent(),
            'status' => 'processed',
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        foreach ((array) $request->json('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $mapped = match ($status['status'] ?? null) {
                        'delivered', 'read' => 'delivered',
                        'failed' => 'failed',
                        default => null,
                    };
                    if ($mapped && ! empty($status['id'])) {
                        NotificationDelivery::query()->where('channel', 'whatsapp')->where('provider_message_id', (string) $status['id'])
                            ->update(array_filter([
                                'status' => $mapped,
                                'error' => $mapped === 'failed' ? mb_substr((string) ($status['errors'][0]['title'] ?? 'Delivery failed'), 0, 500) : null,
                            ], fn ($v) => $v !== null));
                    }
                }
            }
        }

        return response()->json(['received' => true]);
    }
}
