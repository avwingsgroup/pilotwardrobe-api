<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Enums\ListingStatus;
use App\Enums\PayoutStatus;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutMethod;
use App\Models\User;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OffersSwapsWalletTest extends TestCase
{
    public function test_offer_negotiation_and_checkout_at_agreed_price(): void
    {
        $seller = User::factory()->kycApproved()->create();
        $listing = Listing::factory()->active()->create(['seller_id' => $seller->id, 'price_amount' => 74500, 'minimum_offer_amount' => 60000]);
        $buyer = User::factory()->create();
        Address::query()->create(['user_id' => $buyer->id, 'full_name' => 'B', 'phone' => '+2348000000000', 'line1' => 'x', 'city' => 'Lagos', 'country_code' => 'NG', 'is_default' => true]);

        $this->actingAsUser($buyer);
        $this->postJson("/api/v1/listings/{$listing->id}/offers", ['amount' => 50000, 'currency' => 'USD'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', "The seller doesn't accept offers below $600.00.");
        $this->postJson("/api/v1/listings/{$listing->id}/offers", ['amount' => 0, 'currency' => 'USD'])
            ->assertStatus(422)->assertJsonPath('errors.amount.0', 'The amount must be greater than zero.');

        $offer = $this->postJson("/api/v1/listings/{$listing->id}/offers", ['amount' => 71200, 'currency' => 'USD', 'message' => 'Today?'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.direction', 'sent')
            ->assertJsonPath('data.allowed_actions', ['cancel', 'message'])->json('data');

        $this->assertErrorEnvelope($this->postJson("/api/v1/listings/{$listing->id}/offers", ['amount' => 70000, 'currency' => 'USD']), 409, 'CONFLICT');
        // Buyer cannot accept their own offer.
        $this->assertErrorEnvelope($this->postJson("/api/v1/offers/{$offer['id']}/accept"), 403, 'FORBIDDEN');

        $this->actingAsUser($seller);
        $this->getJson('/api/v1/me/offers?box=received')->assertOk()->assertJsonPath('data.0.you_receive.amount', 65504);
        $this->postJson("/api/v1/offers/{$offer['id']}/counter", ['amount' => 80000])->assertStatus(422);
        $this->postJson("/api/v1/offers/{$offer['id']}/counter", ['amount' => 72850, 'message' => 'Meet me?'])
            ->assertOk()->assertJsonPath('data.status', 'countered')->assertJsonPath('data.counter_amount.amount', 72850);
        $this->assertErrorEnvelope($this->postJson("/api/v1/offers/{$offer['id']}/accept"), 409, 'INVALID_STATE_TRANSITION');

        $this->actingAsUser($buyer);
        $counter = $this->getJson('/api/v1/me/offers?box=received')->assertOk()->json('data.0');
        $this->assertSame(['accept', 'decline', 'message'], $counter['allowed_actions']);
        $this->postJson("/api/v1/offers/{$counter['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertSame(ListingStatus::Reserved, $listing->fresh()->status);

        // Another buyer cannot buy the reserved item.
        $this->actingAsUser(User::factory()->create());
        $this->assertErrorEnvelope($this->postJson('/api/v1/checkout/quote', ['listing_id' => $listing->id]), 409, 'CONFLICT');

        $this->actingAsUser($buyer);
        $this->postJson('/api/v1/checkout/quote', ['listing_id' => $listing->id, 'offer_id' => $counter['id']])
            ->assertOk()->assertJsonPath('data.totals.items.amount', 72850);
    }

    public function test_swap_requires_kyc_and_accept_creates_two_legs(): void
    {
        $owner = User::factory()->kycApproved()->create();
        $target = Listing::factory()->active()->create(['seller_id' => $owner->id, 'price_amount' => 14500, 'swappable' => true]);

        $this->actingAsUser(User::factory()->create());
        $this->postJson("/api/v1/listings/{$target->id}/swap-requests", ['offered_listing_ids' => [], 'message' => 'hi'])
            ->assertStatus(403)->assertJsonPath('code', 'KYC_REQUIRED')->assertJsonPath('message', 'Verify your identity to propose swaps.');

        $proposer = User::factory()->kycApproved()->create();
        $mine = Listing::factory()->active()->create(['seller_id' => $proposer->id, 'price_amount' => 74500]);
        $notMine = Listing::factory()->active()->create();
        $this->actingAsUser($proposer);

        $this->postJson("/api/v1/listings/{$target->id}/swap-requests", ['offered_listing_ids' => [$notMine->id]])->assertStatus(422);

        $swap = $this->postJson("/api/v1/listings/{$target->id}/swap-requests", [
            'offered_listing_ids' => [$mine->id], 'cash_adjustment' => ['amount' => 2000, 'currency' => 'USD'], 'message' => 'Straight swap plus $20?',
        ])->assertCreated()->assertJsonPath('data.swap_fee.amount', 3060)->json('data');

        $this->actingAsUser($owner);
        $this->postJson("/api/v1/swap-requests/{$swap['id']}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');

        $legs = Order::query()->where('swap_request_id', $swap['id'])->get();
        $this->assertCount(2, $legs);
        $proposerLeg = $legs->firstWhere('buyer_id', $proposer->id);
        $this->assertSame(2000, $proposerLeg->cash_adjustment_amount);
        $this->assertSame(2000, $proposerLeg->seller_net_amount);
        $this->assertSame(ListingStatus::Reserved, $target->fresh()->status);
        $this->assertSame(ListingStatus::Reserved, $mine->fresh()->status);
    }

    public function test_withdrawal_rules_and_dual_control(): void
    {
        $seller = User::factory()->kycApproved()->create();
        $method = PayoutMethod::query()->create(['user_id' => $seller->id, 'kind' => 'wise', 'label' => 'Wise · USD', 'currency' => 'USD', 'last4' => '7714', 'provider' => 'manual', 'is_default' => true]);
        DB::transaction(function () use ($seller) {
            $wallet = app(WalletService::class)->walletFor($seller, 'USD');
            $wallet->forceFill(['available_amount' => 500000])->save();
        });

        $this->actingAsUser($seller);
        $this->postJson('/api/v1/me/withdrawals/quote', ['amount' => 20000, 'currency' => 'USD', 'payout_method_id' => $method->id])
            ->assertOk()->assertJsonPath('data.recipient_receives.amount', 20000)->assertJsonPath('data.requires_approval', false);

        $this->postJson('/api/v1/me/withdrawals', ['amount' => 900000, 'currency' => 'USD', 'payout_method_id' => $method->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['amount']]);

        $this->withHeaders(['Idempotency-Key' => 'wd-1'])->postJson('/api/v1/me/withdrawals', ['amount' => 20000, 'currency' => 'USD', 'payout_method_id' => $method->id])
            ->assertCreated()->assertJsonPath('data.type', 'withdrawal');
        $replay = $this->withHeaders(['Idempotency-Key' => 'wd-1'])->postJson('/api/v1/me/withdrawals', ['amount' => 20000, 'currency' => 'USD', 'payout_method_id' => $method->id]);
        $replay->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->flushHeaders();
        $this->assertSame(1, Payout::query()->count(), 'idempotency key prevents a duplicate payout');

        $big = $this->postJson('/api/v1/me/withdrawals', ['amount' => 250000, 'currency' => 'USD', 'payout_method_id' => $method->id])
            ->assertCreated()->assertJsonPath('data.requires_approval', true)->json('data.payout_id');
        $this->assertSame(PayoutStatus::AwaitingApproval, Payout::query()->find($big)->status);

        $this->getJson('/api/v1/me/wallet')->assertOk()
            ->assertJsonPath('data.available.amount', 500000 - 20000 - 250000)
            ->assertJsonPath('data.reserved.amount', 270000);

        // Unverified sellers cannot withdraw.
        $this->actingAsUser(User::factory()->create());
        $other = PayoutMethod::query()->create(['user_id' => auth()->id(), 'kind' => 'wise', 'label' => 'Wise', 'currency' => 'USD', 'provider' => 'manual']);
        $this->assertErrorEnvelope($this->postJson('/api/v1/me/withdrawals', ['amount' => 1000, 'currency' => 'USD', 'payout_method_id' => $other->id]), 403, 'KYC_REQUIRED');
    }
}
