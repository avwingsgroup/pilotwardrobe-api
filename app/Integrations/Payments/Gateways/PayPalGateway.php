<?php

declare(strict_types=1);

namespace App\Integrations\Payments\Gateways;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\Amounts;
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
use Illuminate\Support\Facades\Cache;

/**
 * PayPal Orders v2. The buyer approves on paypal.com; verification captures an
 * APPROVED order (idempotent via PayPal-Request-Id) and reads the capture status.
 * Webhooks are authenticated with PayPal's verify-webhook-signature API.
 */
final class PayPalGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'paypal';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['client_id']) && ! empty($this->config['client_secret']);
    }

    public function publicKey(): ?string
    {
        return $this->config['client_id'] ?? null;
    }

    public function initialize(Payment $payment, InitializeRequest $request): InitializeResult
    {
        $response = ProviderHttp::send('paypal', 'orders.create', fn () => $this->http()
            ->withHeaders(['PayPal-Request-Id' => 'init-'.$payment->reference])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $payment->reference,
                    'custom_id' => $payment->reference,
                    'description' => mb_substr($request->description, 0, 127),
                    'amount' => ['currency_code' => $payment->currency, 'value' => Amounts::toMajor($payment->amount)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => 'Pilotwardrobe',
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $request->returnUrl.(str_contains($request->returnUrl, '?') ? '&' : '?').'reference='.rawurlencode($payment->reference),
                    'cancel_url' => $request->cancelUrl,
                ]]],
            ]));

        $approve = collect((array) $response->json('links', []))
            ->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true));

        if (! $approve) {
            throw ProviderException::permanent('paypal', 'No approval link returned.');
        }

        return new InitializeResult(authorizationUrl: (string) $approve['href'], gatewayReference: (string) $response->json('id'));
    }

    public function verify(Payment $payment): VerificationResult
    {
        if (! $payment->gateway_reference) {
            return new VerificationResult(PaymentStatus::Pending);
        }

        $orderId = rawurlencode($payment->gateway_reference);
        $order = (array) ProviderHttp::send('paypal', 'orders.get', fn () => $this->http(true)->get("/v2/checkout/orders/{$orderId}"))->json();

        if (($order['status'] ?? null) === 'APPROVED') {
            $captured = $this->http()
                ->withHeaders(['PayPal-Request-Id' => 'capture-'.$payment->reference])
                ->post("/v2/checkout/orders/{$orderId}/capture", (object) []);

            if ($captured->successful()) {
                $order = (array) $captured->json();
            } elseif ($captured->status() === 422) {
                // e.g. INSTRUMENT_DECLINED — buyer must pick another funding source.
                return new VerificationResult(PaymentStatus::Failed, failureReason: 'PayPal could not charge the selected funding source.');
            } else {
                throw ProviderException::fromStatus('paypal', $captured->status(), 'orders.capture');
            }
        }

        $capture = (array) ($order['purchase_units'][0]['payments']['captures'][0] ?? []);
        $status = match (true) {
            ($capture['status'] ?? null) === 'COMPLETED' => PaymentStatus::Successful,
            ($capture['status'] ?? null) === 'PENDING' => PaymentStatus::Processing,
            in_array($capture['status'] ?? null, ['DECLINED', 'FAILED'], true) => PaymentStatus::Failed,
            ($order['status'] ?? null) === 'VOIDED' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        return new VerificationResult(
            status: $status,
            amount: isset($capture['amount']['value']) ? Amounts::toMinor($capture['amount']['value']) : null,
            currency: $capture['amount']['currency_code'] ?? null,
            gatewayReference: (string) ($order['id'] ?? $payment->gateway_reference),
            methodLabel: 'PayPal',
            failureReason: $status === PaymentStatus::Failed ? 'PayPal declined the payment.' : null,
            metadata: ['capture_id' => $capture['id'] ?? null],
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        $captureId = $payment->gateway_metadata['capture_id'] ?? null;
        if (! $captureId) {
            throw ProviderException::permanent('paypal', 'Missing capture id for refund.');
        }

        $response = ProviderHttp::send('paypal', 'captures.refund', fn () => $this->http()
            ->withHeaders(['PayPal-Request-Id' => $refundReference])
            ->post('/v2/payments/captures/'.rawurlencode((string) $captureId).'/refund', [
                'amount' => ['value' => Amounts::toMajor($amount), 'currency_code' => $payment->currency],
                'note_to_payer' => mb_substr($reason, 0, 255),
            ]));

        $status = match ($response->json('status')) {
            'COMPLETED' => 'succeeded',
            'FAILED', 'CANCELLED' => 'failed',
            default => 'processing',
        };

        return new RefundResult($status, (string) $response->json('id'));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        if (empty($this->config['webhook_id'])) {
            throw new InvalidWebhookSignature('paypal webhook id not configured');
        }

        $verification = $this->http()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('paypal-auth-algo'),
            'cert_url' => $request->header('paypal-cert-url'),
            'transmission_id' => $request->header('paypal-transmission-id'),
            'transmission_sig' => $request->header('paypal-transmission-sig'),
            'transmission_time' => $request->header('paypal-transmission-time'),
            'webhook_id' => $this->config['webhook_id'],
            'webhook_event' => json_decode($request->getContent(), true),
        ]);

        if ($verification->json('verification_status') !== 'SUCCESS') {
            throw new InvalidWebhookSignature('paypal');
        }

        $type = (string) $request->json('event_type', '');
        $resource = (array) $request->json('resource', []);
        $orderId = $resource['supplementary_data']['related_ids']['order_id'] ?? ($type === 'CHECKOUT.ORDER.APPROVED' ? ($resource['id'] ?? null) : null);
        $kind = match (true) {
            str_starts_with($type, 'CHECKOUT.ORDER.'), str_starts_with($type, 'PAYMENT.CAPTURE.') && $type !== 'PAYMENT.CAPTURE.REFUNDED' => 'payment',
            $type === 'PAYMENT.CAPTURE.REFUNDED' => 'refund',
            str_starts_with($type, 'PAYMENT.PAYOUTS') => 'transfer',
            default => 'ignored',
        };

        return new WebhookNotification(
            eventId: (string) $request->json('id'),
            eventType: $type,
            kind: $kind,
            reference: $resource['custom_id'] ?? ($resource['purchase_units'][0]['custom_id'] ?? null),
            gatewayReference: $orderId ? (string) $orderId : null,
            payload: $request->json()->all(),
        );
    }

    private function http(bool $read = false): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ProviderException::permanent('paypal', 'PayPal credentials are not configured.');
        }

        return ($read ? ProviderHttp::readClient('paypal') : ProviderHttp::client('paypal'))
            ->baseUrl($this->config['base_url'])
            ->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        return Cache::remember('paypal:access_token', now()->addMinutes(30), function () {
            $response = ProviderHttp::send('paypal', 'oauth.token', fn () => ProviderHttp::client('paypal')
                ->baseUrl($this->config['base_url'])
                ->withBasicAuth($this->config['client_id'], $this->config['client_secret'])
                ->asForm()
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials']));

            return (string) $response->json('access_token');
        });
    }
}
