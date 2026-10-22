<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\DisputeStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\DeviceToken;
use App\Models\Dispute;
use App\Models\FitKitProfile;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PayoutMethod;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Permanent account deletion (`DELETE /me`). Refused (409) while money or obligations
 * are open. Personal data is erased/anonymised; orders, ledger rows and audit logs are
 * retained (financial record-keeping) but no longer identify the person.
 */
final class DeleteAccount
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function assertDeletable(User $user): void
    {
        $openOrders = Order::query()
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::open()))
            ->exists();
        if ($openOrders) {
            throw ApiException::conflict('You have open orders. Complete or cancel them before deleting your account.');
        }

        $balance = Wallet::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('available_amount', '>', 0)->orWhere('escrow_amount', '>', 0)->orWhere('reserved_amount', '>', 0)->orWhere('pending_release_amount', '>', 0))
            ->exists();
        if ($balance) {
            throw ApiException::conflict('Withdraw your wallet balance before deleting your account.');
        }

        $payouts = Payout::query()->where('user_id', $user->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, PayoutStatus::reserving()))->exists();
        $disputes = Dispute::query()->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->whereIn('status', array_map(fn ($s) => $s->value, DisputeStatus::active()))->exists();
        if ($payouts || $disputes) {
            throw ApiException::conflict('You have payouts or disputes in progress. Try again once they are resolved.');
        }
    }

    public function handle(User $user): void
    {
        $this->assertDeletable($user);
        $email = $user->email;
        $name = $user->first_name;

        DB::transaction(function () use ($user) {
            $this->sessions->revokeAll($user);

            Listing::query()->where('seller_id', $user->id)
                ->whereNotIn('status', [ListingStatus::Sold->value, ListingStatus::Swapped->value])
                ->update(['status' => ListingStatus::Removed->value, 'deleted_at' => now()]);

            SocialAccount::query()->where('user_id', $user->id)->delete();
            DeviceToken::query()->where('user_id', $user->id)->delete();
            FitKitProfile::query()->where('user_id', $user->id)->delete();
            Address::query()->where('user_id', $user->id)->delete();
            PayoutMethod::query()->where('user_id', $user->id)->delete();
            $user->kyc()->update([
                'legal_first_name' => null, 'legal_last_name' => null, 'date_of_birth' => null, 'document_number' => null,
            ]);

            $this->audit->log('USER.DELETED', $user, 'Self-service deletion', 'danger', actor: $user);

            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'user',
                'email' => 'deleted+'.$user->id.'@invalid.pilotwardrobe.com',
                'username' => 'deleted_'.substr($user->id, -10),
                'phone' => null,
                'password' => null,
                'bio' => null,
                'avatar_media_id' => null,
                'cover_media_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'status' => UserStatus::Deactivated,
                'marketing_opt_in' => false,
            ])->save();
            $user->delete();
        });

        // Final confirmation goes to the original address (the account no longer holds it).
        $this->notifications->emailAddress($email, 'account.deleted', ['first_name' => $name], $name);
    }
}
