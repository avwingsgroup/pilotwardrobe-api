<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Exceptions\ApiException;
use App\Http\Resources\MediaAssetPresenter;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\User;
use App\Services\Catalogue\FitMatcher;
use App\Support\Money\Money;

/** Cart (VB17). Availability is re-checked on every read. */
final class CartService
{
    private const MAX_ITEMS = 50;

    public function __construct(private readonly FitMatcher $fit) {}

    public function add(User $user, Listing $listing, ?string $size): void
    {
        if ($listing->seller_id === $user->id) {
            throw ApiException::conflict("You can't add your own listing to your cart.");
        }
        if (! $listing->isPurchasableBy($user)) {
            throw ApiException::conflict('This item is no longer available.');
        }

        $sizes = $listing->availableSizes();
        $fromFitKit = false;
        if ($sizes !== null) {
            if ($size === null) {
                $size = $this->fit->match($listing, $user)['size'] ?? null;
                $fromFitKit = $size !== null;
            }
            if ($size === null || ! in_array($size, $sizes, true)) {
                throw ApiException::validation(['size' => 'Choose an available size.']);
            }
            $fromFitKit = $fromFitKit || ($this->fit->match($listing, $user)['size'] ?? null) === $size;
        } else {
            $size = null;
        }

        if (CartItem::query()->where('user_id', $user->id)->count() >= self::MAX_ITEMS
            && ! CartItem::query()->where('user_id', $user->id)->where('listing_id', $listing->id)->exists()) {
            throw ApiException::conflict('Your cart is full.');
        }

        CartItem::query()->updateOrCreate(
            ['user_id' => $user->id, 'listing_id' => $listing->id],
            ['size' => $size, 'size_from_fit_kit' => $fromFitKit, 'quantity' => 1],
        );
    }

    public function show(User $user): array
    {
        $items = CartItem::query()->where('user_id', $user->id)
            ->with(['listing' => fn ($q) => $q->withTrashed()->with(['seller.kyc', 'images'])])
            ->latest()->get();

        $currency = $items->first()?->listing->currency ?? $user->currency;
        $subtotal = Money::zero($currency);
        $rows = [];

        foreach ($items as $item) {
            $listing = $item->listing;
            $available = $listing !== null && ! $listing->trashed() && $listing->isPurchasableBy($user);
            if ($available && $listing->currency === $currency) {
                $subtotal = $subtotal->plus($listing->price()->multiply($item->quantity));
            }
            $rows[] = [
                'id' => $item->id,
                'listing_id' => $item->listing_id,
                'listing_slug' => $listing?->slug,
                'title' => $listing->title ?? 'Item no longer available',
                'image' => $listing ? MediaAssetPresenter::make($listing->images->first(), $listing->title) : null,
                'condition' => $listing?->condition?->value,
                'size' => $item->size,
                'size_from_fit_kit' => (bool) $item->size_from_fit_kit,
                'quantity' => $item->quantity,
                'price' => $listing?->price()?->toArray(),
                'seller' => $listing ? [
                    'id' => $listing->seller->id,
                    'display_name' => $listing->seller->displayName(),
                    'username' => $listing->seller->username,
                    'verified' => $listing->seller->isKycApproved() || $listing->seller->isOfficialStore(),
                ] : null,
                'available' => $available,
            ];
        }

        return ['id' => 'cart_'.$user->id, 'items' => $rows, 'subtotal' => $subtotal->toArray(), 'currency' => $currency];
    }
}
