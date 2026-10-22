<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\Auth\StaffAuthController;
use App\Http\Controllers\Api\V1\Catalogue\CategoryController;
use App\Http\Controllers\Api\V1\Catalogue\FavoriteController;
use App\Http\Controllers\Api\V1\Catalogue\HomeController;
use App\Http\Controllers\Api\V1\Catalogue\ListingController;
use App\Http\Controllers\Api\V1\Catalogue\SellerController;
use App\Http\Controllers\Api\V1\Disputes\DisputeController;
use App\Http\Controllers\Api\V1\Me\AddressController;
use App\Http\Controllers\Api\V1\Me\DeviceController;
use App\Http\Controllers\Api\V1\Me\FitKitController;
use App\Http\Controllers\Api\V1\Me\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Me\ProfileController;
use App\Http\Controllers\Api\V1\Me\SecurityController;
use App\Http\Controllers\Api\V1\Media\MediaController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\Selling\HangarController;
use App\Http\Controllers\Api\V1\Selling\SellerListingController;
use App\Http\Controllers\Api\V1\Support\SupportController;
use App\Http\Controllers\Api\V1\Trading\CartController;
use App\Http\Controllers\Api\V1\Trading\OfferController;
use App\Http\Controllers\Api\V1\Trading\OrderController;
use App\Http\Controllers\Api\V1\Trading\ReviewController;
use App\Http\Controllers\Api\V1\Trading\SwapController;
use App\Http\Controllers\Api\V1\Verification\VerificationController;
use App\Http\Controllers\Api\V1\Wallet\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pilotwardrobe REST API — /api/v1
|--------------------------------------------------------------------------
|
| Guards follow docs/backend-api-contract.md:
|   public    no session
|   optional  works signed out, personalised when signed in (auth resolved, not required)
|   auth      `auth:sanctum` + ownership checks in policies/actions
|   perm:x    RequirePermission middleware (effective permissions, §2)
*/

