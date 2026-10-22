<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trading;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Integrations\Media\MediaStorage;
use App\Models\DownloadGrant;
use App\Models\Media;
use App\Models\Order;
use App\Services\Orders\InvoiceRenderer;
use App\Services\Orders\OrderService;
use App\Services\Reviews\ReviewService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Buyer/seller order endpoints (V4, VB6–VB9). Reads are limited to the two parties. */
final class OrderController extends Controller
{
    public const WITH = ['items.image', 'seller.kyc', 'seller.avatar', 'buyer', 'events', 'successfulPayment', 'latestPayment', 'disputes', 'reviews'];

    private const TABS = [
        'active' => ['pending_payment', 'paid', 'awaiting_dispatch', 'in_transit', 'out_for_delivery', 'delivered', 'on_hold'],
        'awaiting_payment' => ['pending_payment'],
        'to_dispatch' => ['paid', 'awaiting_dispatch'],
        'in_transit' => ['in_transit', 'out_for_delivery'],
        'delivered' => ['delivered'],
        'completed' => ['completed'],
        'disputed' => ['disputed'],
        'cancelled' => ['cancelled', 'refunded', 'partially_refunded'],
    ];

    public function __construct(private readonly OrderService $orders) {}

    /** `GET /me/orders?role=buying|selling&status=` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role', 'buying');
        $status = (string) $request->query('status', '');

        $query = Order::query()->with(self::WITH)
            ->when(in_array($role, ['selling', 'seller'], true), fn ($q) => $q->where('seller_id', $user->id), fn ($q) => $q->where('buyer_id', $user->id))
            ->when(isset(self::TABS[$status]), fn ($q) => $q->whereIn('status', self::TABS[$status]))
            ->when(! isset(self::TABS[$status]) && $status !== '' && $status !== 'all', fn ($q) => $q->whereIn('status', QueryParams::list($request, 'status')))
            ->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), OrderResource::class);
    }

    /** `GET /orders/{id}` */
    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok(new OrderResource($this->findForParty($request, $id)));
    }

    /** `POST /orders` */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(CartController::checkoutRules());
        $order = $this->orders->create($request->user(), $data);

        return ApiResponse::created(new OrderResource($order->fresh(self::WITH)), 'Order created. Complete payment to secure it.');
    }

    /** `POST /orders/{id}/cancel` */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $order = $this->findForParty($request, $id);
        $reason = $data['reason'] ?? ($order->isBuyer($request->user()) ? 'Cancelled by buyer' : 'Cancelled by seller');

        return ApiResponse::ok(new OrderResource($this->orders->cancel($order, $request->user(), $reason)->load(self::WITH)), 'Order cancelled.');
    }

    /** `POST /orders/{id}/confirm-delivery` — optionally with a review. */
    public function confirmDelivery(Request $request, string $id, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate([
            'review' => ['nullable', 'array'],
            'review.rating' => ['required_with:review', 'integer', 'between:1,5'],
            'review.comment' => ['nullable', 'string', 'max:1000'],
            'review.tags' => ['nullable', 'array', 'max:4'],
        ]);
        $order = $this->findForParty($request, $id);
        if (! $order->isBuyer($request->user())) {
            throw ApiException::forbidden('Only the buyer can confirm delivery.');
        }

        $order = $this->orders->confirmDelivery($order, $request->user());
        if (! empty($data['review'])) {
            $reviews->create($order, $request->user(), $data['review']);
        }

        return ApiResponse::ok(new OrderResource($order->fresh(self::WITH)), 'Thanks! Escrow has been released to the seller.');
    }

    /** `POST /orders/{id}/dispatch` */
    public function dispatchOrder(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'carrier' => ['required', 'string', 'max:120'],
            'tracking_number' => ['required', 'string', 'max:120'],
            'tracking_url' => ['nullable', 'url:https', 'max:500'],
        ], ['carrier.required' => 'Choose the carrier.', 'tracking_number.required' => 'Add the tracking number.']);

        $order = $this->findForParty($request, $id);
        if (! $order->isSeller($request->user())) {
            throw ApiException::forbidden('Only the seller can mark an order dispatched.');
        }

        return ApiResponse::ok(new OrderResource($this->orders->dispatch($order, $request->user(), $data)->load(self::WITH)), 'Marked as dispatched.');
    }

    /** `POST /orders/{id}/tracking` (addition) — seller reports out-for-delivery / delivered. */
    public function tracking(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:out_for_delivery,delivered'], 'detail' => ['nullable', 'string', 'max:255']]);
        $order = $this->findForParty($request, $id);
        if (! $order->isSeller($request->user())) {
            throw ApiException::forbidden('Only the seller can update tracking.');
        }

        return ApiResponse::ok(new OrderResource($this->orders->updateTracking($order, $request->user(), $data['status'], $data['detail'] ?? null)->load(self::WITH)));
    }

    /** `GET /orders/{id}/invoice` — PDF. */
    public function invoice(Request $request, string $id, InvoiceRenderer $renderer): Response
    {
        $order = $this->findForParty($request, $id);
        if ($order->paid_at === null) {
            throw ApiException::conflict('An invoice is available once the order is paid.');
        }

        return response($renderer->render($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="pilotwardrobe-'.$order->number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** `POST /orders/{order}/downloads/{asset}/authorize` — short-lived signed URL for purchased digital files. */
    public function authorizeDownload(Request $request, string $orderId, string $assetId, MediaStorage $storage): JsonResponse
    {
        $order = $this->findForParty($request, $orderId);
        if (! $order->isBuyer($request->user())) {
            throw ApiException::forbidden('Only the buyer can download this file.');
        }
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed], true) || $order->paid_at === null) {
            throw ApiException::forbidden('Downloads are available once the order is paid.');
        }

        $media = Media::query()->whereKey($assetId)->where('attachable_type', 'listing')
            ->whereIn('attachable_id', $order->items->pluck('listing_id'))->where('collection', 'downloads')->first();
        if ($media === null) {
            throw ApiException::notFound('File not found.');
        }

        $ttl = 300;
        DownloadGrant::query()->create(['order_id' => $order->id, 'media_id' => $media->id, 'user_id' => $request->user()->id,
            'ip_address' => $request->ip(), 'expires_at' => now()->addSeconds($ttl)]);

        return ApiResponse::ok([
            'url' => $storage->temporaryUrl($media, $ttl),
            'expires_at' => now()->addSeconds($ttl)->toIso8601ZuluString(),
            'filename' => $media->original_filename,
        ]);
    }

    /** Parties only; staff use the admin endpoints. Others get 404 (no IDOR oracle). */
    private function findForParty(Request $request, string $id): Order
    {
        $order = Order::query()->with(self::WITH)->whereKey($id)->first();
        if ($order === null || ! $order->isParty($request->user())) {
            throw ApiException::notFound('Order not found.');
        }

        return $order;
    }
}
