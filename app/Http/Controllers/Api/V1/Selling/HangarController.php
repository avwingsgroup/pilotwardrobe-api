<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Selling;

use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaAssetPresenter;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Review;
use App\Models\Wallet;
use App\Support\Http\ApiResponse;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** `GET /me/hangar` — one aggregate for the seller dashboard (V5) instead of N requests. */
final class HangarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $currency = Wallet::query()->where('user_id', $user->id)->orderByDesc('available_amount')->value('currency') ?? $user->currency;
        $wallet = Wallet::query()->where('user_id', $user->id)->where('currency', $currency)->first();
        $money = fn (int $a) => Money::of($a, $currency)->toArray();

        $available = (int) ($wallet->available_amount ?? 0);
        $escrow = (int) ($wallet->escrow_amount ?? 0);
        $pending = (int) ($wallet->pending_release_amount ?? 0);
        $weekDelta = (int) DB::table('ledger_entries')->where('user_id', $user->id)->where('currency', $currency)
            ->where('type', 'sale')->where('occurred_at', '>=', now()->subWeek())->sum('amount');

        $toDispatch = Order::query()->where('seller_id', $user->id)->whereIn('status', [OrderStatus::Paid->value, OrderStatus::AwaitingDispatch->value])->get(['id', 'dispatch_due_at']);
        $openOffers = Offer::query()->with('listing')->where('recipient_id', $user->id)->where('status', 'pending')->orderByDesc('amount')->get();
        $liveCount = Listing::query()->where('seller_id', $user->id)->whereIn('status', ['active', 'reserved'])->count();
        $needPhotos = Listing::query()->where('seller_id', $user->id)->whereIn('status', ['active', 'draft'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('media')->whereColumn('media.attachable_id', 'listings.id')->where('media.collection', 'images')->whereNull('media.deleted_at'))
            ->count();

        $attention = [];
        if ($toDispatch->isNotEmpty()) {
            $attention[] = ['id' => 'att_dispatch', 'tone' => 'warning', 'title' => $toDispatch->count().' '.str('order')->plural($toDispatch->count()).' awaiting wheels-up',
                'detail' => 'Dispatch by '.optional($toDispatch->min('dispatch_due_at'))->format('D j M').' to keep your on-time rate', 'to' => '/hangar/orders?role=selling&status=to_dispatch'];
        }
        if ($openOffers->isNotEmpty()) {
            $top = $openOffers->first();
            $attention[] = ['id' => 'att_offers', 'tone' => 'info', 'title' => $openOffers->count().' open '.str('offer')->plural($openOffers->count()),
                'detail' => 'Highest: '.$top->money()->format().' on '.$top->listing->title.' — expires '.$top->expires_at->diffForHumans(), 'to' => '/hangar/offers'];
        }
        if (! $user->isKycApproved()) {
            $attention[] = ['id' => 'att_kyc', 'tone' => 'info', 'title' => 'Verify your identity', 'detail' => 'Unlock swaps, unlimited listings and withdrawals', 'to' => '/hangar/verification'];
        }

        $feed = [];
        foreach (Listing::query()->where('seller_id', $user->id)->with('images')->latest('updated_at')->limit(4)->get() as $l) {
            $feed[] = [
                'id' => 'f-'.$l->id, 'kind' => 'listing', 'title' => $l->title,
                'subtitle' => 'Listed '.($l->published_at ?? $l->created_at)->format('M j').' · '.$l->views_count.' views · '.$l->offers_count.' offers',
                'image' => MediaAssetPresenter::make($l->images->first(), $l->title),
                'price' => $l->price()?->toArray(),
                'status' => ['label' => $l->status->label(), 'tone' => $l->status->tone()],
                'bucket' => $l->status === ListingStatus::Active ? 'live' : $l->status->value,
                'next_action' => null,
                'to' => '/hangar/listings/'.$l->id.'/edit',
            ];
        }
        foreach (Order::query()->where('seller_id', $user->id)->with(['items.image', 'buyer'])->whereIn('status', OrderStatus::open())->latest()->limit(4)->get() as $o) {
            $item = $o->items->first();
            $feed[] = [
                'id' => 'f-'.$o->id, 'kind' => 'order', 'title' => $item?->title,
                'subtitle' => 'Order #'.$o->number.' · buyer '.$o->buyer->shortName(),
                'image' => MediaAssetPresenter::make($item?->image, $item?->title),
                'price' => $o->total()->toArray(),
                'status' => ['label' => $o->status->label(), 'tone' => $o->status->tone()],
                'bucket' => $o->status->value,
                'next_action' => $o->status === OrderStatus::AwaitingDispatch ? 'Dispatch by '.$o->dispatch_due_at?->format('D j M') : null,
                'to' => '/hangar/orders/'.$o->id,
            ];
        }

        // Rating as it stood 30 days ago, for the trend. Always a number (the SPA formats it): without
        // older reviews there is no change to show, so it equals the current rating.
        $sellerRating = round((float) $user->rating_avg, 1);
        $olderAverage = Review::query()->where('subject_id', $user->id)->where('is_hidden', false)
            ->where('created_at', '<', now()->subDays(30))->avg('rating');
        $previousRating = $olderAverage !== null ? round((float) $olderAverage, 1) : $sellerRating;

        return ApiResponse::ok([
            'seller_since' => $user->created_at?->toIso8601ZuluString(),
            'verified_seller' => $user->isKycApproved(),
            'stats' => [
                'in_escrow' => $money($escrow),
                'escrow_orders' => Order::query()->where('seller_id', $user->id)->whereIn('escrow_status', ['held', 'frozen'])->count(),
                'available_to_pay_out' => $money($available),
                'pending_release' => $money($pending),
                'week_delta' => $money($weekDelta),
                'live_listings' => $liveCount,
                'listings_need_photos' => $needPhotos,
                'seller_rating' => $sellerRating,
                'previous_rating' => $previousRating,
                'reviews_count' => (int) $user->reviews_count,
            ],
            'wallet' => ['total' => $money($available + $escrow + $pending), 'available' => $money($available), 'in_escrow' => $money($escrow)],
            'attention' => $attention,
            'feed' => $feed,
        ]);
    }
}
