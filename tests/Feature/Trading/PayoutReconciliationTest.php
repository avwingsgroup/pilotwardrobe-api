<?php

declare(strict_types=1);

namespace Tests\Feature\Trading;

use App\Enums\PayoutStatus;
use App\Models\ExchangeRate;
use App\Models\Payout;
use App\Models\PayoutMethod;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Transfers that the provider accepts as "pending" are settled later from the provider's own status. */
final class PayoutReconciliationTest extends TestCase
{
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_secret', 'services.flutterwave.secret_key' => 'FLWSECK_TEST']);
        ExchangeRate::query()->create(['base' => 'USD', 'quote' => 'NGN', 'rate' => 1532.4, 'source' => 'test']);
        $this->seller = User::factory()->kycApproved()->create();
        DB::transaction(fn () => app(WalletService::class)->walletFor($this->seller, 'NGN')->forceFill(['available_amount' => 10_000_000])->save());
    }

    private function withdraw(string $provider, array $transferResponse): Payout
    {
        $method = PayoutMethod::query()->create([
            'user_id' => $this->seller->id, 'kind' => 'bank', 'label' => 'GTBank ••3344', 'currency' => 'NGN', 'country_code' => 'NG',
            'last4' => '3344', 'bank_code' => '058', 'provider' => $provider, 'is_default' => true,
            'provider_recipient_code' => $provider === 'paystack' ? 'RCP_test' : '058:0123443344',
        ]);
        Http::fake([
            'api.paystack.co/transfer' => Http::response(['status' => true, 'data' => $transferResponse]),
            'api.flutterwave.com/v3/transfers' => Http::response(['status' => 'success', 'data' => $transferResponse]),
        ]);

        $this->actingAsUser($this->seller);
        $id = $this->postJson('/api/v1/me/withdrawals', ['amount' => 5_000_000, 'currency' => 'NGN', 'payout_method_id' => $method->id])
            ->assertCreated()->json('data.payout_id');

        $payout = Payout::query()->findOrFail($id);
        $this->assertSame(PayoutStatus::Processing, $payout->status, 'the provider accepted the transfer but has not completed it');

        return $payout;
    }

    public function test_signed_transfer_webhook_settles_payout_only_after_provider_confirms(): void
    {
        $payout = $this->withdraw('paystack', ['status' => 'pending', 'transfer_code' => 'TRF_1']);
        $body = json_encode(['event' => 'transfer.success', 'data' => ['id' => 501, 'reference' => strtolower($payout->reference), 'status' => 'success']]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret')];

        // The provider still reports "pending": the webhook body alone does not mark it paid.
        Http::fake(['api.paystack.co/transfer/verify/*' => Http::sequence()->push(['status' => true, 'data' => ['status' => 'pending']])->push(['status' => true, 'data' => ['status' => 'success']])]);
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $body)->assertOk();
        $this->assertSame(PayoutStatus::Processing, $payout->fresh()->status);

        // A later delivery of the same event is a duplicate; the sweep picks up the provider's success.
        $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], $headers, $body)->assertOk()->assertJsonPath('duplicate', true);
        Payout::query()->whereKey($payout->id)->update(['updated_at' => now()->subMinutes(15)]);
        $this->assertSame(0, Artisan::call('marketplace:sweep', ['sweep' => 'payouts']));

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status);
        $wallet = Wallet::query()->where('user_id', $this->seller->id)->where('currency', 'NGN')->firstOrFail();
        $this->assertSame(0, $wallet->reserved_amount);
        $this->assertSame(5_000_000, $wallet->available_amount);
    }

    public function test_sweep_returns_funds_when_the_provider_reports_failure(): void
    {
        $payout = $this->withdraw('flutterwave', ['id' => 7781, 'status' => 'NEW']);
        Http::fake(['api.flutterwave.com/v3/transfers/7781' => Http::response(['status' => 'success', 'data' => ['id' => 7781, 'status' => 'FAILED', 'complete_message' => 'Account resolve failed']])]);
        Payout::query()->whereKey($payout->id)->update(['updated_at' => now()->subMinutes(15)]);

        $this->assertSame(0, Artisan::call('marketplace:sweep', ['sweep' => 'payouts']));

        $payout->refresh();
        $this->assertSame(PayoutStatus::Rejected, $payout->status);
        $this->assertSame('Account resolve failed', $payout->failure_reason);
        $wallet = Wallet::query()->where('user_id', $this->seller->id)->where('currency', 'NGN')->firstOrFail();
        $this->assertSame(0, $wallet->reserved_amount);
        $this->assertSame(10_000_000, $wallet->available_amount, 'the failed payout is credited back');
    }
}
