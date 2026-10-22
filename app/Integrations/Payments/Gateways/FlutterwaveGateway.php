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

/**
 * Flutterwave v3 — Standard hosted payments (`POST /payments`), verification by our
 * tx_ref, refunds, and webhooks authenticated by the `verif-hash` secret.
 * Flutterwave takes decimal major-unit amounts.
 */
final class FlutterwaveGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'flutterwave';
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
        $response = ProviderHttp::send('flutterwave', 'payments.create', fn () => $this->http()->post('/payments', [
            'tx_ref' => $payment->reference,
            'amount' => Amounts::toMajor($payment->amount),
            'currency' => $payment->currency,
            'redirect_url' => $request->returnUrl,
            'customer' => ['email' => $request->customerEmail, 'name' => $request->customerName],
            'customizations' => ['title' => 'Pilotwardrobe', 'description' => mb_substr($request->description, 0, 100)],
            'meta' => ['payment_id' => $payment->id] + $request->metadata,
        ]));

        if ($response->json('status') !== 'success') {
            throw ProviderException::permanent('flutterwave', (string) $response->json('message', 'initialize failed'));
        }

        return new InitializeResult(authorizationUrl: (string) $response->json('data.link'));
    }

    public function verify(Payment $payment): VerificationResult
    {
        $response = $this->http(true)->get('/transactions/verify_by_reference', ['tx_ref' => $payment->reference]);

        // Not found yet = the customer has not completed the hosted page.
        if ($response->status() === 404 || ($response->status() === 400 && $response->json('status') === 'error')) {
            return new VerificationResult(PaymentStatus::Pending);
        }
        if ($response->failed()) {
            throw ProviderException::fromStatus('flutterwave', $response->status(), 'transactions.verify');
        }

        $data = (array) $response->json('data', []);
        $status = match ($data['status'] ?? null) {
            'successful' => PaymentStatus::Successful,
            'failed' => PaymentStatus::Failed,
            'cancelled' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        $card = (array) ($data['card'] ?? []);
        $label = isset($card['last_4digits']) ? trim(($card['type'] ?? 'Card').' •••• '.$card['last_4digits']) : ($data['payment_type'] ?? null);

        return new VerificationResult(
            status: $status,
            amount: isset($data['amount']) ? Amounts::toMinor($data['amount']) : null,
            currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : null,
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            methodLabel: $label ? (string) $label : null,
            failureReason: $status === PaymentStatus::Failed ? 'The payment was not completed. Please try again or use another method.' : null,
            feeAmount: isset($data['app_fee']) ? Amounts::toMinor($data['app_fee']) : null,
            metadata: ['transaction_id' => $data['id'] ?? null, 'payment_type' => $data['payment_type'] ?? null],
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        $transactionId = $payment->gateway_metadata['transaction_id'] ?? $payment->gateway_reference;
        if (! $transactionId) {
            throw ProviderException::permanent('flutterwave', 'Missing transaction id for refund.');
        }

        $response = ProviderHttp::send('flutterwave', 'transactions.refund', fn () => $this->http()
            ->post('/transactions/'.rawurlencode((string) $transactionId).'/refund', ['amount' => Amounts::toMajor($amount)]));

        $status = match (strtolower((string) $response->json('data.status'))) {
            'completed', 'completed-mpgs', 'successful' => 'succeeded',
            'failed' => 'failed',
            default => 'processing',
        };

        return new RefundResult($status, (string) $response->json('data.id'));
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $hash = (string) $request->header('verif-hash', '');
        $secret = (string) ($this->config['secret_hash'] ?? '');
        if ($secret === '' || $hash === '' || ! hash_equals($secret, $hash)) {
            throw new InvalidWebhookSignature('flutterwave');
        }

        $event = (string) ($request->json('event') ?? $request->json('event.type') ?? '');
        $data = (array) $request->json('data', []);
        $kind = match (true) {
            str_starts_with($event, 'charge.') => 'payment',
            str_starts_with($event, 'transfer.') => 'transfer',
            str_starts_with($event, 'refund.') => 'refund',
            default => 'ignored',
        };

        return new WebhookNotification(
            eventId: $event.':'.($data['id'] ?? sha1($request->getContent())),
            eventType: $event,
            kind: $kind,
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : (isset($data['reference']) ? (string) $data['reference'] : null),
            gatewayReference: isset($data['id']) ? (string) $data['id'] : null,
            payload: $request->json()->all(),
        );
    }

    private function http(bool $read = false): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ProviderException::permanent('flutterwave', 'FLUTTERWAVE_SECRET_KEY is not configured.');
        }

        return ($read ? ProviderHttp::readClient('flutterwave') : ProviderHttp::client('flutterwave'))
            ->baseUrl($this->config['base_url'])
            ->withToken($this->config['secret_key']);
    }
}
