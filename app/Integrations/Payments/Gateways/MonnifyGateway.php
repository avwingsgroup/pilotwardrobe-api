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
 * Monnify (NGN) — init-transaction checkout, status query by our paymentReference,
 * refunds, and webhooks signed with HMAC-SHA512 of the body using the secret key
 * (`monnify-signature`). Uses a cached OAuth bearer token.
 */
final class MonnifyGateway implements PaymentGateway
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'monnify';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['secret_key']) && ! empty($this->config['contract_code']);
    }

    public function publicKey(): ?string
    {
        return null;
    }

    public function initialize(Payment $payment, InitializeRequest $request): InitializeResult
    {
        $response = ProviderHttp::send('monnify', 'transactions.init', fn () => $this->http()->post('/api/v1/merchant/transactions/init-transaction', [
            'amount' => Amounts::toMajor($payment->amount),
            'customerName' => $request->customerName,
            'customerEmail' => $request->customerEmail,
            'paymentReference' => $payment->reference,
            'paymentDescription' => mb_substr($request->description, 0, 100),
            'currencyCode' => $payment->currency,
            'contractCode' => $this->config['contract_code'],
            'redirectUrl' => $request->returnUrl,
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER', 'USSD'],
            'metaData' => ['payment_id' => $payment->id],
        ]));

        if (! $response->json('requestSuccessful')) {
            throw ProviderException::permanent('monnify', (string) $response->json('responseMessage', 'init failed'));
        }

        return new InitializeResult(
            authorizationUrl: (string) $response->json('responseBody.checkoutUrl'),
            gatewayReference: (string) $response->json('responseBody.transactionReference'),
        );
    }

    public function verify(Payment $payment): VerificationResult
    {
        $response = ProviderHttp::send('monnify', 'transactions.query', fn () => $this->http(true)
            ->get('/api/v2/merchant/transactions/query', ['paymentReference' => $payment->reference]));

        $body = (array) $response->json('responseBody', []);
        $status = match ($body['paymentStatus'] ?? null) {
            'PAID', 'OVERPAID' => PaymentStatus::Successful,
            'PARTIALLY_PAID' => PaymentStatus::Processing, // amount check below will refuse to settle
            'FAILED', 'REVERSED' => PaymentStatus::Failed,
            'EXPIRED' => PaymentStatus::Expired,
            'CANCELLED' => PaymentStatus::Cancelled,
            default => PaymentStatus::Pending,
        };

        $card = (array) ($body['cardDetails'] ?? []);
        $label = isset($card['last4']) ? trim(($card['cardType'] ?? 'Card').' •••• '.$card['last4'])
            : (isset($body['paymentMethod']) ? ucwords(strtolower(str_replace('_', ' ', (string) $body['paymentMethod']))) : null);

        return new VerificationResult(
            status: $status,
            amount: isset($body['amountPaid']) ? Amounts::toMinor($body['amountPaid']) : null,
            currency: isset($body['currencyCode']) ? strtoupper((string) $body['currencyCode']) : $payment->currency,
            gatewayReference: isset($body['transactionReference']) ? (string) $body['transactionReference'] : null,
            methodLabel: $label,
            failureReason: $status === PaymentStatus::Failed ? 'The payment was not completed. Please try again or use another method.' : null,
            metadata: ['transaction_reference' => $body['transactionReference'] ?? null],
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        $response = ProviderHttp::send('monnify', 'refunds.initiate', fn () => $this->http()->post('/api/v1/refunds/initiate-refund', [
            'transactionReference' => $payment->gateway_reference,
            'refundReference' => $refundReference,
            'refundAmount' => Amounts::toMajor($amount),
            'refundReason' => mb_substr($reason, 0, 64),
            'customerNote' => 'Pilotwardrobe refund',
        ]));

        $status = match ($response->json('responseBody.refundStatus')) {
            'COMPLETED' => 'succeeded',
            'FAILED' => 'failed',
            default => 'processing',
        };

        return new RefundResult($status, $refundReference);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        $signature = (string) $request->header('monnify-signature', '');
        $expected = hash_hmac('sha512', $request->getContent(), (string) $this->config['secret_key']);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignature('monnify');
        }

        $type = (string) $request->json('eventType', '');
        $data = (array) $request->json('eventData', []);
        $kind = match ($type) {
            'SUCCESSFUL_TRANSACTION', 'FAILED_TRANSACTION', 'REJECTED_PAYMENT' => 'payment',
            'SUCCESSFUL_REFUND', 'FAILED_REFUND' => 'refund',
            'SUCCESSFUL_DISBURSEMENT', 'FAILED_DISBURSEMENT', 'REVERSED_DISBURSEMENT' => 'transfer',
            default => 'ignored',
        };

        return new WebhookNotification(
            eventId: $type.':'.($data['transactionReference'] ?? $data['refundReference'] ?? sha1($request->getContent())),
            eventType: $type,
            kind: $kind,
            reference: isset($data['paymentReference']) ? (string) $data['paymentReference'] : null,
            gatewayReference: isset($data['transactionReference']) ? (string) $data['transactionReference'] : null,
            payload: $request->json()->all(),
        );
    }

    private function http(bool $read = false): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw ProviderException::permanent('monnify', 'Monnify credentials are not configured.');
        }

        return ($read ? ProviderHttp::readClient('monnify') : ProviderHttp::client('monnify'))
            ->baseUrl($this->config['base_url'])
            ->withToken($this->accessToken());
    }

    private function accessToken(): string
    {
        return Cache::remember('monnify:access_token', now()->addMinutes(40), function () {
            $response = ProviderHttp::send('monnify', 'auth.login', fn () => ProviderHttp::client('monnify')
                ->baseUrl($this->config['base_url'])
                ->withBasicAuth($this->config['api_key'], $this->config['secret_key'])
                ->post('/api/v1/auth/login'));

            return (string) $response->json('responseBody.accessToken');
        });
    }
}
