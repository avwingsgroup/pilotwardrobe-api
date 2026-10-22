<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AviatorStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public seller card. Never includes private fields (email, phone, address, KYC data).
 *
 * @mixin User
 */
final class SellerSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return self::fields($user);
    }

    public static function fields(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->isOfficialStore() ? ($user->first_name.' '.$user->last_name) : $user->displayName(),
            'avatar' => MediaAssetPresenter::make($user->avatar),
            'verified' => $user->isKycApproved() || $user->isOfficialStore(),
            'is_aviator' => $user->isAviator() || $user->aviatorCredential?->status === AviatorStatus::Approved,
            'is_official_store' => $user->isOfficialStore(),
            'is_photographer' => $user->seller_type === 'photographer',
            'rating' => round((float) $user->rating_avg, 1),
            'total_reviews' => (int) $user->reviews_count,
            'total_sales' => (int) $user->sales_count,
            'location' => self::location($user),
            'dispatch_time' => $user->dispatch_time,
        ];
    }

    /** "Lagos, Nigeria". */
    public static function location(User $user): ?string
    {
        $country = $user->country_code ? (locale_get_display_region('-'.$user->country_code, 'en') ?: $user->country_code) : null;
        $parts = array_values(array_filter([$user->city, $country]));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** Compact counterparty block used by offers, swaps and messages. */
    public static function counterparty(User $user): array
    {
        return [
            'id' => $user->id,
            'display_name' => $user->isOfficialStore() ? $user->displayName() : $user->shortName(),
            'initials' => $user->initials(),
            'username' => $user->username,
            'avatar' => MediaAssetPresenter::make($user->avatar),
            'verified' => $user->isKycApproved() || $user->isOfficialStore(),
            'rating' => round((float) $user->rating_avg, 1),
            'trades' => (int) $user->sales_count,
            'location' => $user->city,
            'member_since' => $user->created_at?->toIso8601ZuluString(),
        ];
    }
}
