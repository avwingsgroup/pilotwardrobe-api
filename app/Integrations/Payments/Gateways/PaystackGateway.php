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
 * Paystack — hosted checkout (transaction/initialize), verification
 * (transaction/verify/{reference}), refunds and HMAC-SHA512 webhooks
 * (`x-paystack-signature`). Amounts are already in minor units (kobo/pesewas/cents).
 */
final class PaystackGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'paystack';
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
        $response = ProviderHttp::send('paystack', 'transaction.initialize', fn () => $this->http()->post('/transaction/initialize', [
            'email' => $request->customerEmail,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $request->returnUrl,
            'metadata' => ['payment_id' => $payment->id, 'cancel_action' => $request->cancelUrl] + $request->metadata,
        ]));

        if (! $response->json('status')) {
            throw ProviderException::permanent('paystack', (string) $response->json('message', 'initialize failed'));
        }

        return new InitializeResult(
            authorizationUrl: (string) $response->json('data.authorization_url'),
            gatewayReference: (string) $response->json('data.reference'),
            metadata: ['access_code' => $response->json('data.access_code')],
        );
    }

    public function verify(Payment $payment): VerificationResult
    {
        $response = ProviderHttp::send('paystack', 'transaction.verify', fn () => $this->http(true)
            ->get('/transaction/verify/'.rawurlencode($payment->reference)));

        $data = (array) $response->json('data', []);
        $status = match ($data['status'] ?? null) {
            'success' => PaymentStatus::Successful,
            'failed' => PaymentStatus::Failed,
            'abandoned' => PaymentStatus::Pending, // customer has not finished; may still pay
            'reversed' => PaymentStatus::Refunded,
            'ongoing', 'processing', 'queued' => PaymentStatus::Processing,
            default => PaymentStatus::Pending,
        };

        $auth = (array) ($data['authorization'] ?? []);
        $label = isset($auth['last4']) ? trim(ucfirst((string) ($auth['card_type'] ?? 'Card')).' •••• '.$auth['last4']) : ($data['channel'] ?? null);

        return new VerificationResult(
            status: $status,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            methodLabel: $label ? (string) $label : null,
            failureReason: $status === PaymentStatus::Failed ? $this->friendlyFailure((string) ($data['gateway_response'] ?? '')) : null,
            feeAmount: isset($data['fees']) ? (int) $data['fees'] : null,
            metadata: ['channel' => $data['channel'] ?? null, 'transaction_id' => $data['id'] ?? null],
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        $response = ProviderHttp::send('paystack', 'refund.create', fn () => $this->http()->post('/refund', [
            'transaction' => $payment->reference,
            'amount' => $amount,
            'merchant_note' => mb_substr($reason, 0, 200),
        ]));

        $status = match ($response->json('data.status')) {
            'processed' => 'succeeded',
            'failed' => 'failed',
            default => 'processing',
        };

        return new RefundResult($status, (string) $response->json('data.id'));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $signature = (string) $request->header('x-paystack-signature', '');
        $expected = hash_hmac('sha512', $request->getContent(), (string) $this->config['secret_key']);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature('paystack');
        }

        $event = (string) $request->json('event', '');
        $data = (array) $request->json('data', []);
        $kind = match (true) {
            str_starts_with($event, 'charge.') => 'payment',
            str_starts_with($event, 'refund.') => 'refund',
            str_starts_with($event, 'transfer.') => 'transfer',
            default => 'ignored',
        };

        return new WebhookNotification(
            eventId: $event.':'.($data['id'] ?? $data['reference'] ?? sha1($request->getContent())),
            eventType: $event,
            kind: $kind,
            reference: isset($data['reference']) ? (string) $data['reference'] : (isset($data['transaction_reference']) ? (string) $data['transaction_reference'] : null),
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            payload: $request->json()->all(),
        );
    }

    private function http(bool $read = false): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ProviderException::permanent('paystack', 'PAYSTACK_SECRET_KEY is not configured.');
        }

        return ($read ? ProviderHttp::readClient('paystack') : ProviderHttp::client('paystack'))
            ->baseUrl($this->config['base_url'])
            ->withToken($this->config['secret_key']);
    }

    private function friendlyFailure(string $gatewayResponse): string
    {
        $lower = strtolower($gatewayResponse);

        return match (true) {
            str_contains($lower, 'insufficient') => 'Your card has insufficient funds.',
            str_contains($lower, 'declined') => 'Your bank declined the payment.',
            str_contains($lower, 'expired') => 'Your card has expired.',
            default => 'The payment was not completed. Please try again or use another method.',
        };
    }
}
