<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\ListingFormat;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Address;
use App\Models\CartItem;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\User;
use App\Services\Catalogue\FitMatcher;
use App\Services\Platform\PlatformSettings;
use App\Services\Pricing\CommissionService;
use App\Services\Pricing\PromoCodes;
use App\Support\Money\Money;

/**
 * Computes checkout totals entirely server-side (V4). The client never sends prices;
 * it sends what it wants to buy, where to, how fast, and an optional promo code.
 *
 * One order = one seller. A cart spanning several sellers is checked out per seller
 * (`cart_item_ids` must all belong to the same seller).
 */
final class CheckoutService
{
    public function __construct(
        private readonly CommissionService $commission,
        private readonly PromoCodes $promoCodes,
        private readonly PlatformSettings $settings,
        private readonly FitMatcher $fit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  listing_id|cart_item_ids, size, quantity, offer_id, address_id, shipping_option_id, promo_code
     * @param  bool  $lock  lock listing rows (order creation)
     */
    public function quote(User $buyer, array $input, bool $lock = false): CheckoutQuote
    {
        [$lines, $offer] = $this->resolveLines($buyer, $input, $lock);
        $first = $lines[0]['listing'];
        $seller = $first->seller;
        $currency = $first->currency;

        foreach ($lines as $line) {
            if ($line['listing']->seller_id !== $seller->id) {
                throw ApiException::validation(['cart_item_ids' => 'Check out items from one seller at a time.']);
            }
            if ($line['listing']->currency !== $currency) {
                throw ApiException::validation(['cart_item_ids' => 'Items priced in different currencies must be checked out separately.']);
            }
        }

        $needsShipping = collect($lines)->contains(fn ($l) => $l['listing']->format !== ListingFormat::Digital);
        $shippingOptions = $needsShipping ? ($first->shipping ?? []) : [];
        $selected = null;
        $address = null;

        if ($needsShipping) {
            if ($shippingOptions === []) {
                throw ApiException::conflict('This seller has not set up delivery for the item yet.');
            }
            $selected = collect($shippingOptions)->firstWhere('id', $input['shipping_option_id'] ?? null) ?? $shippingOptions[0];
            if (! empty($input['address_id'])) {
                $address = Address::query()->where('user_id', $buyer->id)->whereKey($input['address_id'])->first()
                    ?? throw ApiException::validation(['address_id' => 'Choose one of your saved addresses.']);
            }
        }

        $items = Money::zero($currency);
        foreach ($lines as $line) {
            $items = $items->plus($line['unit_price']->multiply($line['quantity']));
        }

        $freight = Money::of((int) ($selected['price_amount'] ?? 0), $currency);
        $protection = $this->commission->buyerProtection($items)['fee'];
        $duties = $this->duties($first, $address, $items);

        $discount = Money::zero($currency);
        $promo = null;
        if (! empty($input['promo_code'])) {
            ['promo' => $promo, 'discount' => $discount] = $this->promoCodes->apply((string) $input['promo_code'], $buyer, $items);
        }

        $total = $items->plus($freight)->plus($protection)->plus($duties ?? Money::zero($currency))->minus($discount);

        // Seller proceeds: item value less commission, plus freight when the seller ships it themselves.
        $commission = $this->commission->saleCommission($items, $first, $seller);
        $sellerFreight = in_array($selected['method'] ?? null, ['self'], true) ? $freight : Money::zero($currency);
        $sellerNet = $items->minus($commission['fee'])->plus($sellerFreight);

        return new CheckoutQuote(
            buyer: $buyer,
            seller: $seller,
            lines: $lines,
            shippingOptions: $shippingOptions,
            selectedShipping: $selected,
            address: $address,
            items: $items,
            freight: $freight,
            buyerProtection: $protection,
            duties: $duties,
            discount: $discount,
            total: $total,
            platformFeeBps: $commission['rate_bps'],
            platformFee: $commission['fee'],
            sellerNet: $sellerNet,
            offer: $offer,
            promo: $promo,
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: ?Offer} */
    private function resolveLines(User $buyer, array $input, bool $lock): array
    {
        $offer = null;
        $requests = [];

        if (! empty($input['cart_item_ids'])) {
            $cartItems = CartItem::query()->where('user_id', $buyer->id)->whereIn('id', $input['cart_item_ids'])->get();
            if ($cartItems->count() !== count(array_unique($input['cart_item_ids']))) {
                throw ApiException::validation(['cart_item_ids' => 'Some items are no longer in your cart.']);
            }
            foreach ($cartItems as $item) {
                $requests[] = ['listing_id' => $item->listing_id, 'size' => $item->size, 'quantity' => $item->quantity, 'size_from_fit_kit' => $item->size_from_fit_kit, 'cart_item_id' => $item->id];
            }
        } elseif (! empty($input['listing_id'])) {
            $requests[] = ['listing_id' => $input['listing_id'], 'size' => $input['size'] ?? null, 'quantity' => (int) ($input['quantity'] ?? 1), 'size_from_fit_kit' => null, 'cart_item_id' => null];
        } else {
            throw ApiException::validation(['listing_id' => 'Choose an item to buy.']);
        }

        if (! empty($input['offer_id'])) {
            $offer = Offer::query()->whereKey($input['offer_id'])->first();
            $valid = $offer !== null && $offer->status === OfferStatus::Accepted && $offer->buyer_id === $buyer->id
                && count($requests) === 1 && $offer->listing_id === $requests[0]['listing_id']
                && $offer->expires_at->isFuture();
            if (! $valid) {
                throw ApiException::validation(['offer_id' => 'This offer is no longer available for checkout.']);
            }
        }

        $lines = [];
        foreach ($requests as $request) {
            $query = Listing::query()->with(['seller.kyc', 'images', 'category']);
            $listing = ($lock ? $query->lockForUpdate() : $query)->find($request['listing_id']);
            if ($listing === null) {
                throw ApiException::notFound('Listing not found.');
            }
            if ($listing->seller_id === $buyer->id) {
                throw ApiException::conflict("You can't buy your own listing.");
            }
            if (! $listing->isPurchasableBy($buyer) || ! $listing->seller->canTrade()) {
                throw ApiException::conflict('Sorry — '.$listing->title.' is no longer available.');
            }

            $quantity = max(1, (int) $request['quantity']);
            if ($quantity > $listing->availableQuantity()) {
                throw ApiException::conflict('Only '.$listing->availableQuantity().' left of '.$listing->title.'.');
            }

            $size = $request['size'];
            $sizes = $listing->availableSizes();
            $fromFitKit = (bool) $request['size_from_fit_kit'];
            if ($sizes !== null) {
                if ($size === null) {
                    $match = $this->fit->match($listing, $buyer);
                    $size = $match['size'] ?? null;
                    $fromFitKit = $match !== null;
                }
                if ($size === null || ! in_array($size, $sizes, true)) {
                    throw ApiException::validation(['size' => 'Choose an available size.']);
                }
                $fromFitKit = $fromFitKit || ($this->fit->match($listing, $buyer)['size'] ?? null) === $size;
            } else {
                $size = null;
            }

            $unit = $offer !== null ? $offer->money() : $listing->price();
            if ($unit === null || $unit->amount <= 0) {
                throw ApiException::conflict($listing->title.' has no price yet.');
            }

            $lines[] = [
                'listing' => $listing,
                'quantity' => $quantity,
                'size' => $size,
                'size_from_fit_kit' => $fromFitKit,
                'unit_price' => $unit,
                'cart_item_id' => $request['cart_item_id'],
            ];
        }

        return [$lines, $offer];
    }

    private function duties(Listing $listing, ?Address $address, Money $items): ?Money
    {
        $origin = $listing->location['country_code'] ?? $listing->seller->country_code;
        if ($address === null || $origin === null || strtoupper($origin) === strtoupper($address->country_code)) {
            return null;
        }

        $bps = (int) ($this->settings->get('duties', [])[strtoupper($address->country_code)] ?? 0);

        return $bps > 0 ? $items->percentage($bps) : null;
    }
}
