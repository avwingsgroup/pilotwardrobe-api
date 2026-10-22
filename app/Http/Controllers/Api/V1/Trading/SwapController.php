<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trading;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SwapResource;
use App\Models\Listing;
use App\Models\SwapRequest;
use App\Services\Trading\SwapService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use App\Support\Money\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SwapController extends Controller
{
    private const WITH = ['targetListing.images', 'items.listing.images', 'proposer.kyc', 'counterparty.kyc', 'events', 'orders'];

    public function __construct(private readonly SwapService $swaps) {}

    /** `POST /listings/{id}/swap-requests` */
    public function store(Request $request, string $id): JsonResponse
    {
        // KYC is checked before validation so unverified users get KYC_REQUIRED, not field errors.
        if (! $request->user()->isKycApproved()) {
            throw ApiException::kycRequired('Verify your identity to propose swaps.');
        }

        $data = $request->validate([
            'offered_listing_ids' => ['required', 'array', 'min:1', 'max:'.config('platform.swaps.max_offered_items')],
            'offered_listing_ids.*' => ['string', 'max:40'],
            'cash_adjustment' => ['nullable', 'array'],
            'cash_adjustment.amount' => ['nullable', 'integer', 'between:-100000000,100000000'],
            'cash_adjustment.currency' => ['nullable', Rule::in(Currency::codes())],
            'message' => ['nullable', 'string', 'max:500'],
        ], ['offered_listing_ids.required' => 'Choose at least one of your items to offer.']);

        $target = Listing::query()->whereKey($id)->first() ?? throw ApiException::notFound('Listing not found.');
        $swap = $this->swaps->propose(
            $request->user(),
            $target,
            array_values($data['offered_listing_ids']),
            (int) ($data['cash_adjustment']['amount'] ?? 0),
            strtoupper($data['cash_adjustment']['currency'] ?? $target->currency),
            $data['message'] ?? null,
        );

        return ApiResponse::created(new SwapResource($swap->load(self::WITH)), 'Swap proposal sent.');
    }

    /** `GET /me/swap-requests?box=received|sent` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $box = $request->query('box');

        $query = SwapRequest::query()->with(self::WITH)
            ->when($box === 'sent', fn ($q) => $q->where('proposer_id', $user->id))
            ->when($box === 'received', fn ($q) => $q->where('counterparty_id', $user->id))
            ->when(! in_array($box, ['sent', 'received'], true), fn ($q) => $q->where(fn ($w) => $w->where('proposer_id', $user->id)->orWhere('counterparty_id', $user->id)))
            ->when($request->query('status'), fn ($q) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->orderByRaw("(status = 'pending') desc")->latest();

        $page = $query->paginate(QueryParams::perPage($request))->withQueryString();
        SwapRequest::query()->whereIn('id', collect($page->items())->pluck('id'))->where('counterparty_id', $user->id)
            ->whereNull('counterparty_read_at')->update(['counterparty_read_at' => now()]);

        return ApiResponse::paginated($page, SwapResource::class);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new SwapResource($this->swaps->accept($this->find($request, $id), $request->user())->load(self::WITH)), 'Swap accepted. Both sides now pay their leg.');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new SwapResource($this->swaps->reject($this->find($request, $id), $request->user())->load(self::WITH)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new SwapResource($this->swaps->cancel($this->find($request, $id), $request->user())->load(self::WITH)));
    }

    private function find(Request $request, string $id): SwapRequest
    {
        $swap = SwapRequest::query()->whereKey($id)->first();
        if ($swap === null || ! $swap->isParty($request->user())) {
            throw ApiException::notFound('Swap not found.');
        }

        return $swap;
    }
}
