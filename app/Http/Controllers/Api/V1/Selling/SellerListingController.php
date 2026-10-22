<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Selling;

use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listings\SaveListingRequest;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ListingViewContext;
use App\Models\BoostPackage;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingPromotion;
use App\Models\User;
use App\Services\Catalogue\ListingImporter;
use App\Services\Catalogue\ListingService;
use App\Services\Catalogue\PriceCheck;
use App\Services\Pricing\CommissionService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Seller listing management (V6 wizard, VB4 table). Every mutation is scoped to the
 * caller's own listings: someone else's listing id is indistinguishable from a missing one (404).
 */
final class SellerListingController extends Controller
{
    private const WITH = ['seller.kyc', 'seller.avatar', 'seller.aviatorCredential', 'category.parent', 'images'];

    /** Tab → statuses for `GET /me/listings?status=` and `meta.counts`. */
    private const TABS = [
        'live' => ['active', 'reserved', 'pending_review'],
        'active' => ['active', 'reserved'],
        'drafts' => ['draft'],
        'draft' => ['draft'],
        'paused' => ['paused', 'unavailable'],
        'sold' => ['sold', 'swapped'],
        'needs_changes' => ['needs_changes'],
        'archived' => ['archived'],
        'removed' => ['removed', 'rejected'],
    ];

    public function __construct(private readonly ListingService $listings) {}

    /** `GET /me/listings` */
    public function index(Request $request, ListingViewContext $context): JsonResponse
    {
        $user = $request->user();
        $status = (string) $request->query('status', $request->query('tab', 'all'));

        $query = Listing::query()->where('seller_id', $user->id)->with(self::WITH)
            ->when(isset(self::TABS[$status]), fn ($q) => $q->whereIn('status', self::TABS[$status]))
            ->when(QueryParams::search($request), fn ($q, $s) => $q->where('title', 'ilike', '%'.addcslashes($s, '%_\\').'%'));
        QueryParams::applySort($query, $request, ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'price' => 'price_amount', 'views' => 'views_count'], '-updated_at');

        $page = $query->paginate(QueryParams::perPage($request))->withQueryString();
        $context->preload($page->items(), $user);

        $raw = Listing::query()->where('seller_id', $user->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => $raw[$s] ?? 0);

