<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Trading;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Listing;
use App\Services\Orders\CartService;
use App\Services\Orders\CheckoutService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->cart->show($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['listing_id' => ['required', 'string', 'max:40'], 'size' => ['nullable', 'string', 'max:20']]);
        $listing = Listing::query()->with('category')->whereKey($data['listing_id'])->first() ?? throw ApiException::notFound('Listing not found.');

        $this->cart->add($request->user(), $listing, $data['size'] ?? null);

        return ApiResponse::ok($this->cart->show($request->user()), 'Added to your cart.');
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        CartItem::query()->where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->whereKey($id)->orWhere('listing_id', $id))->delete();

        return ApiResponse::ok($this->cart->show($request->user()), 'Removed from your cart.');
    }

    /** `POST /checkout/quote` — pure calculation, no side effects. */
    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(self::checkoutRules());

        return ApiResponse::ok($checkout->quote($request->user(), $data)->toArray());
    }

    public static function checkoutRules(): array
    {
        return [
            'listing_id' => ['required_without:cart_item_ids', 'nullable', 'string', 'max:40'],
            'cart_item_ids' => ['nullable', 'array', 'max:50'],
            'cart_item_ids.*' => ['string', 'max:40'],
            'size' => ['nullable', 'string', 'max:20'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'offer_id' => ['nullable', 'string', 'max:40'],
            'address_id' => ['nullable', 'string', 'max:40'],
            'shipping_option_id' => ['nullable', 'string', 'max:40'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
