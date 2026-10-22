<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Disputes;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DisputeResource;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\Disputes\DisputeService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DisputeController extends Controller
{
    public const WITH = ['order.items.image', 'buyer', 'seller', 'mediator', 'messages.author', 'messages.evidence', 'evidence', 'latestProposal', 'proposals'];

    public function __construct(private readonly DisputeService $disputes) {}

    /** `GET /me/disputes` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Dispute::query()->with(self::WITH)
            ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->when($request->query('status'), fn ($q) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), DisputeResource::class);
    }

    /** `GET /disputes/{id}` — parties only (staff use /admin/disputes). */
    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new DisputeResource($this->find($request, $id)));
    }

    /** `POST /disputes` */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string', 'max:40'],
            'reason' => ['required', Rule::in(array_keys(Dispute::REASONS))],
            'requested' => ['required', Rule::in(Dispute::REQUESTS)],
            'amount' => ['nullable', 'integer', 'min:1'],
            'description' => ['required', 'string', 'min:20', 'max:3000'],
            'evidence_ids' => ['nullable', 'array', 'max:10'],
            'evidence_ids.*' => ['string', 'max:40'],
        ], [
            'reason.required' => 'Tell us what went wrong.',
            'reason.in' => 'Tell us what went wrong.',
            'description.required' => 'Describe the issue in at least 20 characters.',
            'description.min' => 'Describe the issue in at least 20 characters.',
        ]);

        $order = Order::query()->whereKey($data['order_id'])->first();
        if ($order === null || ! $order->isBuyer($request->user())) {
            throw ApiException::notFound('Order not found.');
        }

        $dispute = $this->disputes->open($request->user(), $order, $data);

        return ApiResponse::created(new DisputeResource($dispute->fresh(self::WITH)), 'Dispute opened. Escrow is frozen while we look into it.');
    }

    /** `POST /disputes/{id}/messages` */
    public function message(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
            'evidence_ids' => ['nullable', 'array', 'max:10'],
            'evidence_ids.*' => ['string', 'max:40'],
        ], ['body.required' => 'Write a message first.']);

        $dispute = $this->disputes->addMessage($this->find($request, $id), $request->user(), $data['body'], $data['evidence_ids'] ?? []);

        return ApiResponse::ok(new DisputeResource($dispute->fresh(self::WITH)));
    }

    /** `POST /disputes/{id}/proposal` (addition) — seller settlement offer. */
    public function propose(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['partial_refund', 'return_and_refund', 'full_refund'])],
            'amount' => ['required', 'integer', 'min:1'],
            'summary' => ['nullable', 'string', 'max:500'],
        ]);

        $dispute = $this->disputes->propose($this->find($request, $id), $request->user(), $data['kind'], (int) $data['amount'], $data['summary'] ?? null);

        return ApiResponse::ok(new DisputeResource($dispute->fresh(self::WITH)), 'Proposal sent to the buyer.');
    }

    public function withdraw(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new DisputeResource($this->disputes->withdraw($this->find($request, $id), $request->user())->fresh(self::WITH)), 'Dispute withdrawn.');
    }

    public function acceptProposal(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new DisputeResource($this->disputes->acceptProposal($this->find($request, $id), $request->user())->fresh(self::WITH)), 'Settlement accepted.');
    }

    public function declineProposal(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new DisputeResource($this->disputes->declineProposal($this->find($request, $id), $request->user())->fresh(self::WITH)), 'A mediator will review the case.');
    }

    private function find(Request $request, string $id): Dispute
    {
        $dispute = Dispute::query()->with(self::WITH)->whereKey($id)->first();
        if ($dispute === null || ! $dispute->isParty($request->user())) {
            throw ApiException::notFound('Dispute not found.');
        }

        return $dispute;
    }
}
