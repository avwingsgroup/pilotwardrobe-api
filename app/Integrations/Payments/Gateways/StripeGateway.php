<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\InitializeRequest;
use App\Integrations\Payments\InitializeResult;
use App\Integrations\Payments\InvalidWebhookSignature;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\RefundResult;
use App\Integrations\Payments\VerificationResult;
use App\Integrations\Payments\WebhookNotification;
use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;
use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;

/**
 * Stripe Checkout Sessions (hosted on checkout.stripe.com). Card, Apple Pay and
 * Google Pay are all presented by Stripe's hosted page, which is how Google Pay is
 * supported without handling wallet tokens ourselves.
 *
 * Webhooks: `Stripe-Signature` (t=…, v1=…) HMAC-SHA256 over "{t}.{body}" with a
 * 5-minute tolerance against replays.
 */
final class StripeGateway implements PaymentGateway
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'stripe';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['secret_key']);
    }

    public function publicKey(): ?string
    {
        return $this->config['public_key'] ?? null;
    }

    public function initialize(Payment $payment, InitializeRequest $request): InitializeResult
    {
        $separator = str_contains($request->returnUrl, '?') ? '&' : '?';

        $response = ProviderHttp::send('stripe', 'checkout.sessions.create', fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => 'init-'.$payment->reference])
            ->post('/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $payment->reference,
                'customer_email' => $request->customerEmail,
                'success_url' => $request->returnUrl.$separator.'reference='.rawurlencode($payment->reference),
                'cancel_url' => $request->cancelUrl,
                'expires_at' => now()->addMinutes(max(30, (int) config('platform.payments.pending_expiry_minutes')))->timestamp,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'unit_amount' => $payment->amount,
                        'product_data' => ['name' => mb_substr($request->description, 0, 120)],
                    ],
                ]],
                'metadata' => ['reference' => $payment->reference, 'payment_id' => $payment->id],
                'payment_intent_data' => ['metadata' => ['reference' => $payment->reference]],
            ]));

        return new InitializeResult(
            authorizationUrl: (string) $response->json('url'),
            gatewayReference: (string) $response->json('id'),
        );
    }

    public function verify(Payment $payment): VerificationResult
    {
        if (! $payment->gateway_reference) {
            return new VerificationResult(PaymentStatus::Pending);
        }

        $response = ProviderHttp::send('stripe', 'checkout.sessions.retrieve', fn () => $this->http(true)
            ->get('/checkout/sessions/'.rawurlencode($payment->gateway_reference), ['expand' => ['payment_intent.latest_charge']]));

        $session = (array) $response->json();
        $intent = (array) ($session['payment_intent'] ?? []);
        $charge = (array) ($intent['latest_charge'] ?? []);

        $status = match (true) {
            ($session['payment_status'] ?? null) === 'paid' => PaymentStatus::Successful,
            ($session['status'] ?? null) === 'expired' => PaymentStatus::Expired,
            ($intent['status'] ?? null) === 'processing' => PaymentStatus::Processing,
            ($intent['status'] ?? null) === 'canceled' => PaymentStatus::Cancelled,
            ($intent['last_payment_error'] ?? null) !== null && ($session['status'] ?? null) === 'complete' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };

        $card = (array) ($charge['payment_method_details']['card'] ?? []);
        $wallet = $card['wallet']['type'] ?? null;
        $label = isset($card['last4'])
            ? trim(($wallet ? ucwords(str_replace('_', ' ', (string) $wallet)).' · ' : '').ucfirst((string) ($card['brand'] ?? 'card')).' •••• '.$card['last4'])
            : null;

        return new VerificationResult(
            status: $status,
            amount: isset($session['amount_total']) ? (int) $session['amount_total'] : null,
            currency: isset($session['currency']) ? strtoupper((string) $session['currency']) : null,
            gatewayReference: (string) ($session['id'] ?? $payment->gateway_reference),
            methodLabel: $label,
            failureReason: $status === PaymentStatus::Failed ? (string) ($intent['last_payment_error']['message'] ?? 'Your card was declined.') : null,
            metadata: ['payment_intent' => $intent['id'] ?? null],
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        $intent = $payment->gateway_metadata['payment_intent'] ?? null;
        if (! $intent) {
            throw ProviderException::permanent('stripe', 'Missing payment_intent for refund.');
        }

        $response = ProviderHttp::send('stripe', 'refunds.create', fn () => $this->http()
            ->withHeaders(['Idempotency-Key' => $refundReference])
            ->post('/refunds', [
                'payment_intent' => $intent,
                'amount' => $amount,
                'metadata' => ['reference' => $refundReference],
            ]));

        $status = match ($response->json('status')) {
            'succeeded' => 'succeeded',
            'failed', 'canceled' => 'failed',
            default => 'processing',
        };

        return new RefundResult($status, (string) $response->json('id'));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $header = (string) $request->header('Stripe-Signature', '');
        $secret = (string) ($this->config['webhook_secret'] ?? '');
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k][] = $v;
        }
        $timestamp = (int) ($parts['t'][0] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        $valid = $secret !== '' && $timestamp > 0
            && abs(time() - $timestamp) <= (int) ($this->config['webhook_tolerance'] ?? 300)
            && collect($parts['v1'] ?? [])->contains(fn ($sig) => hash_equals($expected, (string) $sig));

        if (! $valid) {
            throw new InvalidWebhookSignature('stripe');
        }

        $type = (string) $request->json('type', '');
        $object = (array) $request->json('data.object', []);
        $kind = match (true) {
            str_starts_with($type, 'checkout.session.'), str_starts_with($type, 'payment_intent.') => 'payment',
            str_starts_with($type, 'charge.refund'), str_starts_with($type, 'refund.') => 'refund',
            default => 'ignored',
        };

        return new WebhookNotification(
            eventId: (string) $request->json('id'),
            eventType: $type,
            kind: $kind,
            reference: $object['client_reference_id'] ?? ($object['metadata']['reference'] ?? null),
            gatewayReference: isset($object['id']) ? (string) $object['id'] : null,
            payload: $request->json()->all(),
        );
    }

    private function http(bool $read = false): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ProviderException::permanent('stripe', 'STRIPE_SECRET is not configured.');
        }

        return ($read ? ProviderHttp::readClient('stripe') : ProviderHttp::client('stripe'))
            ->baseUrl(self::API)
            ->withToken($this->config['secret_key'])
            ->asForm();
    }
}
