<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trading;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Order;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use App\Support\Http\ApiResponse;
use App\Support\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReviewController extends Controller
{
    /** `GET /me/reviews?direction=received|given` */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $direction = $request->query('direction');

        $query = Review::query()->with('author')
            ->when($direction === 'given', fn ($q) => $q->where('author_id', $user->id))
            ->when($direction === 'received', fn ($q) => $q->where('subject_id', $user->id))
            ->when(! in_array($direction, ['given', 'received'], true), fn ($q) => $q->where(fn ($w) => $w->where('subject_id', $user->id)->orWhere('author_id', $user->id)))
            ->latest();

        return ApiResponse::paginated($query->paginate(QueryParams::perPage($request))->withQueryString(), ReviewResource::class);
    }

    /** `POST /orders/{id}/reviews` */
    public function store(Request $request, string $id, ReviewService $reviews): Response
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array', 'max:4'],
            'tags.*' => ['string', 'max:40'],
        ], ['rating.required' => 'Choose a star rating.']);

        $order = Order::query()->whereKey($id)->first();
        if ($order === null || ! $order->isParty($request->user())) {
            throw ApiException::notFound('Order not found.');
        }

        $reviews->create($order, $request->user(), $data);

        return ApiResponse::noContent();
    }
}
