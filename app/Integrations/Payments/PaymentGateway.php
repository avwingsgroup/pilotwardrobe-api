<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Integrations\ProviderException;
use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * One payment service provider. Business code never talks to a PSP directly.
 *
 * Security model: webhooks are only a *trigger*. After a verified webhook (or a client
 * poll) the platform always calls `verify()` server-to-server and settles from that
 * answer, checking amount, currency and reference against the payment row.
 */
interface PaymentGateway
{
    public function code(): string;

    public function isConfigured(): bool;

    /** Key safe to give the browser (inline SDKs), never a secret. */
    public function publicKey(): ?string;

    /** @throws ProviderException */
    public function initialize(Payment $payment, InitializeRequest $request): InitializeResult;

    /** @throws ProviderException */
    public function verify(Payment $payment): VerificationResult;

    /** @throws ProviderException */
    public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult;

    /**
     * Authenticates and parses an inbound webhook.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): WebhookNotification;
}
