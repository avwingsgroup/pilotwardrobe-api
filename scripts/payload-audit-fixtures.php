<?php

// Builds the records the payload samples refer to (inside the audit's rolled-back transaction)
// and returns resolvers that map mock ids (ord_4471, lst_003, …) to real ids.

use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\FitKitProfile;
use App\Models\HeroSlide;
use App\Models\KycVerification;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PayoutMethod;
use App\Models\Report;
use App\Models\StaffRole;
use App\Models\SwapRequest;
use App\Models\User;
use App\Models\Wallet;

return function () {
    $u = fn (string $email) => User::query()->where('email', $email)->firstOrFail();
    $pilot = $u('pilot@example.com');
    $aviator = $u('aviator@example.com');
    $newbie = $u('newbie@example.com');
    $log = [];

    $must = function (array $result, string $what) use (&$log) {
        [$status, $json] = $result;
        if ($status >= 300) {
            $log[] = "fixture {$what} failed: {$status} ".json_encode($json);
        }

        return $json['data'] ?? [];
    };

    // Default address for the pilot.
    $address = Address::query()->where('user_id', $pilot->id)->first()
        ?? Address::query()->forceCreate(['user_id' => $pilot->id, 'label' => 'Home', 'full_name' => 'Nehemiah Okoye', 'phone' => '+2348031234567',
            'line1' => '12 Airport Rd', 'city' => 'Ikeja', 'state' => 'Lagos', 'country_code' => 'NG', 'is_default' => true]);

    // Orders bought by the pilot from the aviator, driven through the real API.
    $aviatorListings = Listing::query()->where('seller_id', $aviator->id)->where('status', ListingStatus::Active)->orderBy('id')->get();
    $buy = function (Listing $listing, string $stage) use ($pilot, $aviator, $address, $must) {
        $order = $must(call('POST', '/orders', $pilot->email, ['listing_id' => $listing->id, 'address_id' => $address->id, 'shipping_option_id' => 'shp_std']), "order {$stage}");
        $orderId = $order['id'] ?? ($order['order']['id'] ?? null);
        if ($orderId === null || $stage === 'pending') {
            return $orderId;
        }
        $init = $must(call('POST', '/payments/initialize', $pilot->email, ['order_id' => $orderId, 'gateway' => 'sandbox', 'return_url' => config('platform.frontend_url').'/payment/callback']), "pay {$stage}");
        $ref = $init['reference'] ?? null;
        call('POST', "/sandbox/payments/{$ref}/approve", $pilot->email);
        call('GET', "/payments/{$ref}", $pilot->email);
        if ($stage === 'paid') {
            return $orderId;
        }
        $must(call('POST', "/orders/{$orderId}/dispatch", $aviator->email, ['carrier' => 'DHL', 'tracking_number' => 'JD0146000032']), "dispatch {$stage}");
        Order::query()->whereKey($orderId)->first()?->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'auto_release_at' => now()->addHours(72)])->save();
        if ($stage === 'completed') {
            $must(call('POST', "/orders/{$orderId}/confirm-delivery", $pilot->email), 'confirm');
        }

        return $orderId;
    };
    $orders = [
        'completed' => $buy($aviatorListings[0], 'completed'),
        'delivered' => $buy($aviatorListings[1], 'delivered'),
        'disputed' => $buy($aviatorListings[2], 'delivered'),
        'admin' => $buy($aviatorListings[3], 'delivered'),
        'pending' => $buy($aviatorListings[4], 'pending'),
    ];

    $dispute = $must(call('POST', '/disputes', $pilot->email, ['order_id' => $orders['disputed'], 'reason' => 'damaged', 'requested' => 'partial_refund', 'amount' => 5000, 'description' => 'Ear cup cracked on arrival, photos attached.']), 'dispute');
    $disputeId = $dispute['id'] ?? Dispute::query()->latest('created_at')->value('id');
    $must(call('POST', "/disputes/{$disputeId}/proposal", $aviator->email, ['kind' => 'partial_refund', 'amount' => 5000, 'summary' => 'Happy to refund $50.']), 'proposal');

    // A swap proposed by the aviator on one of the pilot's listings.
    $pilotListing = Listing::query()->where('seller_id', $pilot->id)->where('status', ListingStatus::Active)->orderBy('id')->firstOrFail();
    $swap = $must(call('POST', "/listings/{$pilotListing->id}/swap-requests", $aviator->email, ['offered_listing_ids' => [$aviatorListings[5]->id], 'message' => 'Straight swap?']), 'swap');

    // An offer the pilot received.
    $offerTo = $must(call('POST', "/listings/{$pilotListing->id}/offers", $aviator->email, ['amount' => (int) round($pilotListing->price_amount * 0.8), 'currency' => $pilotListing->currency, 'message' => 'Would you take this?']), 'offer');

    // Admin fixtures.
    $photoListings = Listing::query()->where('seller_id', $u('photographer@example.com')->id)->where('status', ListingStatus::Active)->orderBy('id')->get();
    $pendingReview = $photoListings[0];
    $pendingReview->forceFill(['status' => ListingStatus::PendingReview])->save();
    $report = Report::query()->forceCreate(['reporter_id' => $pilot->id, 'target_type' => 'listing', 'target_id' => $photoListings[1]->id, 'reason' => 'counterfeit', 'detail' => 'Looks fake', 'severity' => 'medium', 'source' => 'user', 'status' => 'open']);
    $kyc = KycVerification::query()->forceCreate(['user_id' => $newbie->id, 'code' => 'KYC-AUDIT', 'status' => 'pending', 'step' => 'review', 'document_type' => 'passport', 'document_country' => 'NG', 'submitted_at' => now()]);
    $method = PayoutMethod::query()->where('user_id', $pilot->id)->first()
        ?? PayoutMethod::query()->forceCreate(['user_id' => $pilot->id, 'kind' => 'bank', 'label' => 'GTBank ••3344', 'currency' => 'USD', 'country_code' => 'NG', 'last4' => '3344', 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_name' => 'Nehemiah Okoye', 'account_number' => '0123443344', 'provider' => 'manual', 'is_default' => true, 'verified_at' => now()]);
    $wallet = Wallet::query()->firstOrCreate(['user_id' => $pilot->id, 'currency' => 'USD']);
    $wallet->forceFill(['available_amount' => $wallet->available_amount + 500000])->save();
    $payout = $must(call('POST', '/me/withdrawals', $pilot->email, ['amount' => 20000, 'currency' => 'USD', 'payout_method_id' => $method->id]), 'withdrawal');

    $fit = $must(call('GET', '/me/fit-kit', $pilot->email), 'fit kit');
    if (empty($fit['profiles'] ?? $fit)) {
        call('POST', '/me/fit-kit', $pilot->email, ['name' => 'Me', 'jacket' => '40R', 'collar' => '15.5', 'is_default' => true]);
    }
    call('POST', '/contact', $pilot->email, ['name' => 'Nehemiah', 'email' => 'pilot@example.com', 'topic' => 'order', 'subject' => 'Hello there', 'message' => 'Creating a notification for the audit run.']);

    $first = fn ($query) => $query->orderBy('id')->value('id');

    $resolve = function (string $prefix, string $raw, ?string $actor) use ($orders, $disputeId, $swap, $offerTo, $report, $kyc, $pilot, $newbie, $first) {
        $user = $actor ? User::query()->where('email', $actor)->first() : null;
        $mock = ltrim($raw, '/');

        return match (true) {
            $mock === 'lst_003' => Listing::query()->where('slug', 'like', 'pilotwardrobe-aviator-uniform-shirt%')->value('id'),
            $mock === 'lst_001' => $first(Listing::query()->where('seller_id', $user?->id)->where('status', 'active')),
            str_starts_with($mock, 'lst_p') => Listing::factory()->create(['seller_id' => $user?->id, 'status' => ListingStatus::Draft])->id,
            $mock === 'ord_4471' => $orders['completed'],
            $mock === 'ord_4402' => $orders['delivered'],
            $mock === 'ord_4485' => $orders['pending'],
            $mock === 'ord_4479a' => $orders['admin'],
            str_starts_with($mock, 'PW-') => Payment::query()->where('user_id', $pilot->id)->latest('created_at')->value('reference'),
            $prefix === 'off' => $offerTo['id'] ?? Offer::query()->where('status', 'pending')->value('id'),
            $prefix === 'swp' => $swap['id'] ?? SwapRequest::query()->value('id'),
            $prefix === 'cnv' => Conversation::query()->where(fn ($q) => $q->where('buyer_id', $user?->id)->orWhere('seller_id', $user?->id))->value('id'),
            $prefix === 'dsp' => $disputeId,
            $prefix === 'ntf' => Notification::query()->where('user_id', $user?->id)->latest('created_at')->value('id'),
            $prefix === 'fit' => FitKitProfile::query()->where('user_id', $user?->id)->value('id'),
            $prefix === 'usr' => $newbie->id,
            $prefix === 'rep' => $report->id,
            $prefix === 'po' => Payout::query()->latest('created_at')->value('id'),
            $prefix === 'kyc' => $kyc->id,
            $prefix === 'role' => StaffRole::query()->where('slug', 'trust_safety')->value('id'),
            $prefix === 'hs' => HeroSlide::query()->value('id'),
            default => null,
        };
    };

    $mapBody = function (array $sample, mixed $value) use (&$mapBody, $resolve, $address, $method, $pendingReview, $orders) {
        if (is_array($value)) {
            return array_map(fn ($v) => $mapBody($sample, $v), $value);
        }
        if (! is_string($value)) {
            return $value;
        }

        return match (true) {
            $value === 'adr_1' => $address->id,
            $value === 'pm_1' => $method->id,
            str_starts_with($value, 'https://pilotwardrobe.com/') => config('platform.frontend_url').substr($value, strlen('https://pilotwardrobe.com')),
            $value === 'mod_1' => $pendingReview->id,
            $value === 'ord_4471' && $sample['path'] === '/disputes' => $orders['delivered'],
            (bool) preg_match('/^(lst|ord|off|swp|cnv|dsp)_[A-Za-z0-9]+$/', $value) => $resolve(explode('_', $value)[0], '/'.$value, $sample['actor']) ?? $value,
            default => $value,
        };
    };

    fwrite(STDERR, implode("\n", $log)."\n");

    return ['resolve' => $resolve, 'body' => $mapBody];
};