        return ApiResponse::paginated($page, ListingResource::class, ['counts' => [
            'all' => (int) $raw->sum(),
            'live' => $sum(self::TABS['live']),
            'drafts' => $sum(self::TABS['drafts']),
            'paused' => $sum(self::TABS['paused']),
            'needs_changes' => $sum(self::TABS['needs_changes']),
            'sold' => $sum(self::TABS['sold']),
            'archived' => $sum(self::TABS['archived']),
            'removed' => $sum(self::TABS['removed']),
        ]]);
    }

    /** `GET /me/listings/{id}` */
    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new ListingResource($this->own($request, $id)->load(self::WITH)));
    }

    /** `POST /listings` */
    public function store(SaveListingRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canList()) {
            throw ApiException::forbidden('Listing is restricted on your account. Contact support.');
        }

        $listing = $this->listings->createDraft($user, $request->validated());

        return ApiResponse::created(new ListingResource($listing->load(self::WITH)), 'Draft saved.');
    }

    /** `PATCH /listings/{id}` */
    public function update(SaveListingRequest $request, string $id): JsonResponse
    {
        $listing = $this->listings->update($this->own($request, $id), $request->user(), $request->validated());

        return ApiResponse::ok(new ListingResource($listing->load(self::WITH)), 'Saved.');
    }

    /** `POST /listings/{id}/publish` */
    public function publish(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        if (! $user->canList()) {
            throw ApiException::forbidden('Listing is restricted on your account. Contact support.');
        }

        $listing = $this->listings->publish($this->own($request, $id), $user);
        $message = $listing->status === ListingStatus::Active ? 'Your listing is live.' : 'Submitted for review. We usually review within a few hours.';

        return ApiResponse::ok(new ListingResource($listing->load(self::WITH)), $message);
    }

    /** `POST /listings/{id}/{pause|resume|archive|mark-sold|mark-unavailable|relist|resubmit}` */
    public function transition(Request $request, string $id, string $action): JsonResponse
    {
        $listing = $this->listings->transition($this->own($request, $id), $request->user(), $action);

        return ApiResponse::ok(new ListingResource($listing->load(self::WITH)));
    }

    /** `DELETE /listings/{id}` */
    public function destroy(Request $request, string $id): Response
    {
        $this->listings->delete($this->own($request, $id), $request->user());

        return ApiResponse::noContent();
    }

    /** `POST /listings/bulk` — pause/resume/archive/price. Items in the wrong state are skipped. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'max:40'],
            'action' => ['required', Rule::in(['pause', 'resume', 'archive', 'price'])],
            'price_change' => ['required_if:action,price', 'array'],
            'price_change.type' => ['required_if:action,price', Rule::in(['percent', 'amount', 'set'])],
            'price_change.value' => ['required_if:action,price', 'integer'],
        ]);

        $user = $request->user();
        $listings = Listing::query()->where('seller_id', $user->id)->whereIn('id', $data['ids'])->get();
        $updated = 0;
        $skipped = [];

        foreach ($listings as $listing) {
            try {
                if ($data['action'] === 'price') {
                    $this->listings->update($listing, $user, ['price' => ['amount' => $this->newPrice($listing, $data['price_change'])]]);
                } else {
                    $this->listings->transition($listing, $user, $data['action']);
                }
                $updated++;
            } catch (ApiException $e) {
                $skipped[] = ['id' => $listing->id, 'reason' => $e->getMessage()];
            }
        }

        return ApiResponse::ok(['updated' => $updated, 'skipped' => $skipped], $updated === 1 ? '1 listing updated.' : "{$updated} listings updated.");
    }

    /** `POST /listings/imports` — CSV (multipart `file`). */
    public function import(Request $request, ListingImporter $importer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        $result = $importer->import($request->user(), $request->file('file')->getRealPath());

        return ApiResponse::created($result, "{$result['created']} drafts created.");
    }

    /** `POST /listings/price-check` */
    public function priceCheck(Request $request, PriceCheck $priceCheck): JsonResponse
    {
        $data = $request->validate([
            'listing_id' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'string', 'max:80'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'condition' => ['nullable', 'string', 'max:20'],
            'price' => ['nullable', 'array'],
            'price.amount' => ['nullable', 'integer', 'min:0'],
            'price.currency' => ['nullable', Rule::in(Currency::codes())],
        ]);

        return ApiResponse::ok($priceCheck->suggest($data));
    }

    /** `POST /listings/fee-quote` (public) — commission and net payout for a price. */
    public function feeQuote(Request $request, CommissionService $commission): JsonResponse
    {
        $data = $request->validate([
            'price' => ['required', 'array'],
            'price.amount' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'price.currency' => ['required', Rule::in(Currency::codes())],
            'listing_id' => ['nullable', 'string', 'max:40'],
            'category_id' => ['nullable', 'string', 'max:80'],
        ]);

        $listing = null;
        if (! empty($data['listing_id']) && $request->user()) {
            $listing = Listing::query()->where('seller_id', $request->user()->id)->whereKey($data['listing_id'])->first();
        } elseif (! empty($data['category_id'])) {
            $listing = new Listing;
            $listing->category_id = Category::query()->where('id', $data['category_id'])->orWhere('slug', $data['category_id'])->value('id');
        }

        return ApiResponse::ok($commission->feeQuote(Money::of($data['price']['amount'], $data['price']['currency']), $listing, $request->user()));
    }

    /** `POST /listings/{id}/reserve` — seller reserves for the buyer in a conversation. */
    public function reserve(Request $request, string $id): Response
    {
        $data = $request->validate([
            'buyer_id' => ['required_without:conversation_id', 'nullable', 'string', 'max:40'],
            'conversation_id' => ['nullable', 'string', 'max:40'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:72'],
        ]);

        $listing = Listing::query()->whereKey($id)->first() ?? throw ApiException::notFound('Listing not found.');
        if ($listing->seller_id !== $request->user()->id) {
            throw ApiException::forbidden('Only the seller can reserve this listing.');
        }

        $buyerId = $data['buyer_id'] ?? Conversation::query()->whereKey($data['conversation_id'])
            ->where('seller_id', $request->user()->id)->where('listing_id', $listing->id)->value('buyer_id');
        $buyer = $buyerId ? User::query()->find($buyerId) : null;
        if ($buyer === null) {
            throw ApiException::validation(['buyer_id' => 'Choose the buyer to reserve for.']);
        }

        $this->listings->reserveFor($listing, $request->user(), $buyer, (int) ($data['hours'] ?? 24));

        return ApiResponse::noContent();
    }

    /** `POST /listings/{id}/promote` — creates a boost awaiting payment (pay via /payments/initialize with promotion_id). */
    public function promote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['package_id' => ['required', 'string', 'max:40']]);
        $listing = $this->own($request, $id);
        if ($listing->status !== ListingStatus::Active) {
            throw ApiException::invalidTransition('Only live listings can be promoted.');
        }

        $package = BoostPackage::query()->where('is_active', true)
            ->where(fn ($q) => $q->whereKey($data['package_id'])->orWhere('code', $data['package_id']))->first()
            ?? throw ApiException::validation(['package_id' => 'Choose a promotion package.']);

        $promotion = DB::transaction(function () use ($listing, $package, $request) {
            ListingPromotion::query()->where('listing_id', $listing->id)->where('status', 'pending_payment')->update(['status' => 'cancelled']);

            return ListingPromotion::query()->create([
                'listing_id' => $listing->id,
                'boost_package_id' => $package->id,
                'user_id' => $request->user()->id,
                'status' => 'pending_payment',
                'amount' => $package->price_amount,
                'currency' => $package->currency,
            ]);
        });

        return ApiResponse::ok([
            'promotion_id' => $promotion->id,
            'status' => $promotion->status,
            'package' => ['id' => $package->id, 'name' => $package->name, 'duration_days' => $package->duration_days],
            'amount' => $promotion->total()->toArray(),
            'payment_required' => true,
        ], 'Choose a payment method to start the boost.');
    }

    private function own(Request $request, string $id): Listing
    {
        return Listing::query()->where('seller_id', $request->user()->id)->whereKey($id)->first()
            ?? throw ApiException::notFound('Listing not found.');
    }

    private function newPrice(Listing $listing, array $change): int
    {
        $current = (int) $listing->price_amount;
        $new = match ($change['type']) {
            'percent' => $current + intdiv($current * (int) $change['value'], 100),
            'amount' => $current + (int) $change['value'],
            default => (int) $change['value'],
        };

        return max(1, $new);
    }
}
