<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Http\Resources\MediaAssetPresenter;
use App\Models\Address;
use App\Models\Listing;
use App\Models\Offer;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Platform\PlatformSettings;
use App\Support\Money\Money;

/**
 * A fully server-computed price breakdown. `POST /checkout/quote` renders it; `POST /orders`
 * recomputes it inside the order transaction and persists exactly these numbers.
 */
final class CheckoutQuote
{
    /**
     * @param  list<array{listing: Listing, quantity: int, size: ?string, size_from_fit_kit: bool, unit_price: Money, cart_item_id: ?string}>  $lines
     * @param  list<array<string, mixed>>  $shippingOptions
     */
    public function __construct(
        public readonly User $buyer,
        public readonly User $seller,
        public readonly array $lines,
        public readonly array $shippingOptions,
        public readonly ?array $selectedShipping,
        public readonly ?Address $address,
        public readonly Money $items,
        public readonly Money $freight,
        public readonly Money $buyerProtection,
        public readonly ?Money $duties,
        public readonly Money $discount,
        public readonly Money $total,
        public readonly int $platformFeeBps,
        public readonly Money $platformFee,
        public readonly Money $sellerNet,
        public readonly ?Offer $offer,
        public readonly ?PromoCode $promo,
    ) {}

    public function toArray(): array
    {
        $sla = (int) app(PlatformSettings::class)->dispatchSlaDays();
        $days = $this->selectedShipping['estimated_days'] ?? null;

        return [
            'items' => array_map(fn (array $line) => [
                'id' => 'ci_'.$line['listing']->id,
                'listing_id' => $line['listing']->id,
                'listing_slug' => $line['listing']->slug,
                'title' => $line['listing']->title,
                'image' => MediaAssetPresenter::make($line['listing']->images->first(), $line['listing']->title),
                'condition' => $line['listing']->condition?->value,
                'size' => $line['size'],
                'size_from_fit_kit' => $line['size_from_fit_kit'],
                'quantity' => $line['quantity'],
                'price' => $line['unit_price']->toArray(),
                'seller' => [
                    'id' => $this->seller->id,
                    'display_name' => $this->seller->displayName(),
                    'username' => $this->seller->username,
                    'verified' => $this->seller->isKycApproved() || $this->seller->isOfficialStore(),
                ],
                'available' => true,
            ], $this->lines),
            'shipping_options' => array_map(fn (array $o) => self::shippingOption($o, $this->items->currency->value), $this->shippingOptions),
            'selected_shipping_id' => $this->selectedShipping['id'] ?? null,
            'totals' => [
                'items' => $this->items->toArray(),
                'freight' => $this->freight->toArray(),
                'buyer_protection' => $this->buyerProtection->toArray(),
                'duties' => $this->duties?->toArray(),
                'discount' => $this->discount->isZero() ? null : $this->discount->toArray(),
                'total' => $this->total->toArray(),
            ],
            'promo_code' => $this->promo?->code,
            'offer_id' => $this->offer?->id,
            'dispatch_note' => $this->selectedShipping === null ? null : sprintf(
                'Seller ships within %d days%s.', $sla,
                $days ? sprintf(' — estimated arrival in %d–%d days', ($days['min'] ?? 0) + 1, ($days['max'] ?? 0) + $sla) : '',
            ),
            'escrow_note' => sprintf('Funds are held in escrow until you confirm arrival — or released automatically %dh after landing.',
                app(PlatformSettings::class)->autoReleaseHours()),
        ];
    }

    public static function shippingOption(array $option, string $currency): array
    {
        return [
            'id' => $option['id'],
            'method' => $option['method'],
            'label' => $option['label'],
            'description' => $option['description'] ?? null,
            'price' => Money::of((int) ($option['price_amount'] ?? 0), $currency)->toArray(),
            'estimated_days' => $option['estimated_days'] ?? null,
        ];
    }
}
