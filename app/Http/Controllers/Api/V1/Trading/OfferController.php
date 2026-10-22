<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trading;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Listing;
use App\Models\Offer;
use App\Services\Trading\OfferService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OfferController extends Controller
{
    private const WITH = ['listing.images', 'listing.seller', 'buyer.kyc', 'seller.kyc', 'events'];

    public function __construct(private readonly OfferService $offers) {}

    /** `POST /listings/{id}/offers` */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'max:100000000000'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'message' => ['nullable', 'string', 'max:500'],
            'size' => ['nullable', 'string', 'max:20'],
        ], ['amount.required' => 'Enter your offer amount.']);

        $listing = Listing::query()->whereKey($id)->first() ?? throw ApiException::notFound('Listing not found.');
        $offer = $this->offers->make($request->user(), $listing, (int) $data['amount'], strtoupper($data['currency']), $data['message'] ?? null, $data['size'] ?? null);

        return ApiResponse::created(new OfferResource($offer->load(self::WITH)), 'Offer sent. The seller has 24h to respond.');
    }

    /** `GET /me/offers?box=received|sent&status=` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $box = $request->query('box', 'received');

        $query = Offer::query()->with(self::WITH)
            ->when($box === 'sent', fn ($q) => $q->where('proposer_id', $user->id), fn ($q) => $q->where('recipient_id', $user->id))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->orderByRaw("(status = 'pending') desc")->latest();

        $page = $query->paginate(QueryParams::perPage($request))->withQueryString();

        // Opening the inbox marks received offers as seen.
        if ($box !== 'sent') {
            Offer::query()->whereIn('id', collect($page->items())->pluck('id'))->whereNull('recipient_read_at')->update(['recipient_read_at' => now()]);
        }

        return ApiResponse::paginated($page, OfferResource::class);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new OfferResource($this->offers->accept($this->find($request, $id), $request->user())->load(self::WITH)), 'Offer accepted. The item is reserved for the buyer.');
    }

    public function decline(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return ApiResponse::ok(new OfferResource($this->offers->decline($this->find($request, $id), $request->user(), $data['reason'] ?? null)->load(self::WITH)));
    }

    public function counter(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $offer = $this->find($request, $id);
        if (isset($data['currency']) && strtoupper($data['currency']) !== $offer->currency) {
            throw ApiException::validation(['currency' => "Counter in {$offer->currency}."]);
        }

        return ApiResponse::ok(new OfferResource($this->offers->counter($offer, $request->user(), (int) $data['amount'], $data['message'] ?? null)->load(self::WITH)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new OfferResource($this->offers->cancel($this->find($request, $id), $request->user())->load(self::WITH)));
    }

    private function find(Request $request, string $id): Offer
    {
        $offer = Offer::query()->whereKey($id)->first();
        if ($offer === null || ! $offer->isParty($request->user())) {
            throw ApiException::notFound('Offer not found.');
        }

        return $offer;
    }
}
