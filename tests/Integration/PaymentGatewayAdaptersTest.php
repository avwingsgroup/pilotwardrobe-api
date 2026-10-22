<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\Gateways\FlutterwaveGateway;
use App\Integrations\Payments\Gateways\MonnifyGateway;
use App\Integrations\Payments\Gateways\PayPalGateway;
use App\Integrations\Payments\Gateways\PaystackGateway;
use App\Integrations\Payments\Gateways\StripeGateway;
use App\Integrations\Payments\InitializeRequest;
use App\Integrations\Payments\InvalidWebhookSignature;
use App\Integrations\ProviderException;
use App\Models\Payment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** PSP adapters against faked HTTP: request mapping, response normalisation, webhook authentication. */
final class PaymentGatewayAdaptersTest extends TestCase
{
    private function payment(array $overrides = []): Payment
    {
        $payment = new Payment(array_merge([
            'reference' => 'PW-2026-ABC123', 'payable_type' => 'order', 'payable_id' => 'ord_x', 'user_id' => 'usr_x',
            'gateway' => 'paystack', 'amount' => 19061, 'currency' => 'USD', 'status' => 'pending',
        ], $overrides));
        $payment->id = 'pay_test';

        return $payment;
    }

    private function init(): InitializeRequest
    {
        return new InitializeRequest('buyer@example.com', 'Ada Obi', 'https://pilotwardrobe.test/payment/callback', 'https://pilotwardrobe.test/payment/callback?cancelled=1', 'Order #PW-1');
    }

