<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Enums\EscrowStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\Gateways\SandboxGateway;
use App\Integrations\Payments\InitializeRequest;
use App\Integrations\Payments\InitializeResult;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\RefundResult;
use App\Integrations\Payments\VerificationResult;
use App\Integrations\Payments\WebhookNotification;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Services\Orders\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CheckoutPaymentFlowTest extends TestCase
{
    private const RETURN_URL = 'https://pilotwardrobe.test/payment/callback';

    private User $buyer;

    private User $seller;

    private Listing $listing;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->kycApproved()->create(['created_at' => now()->subYear()]);
        $this->listing = Listing::factory()->active()->create(['seller_id' => $this->seller->id, 'price_amount' => 74500]);
        $this->buyer = User::factory()->create();
        $this->address = Address::query()->create([
            'user_id' => $this->buyer->id, 'label' => 'Home', 'full_name' => 'Nehemiah Okoye', 'phone' => '+2348021184460',
            'line1' => '18 Ikoyi Crescent', 'city' => 'Lagos', 'state' => 'Lagos', 'country_code' => 'NG', 'is_default' => true,
        ]);
    }

    private function placeOrder(): array
    {
        $this->actingAsUser($this->buyer);

        return $this->postJson('/api/v1/orders', [
            'listing_id' => $this->listing->id,
            'address_id' => $this->address->id,
            'shipping_option_id' => 'shp_std',
        ])->assertCreated()->json('data');
    }

    public function test_quote_is_computed_server_side(): void
    {
        $this->actingAsUser($this->buyer);

        $this->postJson('/api/v1/checkout/quote', ['listing_id' => $this->listing->id, 'address_id' => $this->address->id, 'shipping_option_id' => 'shp_std'])
            ->assertOk()
            ->assertJsonPath('data.totals.items.amount', 74500)
            ->assertJsonPath('data.totals.freight.amount', 2400)
            ->assertJsonPath('data.totals.buyer_protection.amount', 1490)
            ->assertJsonPath('data.totals.duties', null)
            ->assertJsonPath('data.totals.total.amount', 78390)
            ->assertJsonPath('data.selected_shipping_id', 'shp_std');

        $this->assertSame(0, Order::query()->count(), 'quote must have no side effects');
    }

    public function test_full_purchase_flow_through_escrow_to_available_balance(): void
    {
        $order = $this->placeOrder();
        $this->assertSame('pending_payment', $order['status']);
        $this->assertSame('not_funded', $order['escrow_status']);
        $this->assertSame(['pay', 'cancel'], $order['allowed_actions']);
        $this->assertSame(1, $this->listing->fresh()->reserved_quantity);

        $init = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])
            ->assertOk()->assertJsonPath('data.status', 'pending')->json('data');
        $again = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])->json('data');
        $this->assertSame($init['reference'], $again['reference'], 'initialize is idempotent per order + gateway');

        // The redirect alone proves nothing: still pending until verified.
        $this->getJson('/api/v1/payments/'.$init['reference'])->assertOk()->assertJsonPath('data.status', 'pending');

        SandboxGateway::setOutcome($init['reference'], PaymentStatus::Successful);
        $this->getJson('/api/v1/payments/'.$init['reference'])->assertOk()
            ->assertJsonPath('data.status', 'successful')->assertJsonPath('data.order_id', $order['id']);

        $paid = Order::query()->findOrFail($order['id']);
        $this->assertSame(OrderStatus::AwaitingDispatch, $paid->status);
        $this->assertSame(EscrowStatus::Held, $paid->escrow_status);
        $this->assertSame(ListingStatus::Sold, $this->listing->fresh()->status);
        $this->assertSame(68540, $paid->seller_net_amount); // 74,500 − 8% commission; PW freight is retained by the platform

        $wallet = Wallet::query()->where('user_id', $this->seller->id)->firstOrFail();
        $this->assertSame(68540, $wallet->escrow_amount);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->seller->id, 'type' => 'order.new_sale']);

        // Seller dispatches; buyer confirms; funds wait out the dispute window, then become available.
        $this->actingAsUser($this->seller);
        $this->postJson("/api/v1/orders/{$order['id']}/dispatch", ['carrier' => 'DHL Express', 'tracking_number' => 'JD0146'])
            ->assertOk()->assertJsonPath('data.status', 'in_transit');

        $this->actingAsUser($this->buyer);
        $this->postJson("/api/v1/orders/{$order['id']}/confirm-delivery")->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.escrow_status', 'released');

        $wallet->refresh();
        $this->assertSame(0, $wallet->escrow_amount);
        $this->assertSame(68540, $wallet->pending_release_amount);

        $completed = Order::query()->findOrFail($order['id']);
        $this->assertFalse(app(OrderService::class)->settleFunds($completed), 'not before the dispute window ends');
        $this->travelTo($completed->funds_available_at->copy()->addMinute());
        $this->assertTrue(app(OrderService::class)->settleFunds($completed));
        $wallet->refresh();
        $this->assertSame(68540, $wallet->available_amount);
        $this->assertSame(0, $wallet->pending_release_amount);
    }

    public function test_order_creation_is_idempotent_with_header(): void
    {
        $this->actingAsUser($this->buyer);
        $payload = ['listing_id' => $this->listing->id, 'address_id' => $this->address->id];

        $first = $this->withHeaders(['Idempotency-Key' => 'checkout-123'])->postJson('/api/v1/orders', $payload)->assertCreated();
        $second = $this->withHeaders(['Idempotency-Key' => 'checkout-123'])->postJson('/api/v1/orders', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Order::query()->count());

        $this->withHeaders(['Idempotency-Key' => 'checkout-123'])->postJson('/api/v1/orders', ['listing_id' => $this->listing->id])
            ->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_double_purchase_of_last_unit_is_prevented(): void
    {
        $this->placeOrder();
        $other = User::factory()->create();
        $this->actingAsUser($other);

        $this->assertErrorEnvelope($this->postJson('/api/v1/orders', ['listing_id' => $this->listing->id]), 409, 'CONFLICT');
    }

    public function test_return_url_must_be_our_frontend(): void
    {
        $order = $this->placeOrder();

        $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => 'https://evil.example/steal'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['return_url']]);
    }

    public function test_other_users_cannot_see_or_pay_an_order(): void
    {
        $order = $this->placeOrder();
        $this->actingAsUser(User::factory()->create());

        $this->assertErrorEnvelope($this->getJson("/api/v1/orders/{$order['id']}"), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL]), 404, 'NOT_FOUND');
        $this->assertErrorEnvelope($this->postJson("/api/v1/orders/{$order['id']}/cancel"), 404, 'NOT_FOUND');
    }

    public function test_amount_mismatch_is_never_marked_successful(): void
    {
        $order = $this->placeOrder();
        $this->fakeGateway(fn (Payment $p) => new VerificationResult(PaymentStatus::Successful, $p->amount - 100, $p->currency));

        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])->json('data.reference');
        $this->getJson("/api/v1/payments/{$ref}")->assertOk()->assertJsonPath('data.status', 'failed');

        $this->assertSame(OrderStatus::PendingPayment, Order::query()->findOrFail($order['id'])->status);
        $this->assertTrue(AuditLog::query()->where('action', 'PAYMENT.MISMATCH')->exists());
    }

    public function test_currency_mismatch_is_never_marked_successful(): void
    {
        $order = $this->placeOrder();
        $this->fakeGateway(fn (Payment $p) => new VerificationResult(PaymentStatus::Successful, $p->amount, 'NGN'));

        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])->json('data.reference');
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'failed');
    }

    public function test_paystack_webhook_signature_dedupe_and_server_side_verification(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_secret']);
        $order = $this->placeOrder();
        $payment = Payment::query()->create([
            'reference' => 'PW-2026-TESTREF001', 'payable_type' => 'order', 'payable_id' => $order['id'], 'user_id' => $this->buyer->id,
            'gateway' => 'paystack', 'amount' => $order['totals']['total']['amount'], 'currency' => 'USD', 'status' => 'pending',
            'authorization_url' => 'https://checkout.paystack.com/x',
        ]);

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'amount' => $payment->amount, 'currency' => 'USD', 'id' => 99881, 'reference' => $payment->reference,
            'authorization' => ['last4' => '4417', 'card_type' => 'visa'], 'channel' => 'card',
        ]])]);

        $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 99881, 'reference' => $payment->reference, 'amount' => 1, 'currency' => 'USD']]);

        // Forged
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged', 'HTTP_ACCEPT' => 'application/json'], $body)
            ->assertStatus(400);
        $this->assertSame(0, WebhookEvent::query()->count());

        $signature = hash_hmac('sha512', $body, 'sk_test_secret');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $body)->assertOk()->assertJsonPath('received', true);
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $body)->assertOk()->assertJsonPath('duplicate', true);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Successful, $payment->status, 'webhook amount (1) is ignored; the verify API amount is used');
        $this->assertSame('Visa •••• 4417', $payment->method_label);
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(OrderStatus::AwaitingDispatch, Order::query()->findOrFail($order['id'])->status);
        Http::assertSentCount(1);
    }

    public function test_late_payment_for_expired_order_is_refunded_automatically(): void
    {
        $order = $this->placeOrder();
        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])->json('data.reference');

        $this->travel(20)->minutes();
        app(OrderService::class)->expireUnpaid(Order::query()->findOrFail($order['id']));
        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($order['id'])->status);
        $this->assertSame(0, $this->listing->fresh()->reserved_quantity);

        SandboxGateway::setOutcome($ref, PaymentStatus::Successful);
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'refunded');
        $this->assertSame(1, Refund::query()->where('reason', 'duplicate_payment')->count());
    }

    public function test_buyer_can_cancel_paid_order_before_dispatch_with_full_refund(): void
    {
        $order = $this->placeOrder();
        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order['id'], 'gateway' => 'sandbox', 'return_url' => self::RETURN_URL])->json('data.reference');
        SandboxGateway::setOutcome($ref, PaymentStatus::Successful);
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'successful');

        $this->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Changed my mind'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(ListingStatus::Active, $this->listing->fresh()->status);
        $this->assertSame(0, Wallet::query()->where('user_id', $this->seller->id)->value('escrow_amount'));
        $this->assertSame(PaymentStatus::Refunded, Payment::query()->where('reference', $ref)->first()->status);

        $this->assertErrorEnvelope($this->postJson("/api/v1/orders/{$order['id']}/confirm-delivery"), 409, 'INVALID_STATE_TRANSITION');
    }

    private function fakeGateway(callable $verify): void
    {
        $fake = new class($verify) implements PaymentGateway
        {
            public function __construct(private $verify) {}

            public function code(): string
            {
                return 'sandbox';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function publicKey(): ?string
            {
                return null;
            }

            public function initialize(Payment $payment, InitializeRequest $request): InitializeResult
            {
                return new InitializeResult('https://pilotwardrobe.test/payment/mock-gateway?reference='.$payment->reference, 'gw_'.$payment->reference);
            }

            public function verify(Payment $payment): VerificationResult
            {
                return ($this->verify)($payment);
            }

            public function refund(Payment $payment, int $amount, string $refundReference, string $reason): RefundResult
            {
                return new RefundResult('succeeded', 'rf_'.$refundReference);
            }

            public function parseWebhook(Request $request): WebhookNotification
            {
                throw new \RuntimeException;
            }
        };
        app(GatewayRegistry::class)->set('sandbox', $fake);
    }
}
