<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SwapEvent;
use App\Models\SwapRequest;
use App\Services\Trading\SwapService;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SwapRequest */
final class SwapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SwapRequest $swap */
        $swap = $this->resource;
        $viewer = $request->user();
        $isCounterparty = $viewer->id === $swap->counterparty_id;

        return [
            'id' => $swap->id,
            'kind' => 'swap',
            'direction' => $viewer->id === $swap->proposer_id ? 'sent' : 'received',
            'status' => $swap->status->value,
            'target_listing' => ListingResource::snippet($swap->targetListing, withMinimumOffer: $isCounterparty),
            'offered_items' => $swap->items->map(fn ($item) => [
                'listing' => array_diff_key(ListingResource::snippet($item->listing), ['condition' => 1, 'status' => 1]),
                'estimated_value' => Money::of((int) $item->estimated_value, $swap->currency)->toArray(),
            ])->values()->all(),
            'cash_adjustment' => $swap->cashAdjustment()->toArray(),
            'swap_fee' => $swap->swapFee()->toArray(),
            'message' => $swap->message,
            'counterparty' => SellerSummaryResource::counterparty($isCounterparty ? $swap->proposer : $swap->counterparty),
            'expires_at' => $swap->expires_at->toIso8601ZuluString(),
            'created_at' => $swap->created_at?->toIso8601ZuluString(),
            'history' => $swap->events->map(fn (SwapEvent $e) => [
                'id' => $e->id,
                'label' => $e->label,
                'detail' => $e->detail,
                'occurred_at' => $e->occurred_at->toIso8601ZuluString(),
                'kind' => $e->kind,
            ])->values()->all(),
            'order_ids' => $swap->orders->where('buyer_id', $viewer->id)->pluck('id')->values()->all(),
            'unread' => $isCounterparty && $swap->counterparty_read_at === null,
            'allowed_actions' => SwapService::allowedActions($swap, $viewer),
        ];
    }
}