    private function webhook(string $body, array $headers): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return Request::create('/api/v1/webhooks/x', 'POST', [], [], [], $server, $body);
    }

    public function test_paystack_initialize_verify_and_signature(): void
    {
        $gateway = new PaystackGateway(['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'base_url' => 'https://api.paystack.co']);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'PW-2026-ABC123', 'access_code' => 'abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Declined by bank', 'amount' => 19061, 'currency' => 'USD', 'id' => 1]]),
        ]);

        $result = $gateway->initialize($this->payment(), $this->init());
        $this->assertSame('https://checkout.paystack.com/abc', $result->authorizationUrl);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.paystack.co/transaction/initialize'
            && $r['amount'] === 19061 && $r['reference'] === 'PW-2026-ABC123' && $r->hasHeader('Authorization', 'Bearer sk_test'));

        $verify = $gateway->verify($this->payment());
        $this->assertSame(PaymentStatus::Failed, $verify->status);
        $this->assertSame('Your bank declined the payment.', $verify->failureReason);

        $body = '{"event":"charge.success","data":{"id":1,"reference":"PW-2026-ABC123"}}';
        $notification = $gateway->parseWebhook($this->webhook($body, ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test')]));
        $this->assertSame('payment', $notification->kind);
        $this->assertSame('PW-2026-ABC123', $notification->reference);

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->parseWebhook($this->webhook($body, ['x-paystack-signature' => hash_hmac('sha512', $body.' ', 'sk_test')]));
    }

    public function test_flutterwave_uses_major_units_and_secret_hash(): void
    {
        $gateway = new FlutterwaveGateway(['secret_key' => 'FLWSECK', 'secret_hash' => 'my-hash', 'base_url' => 'https://api.flutterwave.com/v3']);
        Http::fake([
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/x']]),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 190.61, 'currency' => 'USD', 'id' => 77, 'card' => ['type' => 'VISA', 'last_4digits' => '4417']]]),
        ]);

        $gateway->initialize($this->payment(['gateway' => 'flutterwave']), $this->init());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/payments') && $r['amount'] === '190.61' && $r['tx_ref'] === 'PW-2026-ABC123');

        $verify = $gateway->verify($this->payment(['gateway' => 'flutterwave']));
        $this->assertSame(PaymentStatus::Successful, $verify->status);
        $this->assertSame(19061, $verify->amount);
        $this->assertSame('VISA •••• 4417', $verify->methodLabel);

        $gateway->parseWebhook($this->webhook('{"event":"charge.completed","data":{"id":77,"tx_ref":"PW-2026-ABC123"}}', ['verif-hash' => 'my-hash']));
        $this->expectException(InvalidWebhookSignature::class);
        $gateway->parseWebhook($this->webhook('{}', ['verif-hash' => 'wrong']));
    }

    public function test_stripe_signature_tolerance_and_session_verification(): void
    {
        $gateway = new StripeGateway(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test', 'webhook_tolerance' => 300]);
        $body = '{"id":"evt_1","type":"checkout.session.completed","data":{"object":{"id":"cs_1","client_reference_id":"PW-2026-ABC123"}}}';
        $t = time();
        $notification = $gateway->parseWebhook($this->webhook($body, ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test')]));
        $this->assertSame('evt_1', $notification->eventId);
        $this->assertSame('PW-2026-ABC123', $notification->reference);

        // Replay outside the tolerance window is rejected even with a valid signature.
        $old = $t - 3600;
        try {
            $gateway->parseWebhook($this->webhook($body, ['Stripe-Signature' => "t={$old},v1=".hash_hmac('sha256', "{$old}.{$body}", 'whsec_test')]));
            $this->fail('Replayed webhook accepted');
        } catch (InvalidWebhookSignature) {
        }

        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_1', 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 19061, 'currency' => 'usd',
            'payment_intent' => ['id' => 'pi_1', 'status' => 'succeeded', 'latest_charge' => ['payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'wallet' => ['type' => 'google_pay']]]]],
        ])]);
        $verify = $gateway->verify($this->payment(['gateway' => 'stripe', 'gateway_reference' => 'cs_1']));
        $this->assertSame(PaymentStatus::Successful, $verify->status);
        $this->assertSame('USD', $verify->currency);
        $this->assertSame('Google Pay · Visa •••• 4242', $verify->methodLabel);
        $this->assertSame('pi_1', $verify->metadata['payment_intent']);
    }

    public function test_monnify_auth_token_and_signature(): void
    {
        $gateway = new MonnifyGateway(['api_key' => 'MK', 'secret_key' => 'SK', 'contract_code' => '123', 'base_url' => 'https://sandbox.monnify.com']);
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response(['responseBody' => ['accessToken' => 'tok']]),
            'sandbox.monnify.com/api/v2/merchant/transactions/query*' => Http::response(['responseBody' => ['paymentStatus' => 'PARTIALLY_PAID', 'amountPaid' => '100.00', 'currencyCode' => 'NGN']]),
        ]);

        $verify = $gateway->verify($this->payment(['gateway' => 'monnify', 'currency' => 'NGN']));
        $this->assertSame(PaymentStatus::Processing, $verify->status, 'partial payments are never treated as successful');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactions/query') && $r->hasHeader('Authorization', 'Bearer tok'));

        $body = '{"eventType":"SUCCESSFUL_TRANSACTION","eventData":{"paymentReference":"PW-2026-ABC123","transactionReference":"MNFY|1"}}';
        $this->assertSame('payment', $gateway->parseWebhook($this->webhook($body, ['monnify-signature' => hash_hmac('sha512', $body, 'SK')]))->kind);
    }

    public function test_paypal_captures_approved_orders_and_verifies_webhooks_via_api(): void
    {
        $gateway = new PayPalGateway(['client_id' => 'cid', 'client_secret' => 'sec', 'webhook_id' => 'WH-1', 'base_url' => 'https://api-m.sandbox.paypal.com']);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'A21']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1/capture' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['value' => '190.61', 'currency_code' => 'USD']]]]]]]),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1' => Http::response(['id' => 'ORDER1', 'status' => 'APPROVED']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);

        $verify = $gateway->verify($this->payment(['gateway' => 'paypal', 'gateway_reference' => 'ORDER1']));
        $this->assertSame(PaymentStatus::Successful, $verify->status);
        $this->assertSame(19061, $verify->amount);
        $this->assertSame('CAP1', $verify->metadata['capture_id']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', 'capture-PW-2026-ABC123'));

        $this->expectException(InvalidWebhookSignature::class);
        $gateway->parseWebhook($this->webhook('{"id":"WH-EVT","event_type":"PAYMENT.CAPTURE.COMPLETED"}', ['paypal-transmission-id' => 'x']));
    }

    public function test_provider_errors_are_classified(): void
    {
        $gateway = new PaystackGateway(['secret_key' => 'sk', 'base_url' => 'https://api.paystack.co']);
        Http::fake(['api.paystack.co/*' => Http::response(['message' => 'down'], 503)]);

        try {
            $gateway->initialize($this->payment(), $this->init());
            $this->fail('No exception');
        } catch (ProviderException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(503, $e->httpStatus);
        }

        $unconfigured = new PaystackGateway(['secret_key' => '', 'base_url' => 'https://api.paystack.co']);
        $this->assertFalse($unconfigured->isConfigured());
    }
}
