<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Offer;
use App\Models\OfferEvent;
use App\Services\Pricing\CommissionService;
use App\Services\Trading\OfferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Offer */
final class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Offer $offer */
        $offer = $this->resource;
        $viewer = $request->user();
        $isSeller = $viewer->id === $offer->seller_id;
        $listing = $offer->listing;
        $counterparty = $isSeller ? $offer->buyer : $offer->seller;
        $asking = (int) $listing->price_amount;

        $out = [
            'id' => $offer->id,
            'kind' => 'offer',
            'direction' => $viewer->id === $offer->proposer_id ? 'sent' : 'received',
            'status' => $offer->status->value,
            'listing' => ListingResource::snippet($listing, withMinimumOffer: $isSeller),
            'amount' => $offer->money()->toArray(),
            'below_asking_percent' => $asking > 0 ? round((1 - $offer->amount / $asking) * 100, 1) : null,
            'message' => $offer->message,
            'size' => $offer->size,
            'counterparty' => SellerSummaryResource::counterparty($counterparty),
            'expires_at' => $offer->expires_at->toIso8601ZuluString(),
            'created_at' => $offer->created_at?->toIso8601ZuluString(),
            'is_counter_offer' => $offer->isCounterOffer(),
            'parent_offer_id' => $offer->parent_offer_id,
            'history' => $offer->events->map(fn (OfferEvent $e) => [
                'id' => $e->id,
                'label' => $e->label,
                'detail' => $e->detail,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'kind' => $e->kind,
            ])->values()->all(),
            'unread' => $viewer->id === $offer->recipient_id && $offer->recipient_read_at === null,
            'allowed_actions' => OfferService::allowedActions($offer, $viewer),
        ];

        if ($offer->counter_amount !== null) {
            $out['counter_amount'] = $offer->counterMoney()->toArray();
        }
        if ($isSeller) {
            $commission = app(CommissionService::class)->saleCommission($offer->money(), $listing, $offer->seller);
            $out['you_receive'] = $offer->money()->minus($commission['fee'])->toArray();
        }

        return $out;
    }
}
