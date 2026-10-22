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
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Offline gateway for local development and automated tests. Enabled only when
 * PAYMENTS_SANDBOX_ENABLED=true and never in production. The outcome of a charge is
 * set through `POST /api/v1/sandbox/payments/{reference}/{approve|decline|cancel}`,
 * which mirrors the SPA's mock gateway page.
 */
final class SandboxGateway implements PaymentGateway
{
    public static function setOutcome(string $reference, PaymentStatus $status): void
    {
        Cache::put("sandbox:payment:{$reference}", $status->value, now()->addDay());
    }

    public function code(): string
    {
        return 'sandbox';
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.payments.sandbox_enabled') && ! app()->isProduction();
    }

    public function publicKey(): ?string
    {
        return null;
    }

    public function initialize(Payment $payment, InitializeRequest $request): InitializeResult
    {
        $url = config('platform.frontend_url').'/payment/mock-gateway?'.http_build_query([
            'reference' => $payment->reference,
            'gateway' => 'sandbox',
            'return_url' => $request->returnUrl,
        ]);

        return new InitializeResult($url, 'sbx_'.$payment->reference);
    }

    public function verify(Payment $payment): VerificationResult
    {
        $status = PaymentStatus::tryFrom((string) Cache::get("sandbox:payment:{$payment->reference}")) ?? PaymentStatus::Pending;

        return new VerificationResult(
            status: $status,
            amount: $payment->amount,
            currency: $payment->currency,
            gatewayReference: 'sbx_'.$payment->reference,
            methodLabel: 'Sandbox •••• 4242',
            failureReason: $status === PaymentStatus::Failed ? 'Your bank declined the payment.' : null,
        );
    }

    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
    {
        return new RefundResult('succeeded', 'sbx_rf_'.$refundReference);
    }

    public function parseWebhook(Request $request): WebhookNotification
    {
        throw new InvalidWebhookSignature('sandbox has no webhooks');
    }
}
