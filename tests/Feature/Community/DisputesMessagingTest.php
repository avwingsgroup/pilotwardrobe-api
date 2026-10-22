<?php

declare(strict_types=1);

namespace Tests\Feature\Community;

use App\Enums\EscrowStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\Gateways\SandboxGateway;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Report;
use App\Models\User;
use App\Models\Wallet;
use Tests\TestCase;

final class DisputesMessagingTest extends TestCase
{
    private User $buyer;

    private User $seller;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->kycApproved()->create(['created_at' => now()->subYear()]);
        $this->listing = Listing::factory()->active()->create(['seller_id' => $this->seller->id, 'price_amount' => 14500]);
        $this->buyer = User::factory()->create();
        Address::query()->create(['user_id' => $this->buyer->id, 'full_name' => 'B', 'phone' => '+2348000000000', 'line1' => 'x', 'city' => 'Lagos', 'country_code' => 'NG', 'is_default' => true]);
    }

    /** Places, pays and delivers an order; returns its id. */
    private function deliveredOrder(): string
    {
        $this->actingAsUser($this->buyer);
        $address = Address::query()->where('user_id', $this->buyer->id)->value('id');
        $order = $this->postJson('/api/v1/orders', ['listing_id' => $this->listing->id, 'address_id' => $address])->json('data.id');
        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $order, 'gateway' => 'sandbox', 'return_url' => 'https://pilotwardrobe.test/payment/callback'])->json('data.reference');
        SandboxGateway::setOutcome($ref, PaymentStatus::Successful);
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'successful');

        $this->actingAsUser($this->seller);
        $this->postJson("/api/v1/orders/{$order}/dispatch", ['carrier' => 'DHL', 'tracking_number' => 'T1'])->assertOk();
        $this->postJson("/api/v1/orders/{$order}/tracking", ['status' => 'delivered'])->assertOk()->assertJsonPath('data.status', 'delivered');

        return $order;
    }

    public function test_dispute_freezes_escrow_and_partial_settlement_splits_funds(): void
    {
        $orderId = $this->deliveredOrder();
        $this->actingAsUser($this->buyer);

        $this->postJson('/api/v1/disputes', ['order_id' => $orderId, 'reason' => '', 'requested' => 'full_refund', 'description' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'Tell us what went wrong.')
            ->assertJsonPath('errors.description.0', 'Describe the issue in at least 20 characters.');

        $dispute = $this->postJson('/api/v1/disputes', [
            'order_id' => $orderId, 'reason' => 'not_as_described', 'requested' => 'partial_refund', 'amount' => 4500,
            'description' => 'Label reads S. Chest 98 cm vs listing 104 cm.',
        ])->assertCreated()->assertJsonPath('data.status', 'awaiting_seller')->json('data');

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Disputed, $order->status);
        $this->assertSame(EscrowStatus::Frozen, $order->escrow_status);
        $this->assertErrorEnvelope($this->postJson("/api/v1/orders/{$orderId}/confirm-delivery"), 409, 'INVALID_STATE_TRANSITION');

        // A stranger cannot read the case.
        $this->actingAsUser(User::factory()->create());
        $this->assertErrorEnvelope($this->getJson("/api/v1/disputes/{$dispute['id']}"), 404, 'NOT_FOUND');

        $this->actingAsUser($this->seller);
        $this->postJson("/api/v1/disputes/{$dispute['id']}/messages", ['body' => 'Listed as M per the original tag.'])
            ->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/v1/disputes/{$dispute['id']}/proposal", ['kind' => 'partial_refund', 'amount' => 4500])
            ->assertOk()->assertJsonPath('data.seller_proposal.status', 'pending');

        $this->actingAsUser($this->buyer);
        $this->getJson("/api/v1/disputes/{$dispute['id']}")->assertJsonPath('data.viewer_role', 'buyer')
            ->assertJsonPath('data.allowed_actions', ['reply', 'add_evidence', 'withdraw', 'accept_proposal', 'decline_proposal']);
        $this->postJson("/api/v1/disputes/{$dispute['id']}/proposal/accept")->assertOk()
            ->assertJsonPath('data.status', 'resolved_split')->assertJsonPath('data.allowed_actions', []);

        $order->refresh();
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertSame(4500, $order->refunded_amount);
        $wallet = Wallet::query()->where('user_id', $this->seller->id)->firstOrFail();
        $this->assertSame(0, $wallet->escrow_amount);
        $this->assertSame(14500 - 1160 - 4500, $wallet->pending_release_amount); // price − 8% fee − refund
    }

    public function test_staff_decision_settles_dispute_and_needs_dispute_permission(): void
    {
        $orderId = $this->deliveredOrder();
        $this->actingAsUser($this->buyer);
        $id = $this->postJson('/api/v1/disputes', ['order_id' => $orderId, 'reason' => 'damaged', 'requested' => 'partial_refund', 'amount' => 5000, 'description' => 'The ear cup arrived cracked down the middle.'])->json('data.id');

        $this->actingAsUser(User::factory()->staff('support_agent')->create());
        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/disputes/{$id}/decision", ['decision' => 'partial_refund', 'amount_to_buyer' => 5000, 'policy_reference' => 'BP 4.2']), 403, 'FORBIDDEN');

        // No `note`: optional fields may be omitted entirely.
        $this->actingAsUser(User::factory()->staff('trust_safety')->create());
        $this->postJson("/api/v1/admin/disputes/{$id}/decision", ['decision' => 'partial_refund', 'amount_to_buyer' => 5000, 'policy_reference' => 'BP 4.2'])
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::PartiallyRefunded, $order->status);
        $this->assertSame(5000, $order->refunded_amount);
        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/disputes/{$id}/decision", ['decision' => 'release_to_seller', 'policy_reference' => 'BP 4.2']), 409, 'INVALID_STATE_TRANSITION');
    }

    public function test_withdrawing_a_dispute_unfreezes_escrow(): void
    {
        $orderId = $this->deliveredOrder();
        $this->actingAsUser($this->buyer);
        $id = $this->postJson('/api/v1/disputes', ['order_id' => $orderId, 'reason' => 'damaged', 'requested' => 'full_refund', 'description' => 'The ear cup arrived cracked down the middle.'])->json('data.id');

        $this->postJson("/api/v1/disputes/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(EscrowStatus::Held, $order->escrow_status);
    }

    public function test_messaging_flags_off_platform_payment_and_counts_unread(): void
    {
        $this->actingAsUser($this->buyer);
        $conversation = $this->postJson('/api/v1/conversations', ['listing_id' => $this->listing->id])->assertCreated()->json('data');
        $this->assertSame($conversation['id'], $this->postJson('/api/v1/conversations', ['listing_id' => $this->listing->id])->json('data.id'));

        $this->postJson("/api/v1/conversations/{$conversation['id']}/messages", ['body' => ''])
            ->assertStatus(422)->assertJsonPath('errors.body.0', 'Write a message first.');
        $this->postJson("/api/v1/conversations/{$conversation['id']}/messages", ['body' => 'Is the headset still available?'])
            ->assertCreated()->assertJsonPath('data.mine', true);
        $this->postJson("/api/v1/conversations/{$conversation['id']}/messages", ['body' => "Let's do bank transfer instead, account number 0123456789"])
            ->assertCreated()->assertJsonPath('data.flags', ['bank_details', 'off_platform_payment']);
        $this->assertTrue(Report::query()->where('source', 'automated')->exists());

        $this->actingAsUser($this->seller);
        $this->getJson('/api/v1/conversations?context=selling')->assertOk()->assertJsonPath('data.0.unread_count', 2);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.counters.unread_messages', 2);
        $this->postJson("/api/v1/conversations/{$conversation['id']}/read")->assertNoContent();
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.unread_count', 0);

        $this->actingAsUser(User::factory()->create());
        $this->assertErrorEnvelope($this->getJson("/api/v1/conversations/{$conversation['id']}/messages"), 404, 'NOT_FOUND');
    }

    public function test_notification_centre(): void
    {
        $this->deliveredOrder();
        $this->actingAsUser($this->buyer);

        $list = $this->getJson('/api/v1/notifications')->assertOk()->json('data');
        $this->assertNotEmpty($list);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', count($list));
        $this->patchJson("/api/v1/notifications/{$list[0]['id']}/read")->assertOk()->assertJsonPath('data.id', $list[0]['id']);
        $this->postJson('/api/v1/notifications/read-all')->assertNoContent();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 0);

        $this->actingAsUser($this->seller);
        $this->assertErrorEnvelope($this->patchJson("/api/v1/notifications/{$list[0]['id']}/read"), 404, 'NOT_FOUND');
    }

    public function test_contact_form_and_help(): void
    {
        $this->postJson('/api/v1/contact', ['name' => '', 'email' => 'nope', 'topic' => 'callback', 'subject' => '', 'message' => 'short', 'phone' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Please tell us your name.')
            ->assertJsonPath('errors.phone.0', 'Enter a phone number we can call.');

        $this->postJson('/api/v1/contact', ['name' => 'Ada', 'email' => 'ada@example.com', 'topic' => 'callback', 'subject' => 'Tracking', 'message' => 'Order tracking has not updated.', 'phone' => '+234 803 123 4567'])
            ->assertCreated()->assertJsonStructure(['data' => ['ticket']]);

        $this->getJson('/api/v1/help/summary')->assertOk()->assertJsonPath('data.status.overall', 'unknown');
        $this->getJson('/api/v1/support/contact-info')->assertOk()->assertJsonCount(24, 'data.chat_load');
        $this->postJson('/api/v1/newsletter', ['email' => 'news@example.com'])->assertNoContent();
    }
}
