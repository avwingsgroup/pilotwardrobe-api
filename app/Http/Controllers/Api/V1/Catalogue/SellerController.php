<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\ListingStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingViewContext;
use App\Http\Resources\MediaAssetPresenter;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\SellerSummaryResource;
use App\Models\Listing;
use App\Models\Review;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Public seller storefronts (V7, VB18, VB19) and the directory. */
final class SellerController extends Controller
{
    /** `GET /sellers?type=photographer|official|aviator&search=` */
    public function index(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', '');

        $query = $this->publicSellers()
            ->with(['kyc', 'avatar', 'aviatorCredential'])
            ->when($type === 'photographer' || $type === 'photographers', fn (Builder $q) => $q->where('seller_type', 'photographer'))
            ->when($type === 'official', fn (Builder $q) => $q->where('seller_type', 'official_store'))
            ->when($type === 'aviator' || $type === 'aviators', fn (Builder $q) => $q->where('role', UserRole::Aviator->value))
            ->when(! in_array($type, ['photographer', 'photographers', 'official'], true), fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('seller_type', ['official_store', 'photographer'])
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('listings')->whereColumn('listings.seller_id', 'users.id')
                    ->whereIn('listings.status', ['active', 'reserved'])->whereNull('listings.deleted_at'))))
            ->when(QueryParams::search($request), fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                ->where('username', 'ilike', '%'.addcslashes($s, '%_\\').'%')
                ->orWhereRaw("(first_name || ' ' || last_name) ilike ?", ['%'.addcslashes($s, '%_\\').'%'])))
            ->orderByRaw("(seller_type = 'official_store') desc")
            ->orderByDesc('sales_count')->orderBy('id');

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), fn (User $u) => SellerSummaryResource::fields($u));
    }

    /** `GET /sellers/{username}` */
    public function show(Request $request, string $username): JsonResponse
    {
        $seller = $this->find($username);
        $viewer = $request->user();

        $dispatch = DB::table('orders')->where('seller_id', $seller->id)->whereNotNull('dispatched_at')
            ->selectRaw('count(*) as total, count(*) filter (where dispatched_at <= dispatch_due_at) as on_time')->first();

        return ApiResponse::ok([
            ...SellerSummaryResource::fields($seller),
            'bio' => $seller->bio,
            'cover_image' => MediaAssetPresenter::make($seller->cover, $seller->displayName()),
            'job_title' => $seller->job_title,
            'aircraft_type' => $seller->aircraft_type,
            'member_since' => $seller->created_at?->toIso8601ZuluString(),
            'response_time' => $seller->response_time,
            'response_rate' => $seller->response_rate,
            'followers' => (int) $seller->followers_count,
            'stats' => [
                'trades' => (int) $seller->sales_count,
                'on_time_dispatch_rate' => ($dispatch->total ?? 0) > 0 ? (int) round($dispatch->on_time / $dispatch->total * 100) : null,
                'listings_count' => Listing::query()->where('seller_id', $seller->id)->whereIn('status', ['active', 'reserved'])->count(),
                'sold_count' => (int) $seller->sales_count,
                'reviews_count' => (int) $seller->reviews_count,
            ],
            // array_replace keeps the star keys (array_merge would renumber them into a JSON list).
            'rating_breakdown' => array_replace(['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0], array_map('intval', $seller->rating_breakdown ?? [])),
            'is_following' => $viewer !== null && DB::table('follows')->where('follower_id', $viewer->id)->where('seller_id', $seller->id)->exists(),
        ]);
    }

    /** `GET /sellers/{username}/listings` */
    public function listings(Request $request, string $username, ListingViewContext $context): JsonResponse
    {
        $seller = $this->find($username);
        $page = Listing::query()->where('seller_id', $seller->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, ListingStatus::publiclyVisible()))
            ->with(['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'])
            ->orderByRaw('(promoted_until > now()) desc nulls last')->orderByDesc('published_at')
            ->paginate(QueryParams::perPage($request))->withQueryString();
        $context->preload($page->items(), $request->user());

        return ApiResponse::paginated($page, ListingResource::class);
    }

    /** `GET /sellers/{username}/reviews` */
    public function reviews(Request $request, string $username): JsonResponse
    {
        $seller = $this->find($username);
        $page = Review::query()->where('subject_id', $seller->id)->where('is_hidden', false)
            ->with('author')->latest()->paginate(QueryParams::perPage($request))->withQueryString();

        return ApiResponse::paginated($page, ReviewResource::class);
    }

    /** `POST /sellers/{username}/follow` */
    public function follow(Request $request, string $username, NotificationService $notifications): JsonResponse
    {
        $seller = $this->find($username);
        $user = $request->user();
        if ($seller->id === $user->id) {
            throw ApiException::validation(['username' => 'You cannot follow yourself.']);
        }

        $followers = DB::transaction(function () use ($seller, $user) {
            $inserted = DB::table('follows')->insertOrIgnore(['follower_id' => $user->id, 'seller_id' => $seller->id, 'created_at' => now()]);
            if ($inserted) {
                $seller->increment('followers_count');
            }

            return [(int) $seller->fresh()->followers_count, $inserted > 0];
        });

        if ($followers[1]) {
            $notifications->notify($seller, 'seller.new_follower', ['follower' => $user->shortName()], "seller.new_follower:{$seller->id}:{$user->id}");
        }

        return ApiResponse::ok(['followers' => $followers[0], 'is_following' => true]);
    }

    /** `DELETE /sellers/{username}/follow` */
    public function unfollow(Request $request, string $username): JsonResponse
    {
        $seller = $this->find($username);

        $followers = DB::transaction(function () use ($seller, $request) {
            $deleted = DB::table('follows')->where('follower_id', $request->user()->id)->where('seller_id', $seller->id)->delete();
            if ($deleted) {
                DB::table('users')->where('id', $seller->id)->where('followers_count', '>', 0)->decrement('followers_count');
            }

            return (int) $seller->fresh()->followers_count;
        });

        return ApiResponse::ok(['followers' => $followers, 'is_following' => false]);
    }

    /** @return Builder<User> */
    private function publicSellers(): Builder
    {
        return User::query()->whereIn('role', [UserRole::User->value, UserRole::Aviator->value])
            ->whereIn('status', [UserStatus::Active->value, UserStatus::Restricted->value]);
    }

    private function find(string $username): User
    {
        return $this->publicSellers()->with(['kyc', 'avatar', 'cover', 'aviatorCredential'])
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])->first()
            ?? throw ApiException::notFound('Seller not found.');
    }
}
