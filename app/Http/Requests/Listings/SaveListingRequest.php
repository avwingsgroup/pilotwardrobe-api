<?php

declare(strict_types=1);

namespace App\Http\Requests\Listings;

use App\Enums\ListingCondition;
use App\Enums\ListingFormat;
use App\Http\Requests\ApiRequest;
use App\Support\Money\Currency;
use Illuminate\Validation\Rule;

/**
 * Create (`POST /listings`) and autosave (`PATCH /listings/{id}`). Drafts may be partial;
 * completeness is enforced at publish time. Money uses `{ amount, currency }` objects
 * for listing drafts (handoff §10).
 */
final class SaveListingRequest extends ApiRequest
{
    public function rules(): array
    {
        $money = fn (string $key) => [
            $key => ['nullable', 'array'],
            "{$key}.amount" => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            "{$key}.currency" => ['nullable', Rule::in(Currency::codes())],
        ];

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:140'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'model' => ['sometimes', 'nullable', 'string', 'max:80'],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:30'],
            'attributes.*' => ['nullable'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'year_purchased' => ['sometimes', 'nullable', 'digits:4'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:15'],
            'tags.*' => ['string', 'max:30'],
            'aircraft_types' => ['sometimes', 'nullable', 'array', 'max:20'],
            'aircraft_types.*' => ['string', 'max:20'],
            'condition' => ['sometimes', 'nullable', Rule::in(ListingCondition::values())],
            'format' => ['sometimes', Rule::in(ListingFormat::values())],
            ...$money('price'),
            ...$money('compare_at_price'),
            ...$money('minimum_offer'),
            'currency' => ['sometimes', Rule::in(Currency::codes())],
            'negotiable' => ['sometimes', 'boolean'],
            'swappable' => ['sometimes', 'boolean'],
            'auto_decline_below_minimum' => ['sometimes', 'boolean'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'media_ids' => ['sometimes', 'array', 'max:12'],
            'media_ids.*' => ['string', 'max:40'],
            'download_media_ids' => ['sometimes', 'array', 'max:5'],
            'download_media_ids.*' => ['string', 'max:40'],
            'sizes' => ['sometimes', 'nullable', 'array'],
            'sizes.available' => ['nullable', 'array', 'max:40'],
            'sizes.available.*' => ['string', 'max:20'],
            'sizes.unavailable' => ['nullable', 'array', 'max:40'],
            'sizes.unavailable.*' => ['string', 'max:20'],
            'location' => ['sometimes', 'nullable', 'array'],
            'location.country_code' => ['nullable', 'string', 'size:2'],
            'location.state' => ['nullable', 'string', 'max:80'],
            'location.city' => ['nullable', 'string', 'max:80'],
            'location.airport_code' => ['nullable', 'string', 'max:4'],
            'freight' => ['sometimes', 'nullable', 'array'],
            'freight.method' => ['nullable', Rule::in(['pilotwardrobe_freight', 'self', 'handoff'])],
            'freight.who_pays' => ['nullable', Rule::in(['buyer', 'seller'])],
            'freight.parcel_size' => ['nullable', 'string', 'max:40'],
            'freight.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'freight.allow_handoff' => ['nullable', 'boolean'],
            'freight.ships_from' => ['nullable', 'array'],
            'freight.price' => ['nullable', 'array'],
            'freight.price.amount' => ['nullable', 'integer', 'min:0'],
            'shipping' => ['sometimes', 'nullable', 'array', 'max:5'],
            'shipping.*.method' => ['required_with:shipping', 'string', 'max:30'],
            'shipping.*.label' => ['nullable', 'string', 'max:60'],
            'shipping.*.description' => ['nullable', 'string', 'max:120'],
            'shipping.*.price.amount' => ['nullable', 'integer', 'min:0'],
            'shipping.*.estimated_days.min' => ['nullable', 'integer', 'min:0', 'max:90'],
            'shipping.*.estimated_days.max' => ['nullable', 'integer', 'min:0', 'max:90'],
            'licensing' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
