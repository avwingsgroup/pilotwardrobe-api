<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FitKitProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FitKitProfile */
final class FitKitProfileResource extends JsonResource
{
    public const DEFAULT_PREFERENCES = [
        'filter_search_by_size' => false,
        'warn_outside_measurements' => true,
        'use_at_checkout' => false,
    ];

    public function toArray(Request $request): array
    {
        $person = trim((string) ($this->for_person ?: $this->name));
        $words = preg_split('/\s+/', $person) ?: [];
        $owner = $request->user();
        $initials = match (true) {
            count($words) >= 2 => mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)),
            $owner !== null && $owner->id === $this->user_id && mb_strtolower($person) === mb_strtolower($owner->first_name) => $owner->initials(),
            default => mb_strtoupper(mb_substr($person, 0, 2)),
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'for_person' => $this->for_person,
            'initials' => $initials,
            'is_default' => (bool) $this->is_default,
            'unit' => $this->unit,
            'jacket' => $this->jacket,
            'collar' => $this->collar,
            'sleeve' => $this->sleeve,
            'waist' => $this->waist,
            'inseam' => $this->inseam,
            'shoe' => $this->shoe,
            'bag' => $this->bag,
            'apparel' => $this->apparel,
            'preferences' => array_merge(self::DEFAULT_PREFERENCES, $this->preferences ?? []),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'orders_used_on' => (int) $this->orders_used_on,
        ];
    }
}
