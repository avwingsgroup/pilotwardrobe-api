<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\Gateways\SandboxGateway;
use App\Models\Payment;
use App\Services\Payments\PaymentService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** `GET /payment-methods?currency=&country=` */
    public function methods(Request $request, GatewayRegistry $gateways): JsonResponse
    {
        $currency = $request->query('currency') ? strtoupper((string) $request->query('currency')) : null;
        $country = $request->query('country') ? strtoupper((string) $request->query('country')) : $request->user()?->country_code;

        return ApiResponse::ok($gateways->methodsFor($currency, $country));
    }

    /** `POST /payments/initialize` — idempotent per payable + gateway while pending. */
    public function initialize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required_without:promotion_id', 'nullable', 'string', 'max:40'],
            'promotion_id' => ['nullable', 'string', 'max:40'],
            'gateway' => ['required', 'string', 'max:20'],
            'return_url' => ['required', 'string', 'max:500'],
        ], ['gateway.required' => 'Choose a payment method.']);

        $payable = $this->payments->findPayable($data['order_id'] ?? null, $data['promotion_id'] ?? null);
        $payment = $this->payments->initialize($request->user(), $payable, $data['gateway'], $data['return_url']);
        $gateway = app(GatewayRegistry::class)->get($payment->gateway);

        return ApiResponse::ok(array_filter([
            'payment_id' => $payment->id,
            'gateway' => $payment->gateway,
            'status' => $payment->status->value,
            'reference' => $payment->reference,
            'authorization_url' => $payment->authorization_url,
            'amount' => $payment->money()->toArray(),
            'public_key' => in_array($payment->gateway, ['paystack', 'flutterwave'], true) ? $gateway->publicKey() : null,
            'expires_at' => $payment->expires_at?->toIso8601ZuluString(),
        ], fn ($v) => $v !== null));
    }

    /** `GET /payments/{reference}` — server-verified status (verifies with the PSP when still open). */
    public function show(Request $request, string $reference): JsonResponse
    {
        $payment = Payment::query()->where('reference', $reference)->where('user_id', $request->user()->id)->first()
            ?? throw ApiException::notFound('Payment not found.');

        // Expired attempts are re-checked too: a late PSP success must be detected (and refunded).
        if ($payment->status->isOpen() || $payment->status === PaymentStatus::Expired) {
            $payment = $this->payments->verify($payment);
        }

        return ApiResponse::ok($this->payments->present($payment));
    }

    /** `POST /sandbox/payments/{reference}/{outcome}` — local/test only (mirrors the SPA mock gateway). */
    public function sandbox(Request $request, string $reference, string $outcome): JsonResponse
    {
        if (! app(GatewayRegistry::class)->get('sandbox')->isConfigured()) {
            throw ApiException::notFound();
        }
        $payment = Payment::query()->where('reference', $reference)->where('gateway', 'sandbox')->where('user_id', $request->user()->id)->first()
            ?? throw ApiException::notFound('Payment not found.');

        SandboxGateway::setOutcome($reference, match ($outcome) {
            'approve' => PaymentStatus::Successful,
            'decline' => PaymentStatus::Failed,
            default => PaymentStatus::Cancelled,
        });

        return ApiResponse::ok($this->payments->present($this->payments->verify($payment)));
    }
}