Route::middleware('throttle:api')->group(function () {

    // ------------------------------------------------------------------ authentication
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('email-lookup', [AuthController::class, 'emailLookup']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('two-factor', [AuthController::class, 'twoFactor']);
            Route::post('register', [AuthController::class, 'register']);
            Route::post('email/verify', [AuthController::class, 'verifyEmail']);
            Route::post('password/reset', [AuthController::class, 'resetPassword']);
            Route::get('password/reset/{token}', [AuthController::class, 'checkResetToken']);
            Route::get('username-availability', [AuthController::class, 'usernameAvailability']);
            Route::get('social/{provider}/redirect', [SocialAuthController::class, 'redirect']);
            Route::post('social/{provider}/callback', [SocialAuthController::class, 'callback']);
            Route::post('social/complete', [SocialAuthController::class, 'complete']);
            Route::post('social/link', [SocialAuthController::class, 'link']);
        });
        Route::post('two-factor/sms', [AuthController::class, 'twoFactorSms'])->middleware('throttle:two-factor-sms');
        Route::post('email/resend', [AuthController::class, 'resendEmail'])->middleware('throttle:otp');
        Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');

        Route::get('me', [AuthController::class, 'me'])->middleware('auth.optional');
        Route::post('logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'account']);
    });

    Route::prefix('admin/auth')->middleware('throttle:auth')->group(function () {
        Route::post('google', [StaffAuthController::class, 'google']);
        Route::post('webauthn', [StaffAuthController::class, 'webauthn']);
    });

    // ------------------------------------------------------------------ public catalogue
    Route::get('home', HomeController::class);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);
    Route::get('boost-packages', [CategoryController::class, 'boostPackages']);
    Route::post('listings/fee-quote', [SellerListingController::class, 'feeQuote'])->middleware('auth.optional');
    Route::get('payment-methods', [PaymentController::class, 'methods'])->middleware('auth.optional');
    Route::get('pages/{slug}', [SupportController::class, 'page']);
    Route::get('help/summary', [SupportController::class, 'helpSummary']);
    Route::get('support/contact-info', [SupportController::class, 'contactInfo']);
    Route::post('contact', [SupportController::class, 'contact'])->middleware(['auth.optional', 'throttle:contact']);
    Route::post('newsletter', [SupportController::class, 'newsletter'])->middleware('throttle:contact');
    Route::post('newsletter/confirm', [SupportController::class, 'confirmNewsletter'])->middleware('throttle:contact');

    Route::middleware('auth.optional')->group(function () {
        Route::get('listings', [ListingController::class, 'index'])->middleware('throttle:search');
        Route::get('listings/{slug}', [ListingController::class, 'show']);
        Route::get('listings/{slug}/related', [ListingController::class, 'related']);
        Route::get('sellers', [SellerController::class, 'index']);
        Route::get('sellers/{username}', [SellerController::class, 'show']);
        Route::get('sellers/{username}/listings', [SellerController::class, 'listings']);
        Route::get('sellers/{username}/reviews', [SellerController::class, 'reviews']);
    });

    // ------------------------------------------------------------------ Control Tower
    Route::prefix('admin')->middleware(['auth:sanctum', 'account', 'staff', 'throttle:admin'])->group(base_path('routes/admin.php'));

    // ------------------------------------------------------------------ signed-in member area
    Route::middleware(['auth:sanctum', 'account'])->group(function () {

        Route::prefix('me')->group(function () {
            Route::patch('/', [ProfileController::class, 'update']);
            Route::delete('/', [ProfileController::class, 'destroy']);
            Route::post('deactivate', [ProfileController::class, 'deactivate']);

            Route::get('security', [SecurityController::class, 'show']);
            Route::put('password', [SecurityController::class, 'changePassword']);
            Route::delete('sessions/{id}', [SecurityController::class, 'revokeSession']);
            Route::post('sessions/logout-others', [SecurityController::class, 'logoutOthers']);
            Route::delete('social/{provider}', [SecurityController::class, 'unlinkSocial']);
            Route::post('two-factor', [SecurityController::class, 'beginTwoFactor']);
            Route::post('two-factor/confirm', [SecurityController::class, 'confirmTwoFactor']);
            Route::delete('two-factor', [SecurityController::class, 'disableTwoFactor']);
            Route::post('two-factor/backup-codes', [SecurityController::class, 'regenerateBackupCodes']);

            Route::get('notification-preferences', [NotificationPreferenceController::class, 'show']);
            Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

            Route::get('fit-kit', [FitKitController::class, 'index']);
            Route::post('fit-kit', [FitKitController::class, 'store']);
            Route::patch('fit-kit/{id}', [FitKitController::class, 'update']);
            Route::delete('fit-kit/{id}', [FitKitController::class, 'destroy']);

            Route::get('addresses', [AddressController::class, 'index']);
            Route::post('addresses', [AddressController::class, 'store']);
            Route::patch('addresses/{id}', [AddressController::class, 'update']);
            Route::delete('addresses/{id}', [AddressController::class, 'destroy']);

            Route::get('favorites', [FavoriteController::class, 'index']);
            Route::get('listings', [SellerListingController::class, 'index']);
            Route::get('listings/{id}', [SellerListingController::class, 'show']);

            Route::get('kyc', [VerificationController::class, 'show']);
            Route::post('kyc/personal', [VerificationController::class, 'personal'])->middleware('throttle:verification');
            Route::post('kyc/document', [VerificationController::class, 'document'])->middleware('throttle:verification');
            Route::post('kyc/submit', [VerificationController::class, 'submit'])->middleware('throttle:verification');
            Route::get('aviator', [VerificationController::class, 'aviator']);
            Route::post('aviator', [VerificationController::class, 'applyAviator'])->middleware('throttle:verification');

            Route::get('hangar', HangarController::class);
            Route::get('disputes', [DisputeController::class, 'index']);

            Route::get('offers', [OfferController::class, 'index']);
            Route::get('swap-requests', [SwapController::class, 'index']);
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('reviews', [ReviewController::class, 'index']);

            Route::get('wallet', [WalletController::class, 'show']);
            Route::get('wallet/transactions', [WalletController::class, 'transactions']);
            Route::post('wallet/statements', [WalletController::class, 'statement']);
            Route::get('payout-methods', [WalletController::class, 'payoutMethods']);
            Route::post('payout-methods', [WalletController::class, 'addPayoutMethod'])->middleware('perm:payouts.request');
            Route::delete('payout-methods/{id}', [WalletController::class, 'removePayoutMethod']);
            Route::get('payout-schedule', [WalletController::class, 'schedule']);
            Route::put('payout-schedule', [WalletController::class, 'updateSchedule']);
            Route::post('withdrawals/quote', [WalletController::class, 'quote'])->middleware('perm:payouts.request');
            Route::post('withdrawals', [WalletController::class, 'withdraw'])->middleware(['perm:payouts.request', 'idempotent', 'throttle:payments']);

            Route::get('devices', [DeviceController::class, 'index']);
            Route::post('devices', [DeviceController::class, 'store']);
            Route::delete('devices/{id}', [DeviceController::class, 'destroy']);
        });

        // Media
        Route::post('media', [MediaController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('media/{id}', [MediaController::class, 'destroy']);

        // Sellers & favourites
        Route::post('sellers/{username}/follow', [SellerController::class, 'follow']);
        Route::delete('sellers/{username}/follow', [SellerController::class, 'unfollow']);
        Route::post('listings/{id}/favorite', [FavoriteController::class, 'store']);
        Route::delete('listings/{id}/favorite', [FavoriteController::class, 'destroy']);

        // Offers & swaps
        Route::post('listings/{id}/offers', [OfferController::class, 'store'])->middleware(['perm:offers.create', 'throttle:offers']);
        Route::post('offers/{id}/accept', [OfferController::class, 'accept']);
        Route::post('offers/{id}/decline', [OfferController::class, 'decline']);
        Route::post('offers/{id}/counter', [OfferController::class, 'counter']);
        Route::post('offers/{id}/cancel', [OfferController::class, 'cancel']);
        Route::post('listings/{id}/swap-requests', [SwapController::class, 'store'])->middleware('perm:swaps.create,offers.create');
        Route::post('swap-requests/{id}/accept', [SwapController::class, 'accept']);
        Route::post('swap-requests/{id}/reject', [SwapController::class, 'reject']);
        Route::post('swap-requests/{id}/cancel', [SwapController::class, 'cancel']);

        // Cart, checkout, orders
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart/items', [CartController::class, 'store'])->middleware('perm:orders.create');
        Route::delete('cart/items/{id}', [CartController::class, 'destroy']);
        Route::post('checkout/quote', [CartController::class, 'quote'])->middleware('perm:orders.create');
        Route::post('orders', [OrderController::class, 'store'])->middleware(['perm:orders.create', 'idempotent']);
        Route::get('orders/{id}', [OrderController::class, 'show']);
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{id}/confirm-delivery', [OrderController::class, 'confirmDelivery']);
        Route::post('orders/{id}/dispatch', [OrderController::class, 'dispatchOrder']);
        Route::post('orders/{id}/tracking', [OrderController::class, 'tracking']);
        Route::get('orders/{id}/invoice', [OrderController::class, 'invoice']);
        Route::post('orders/{order}/downloads/{asset}/authorize', [OrderController::class, 'authorizeDownload']);
        Route::post('orders/{id}/reviews', [ReviewController::class, 'store'])->middleware('perm:reviews.create');

        // Disputes
        Route::post('disputes', [DisputeController::class, 'store'])->middleware('perm:disputes.create');
        Route::get('disputes/{id}', [DisputeController::class, 'show']);
        Route::post('disputes/{id}/messages', [DisputeController::class, 'message']);
        Route::post('disputes/{id}/proposal', [DisputeController::class, 'propose']);
        Route::post('disputes/{id}/withdraw', [DisputeController::class, 'withdraw']);
        Route::post('disputes/{id}/proposal/accept', [DisputeController::class, 'acceptProposal']);
        Route::post('disputes/{id}/proposal/decline', [DisputeController::class, 'declineProposal']);

        // Messaging
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store'])->middleware('perm:messages.send');
        Route::get('conversations/{id}', [ConversationController::class, 'show']);
        Route::get('conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{id}/messages', [ConversationController::class, 'send'])->middleware(['perm:messages.send', 'throttle:messages']);
        Route::post('conversations/{id}/read', [ConversationController::class, 'read']);
        Route::post('conversations/{id}/report', [ConversationController::class, 'report']);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Payments
        Route::post('payments/initialize', [PaymentController::class, 'initialize'])->middleware(['perm:orders.create|listings.promote', 'idempotent', 'throttle:payments']);
        Route::get('payments/{reference}', [PaymentController::class, 'show']);
        Route::post('sandbox/payments/{reference}/{outcome}', [PaymentController::class, 'sandbox'])->whereIn('outcome', ['approve', 'decline', 'cancel']);

        // Selling
        Route::post('listings/price-check', [SellerListingController::class, 'priceCheck']);
        Route::post('listings', [SellerListingController::class, 'store'])->middleware('perm:listings.create');
        Route::post('listings/bulk', [SellerListingController::class, 'bulk'])->middleware('perm:listings.update');
        Route::post('listings/imports', [SellerListingController::class, 'import'])->middleware(['perm:listings.create', 'throttle:uploads']);
        Route::patch('listings/{id}', [SellerListingController::class, 'update'])->middleware('perm:listings.update');
        Route::delete('listings/{id}', [SellerListingController::class, 'destroy'])->middleware('perm:listings.update');
        Route::post('listings/{id}/publish', [SellerListingController::class, 'publish'])->middleware('perm:listings.create');
        Route::post('listings/{id}/reserve', [SellerListingController::class, 'reserve']);
        Route::post('listings/{id}/promote', [SellerListingController::class, 'promote'])->middleware('perm:listings.promote');
        Route::post('listings/{id}/{action}', [SellerListingController::class, 'transition'])
            ->whereIn('action', ['pause', 'resume', 'archive', 'mark-sold', 'mark-unavailable', 'relist', 'resubmit'])
            ->middleware('perm:listings.update');
    });
});
