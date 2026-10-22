<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AviatorStatus;
use App\Enums\KycStatus;
use App\Models\User;
use App\Services\Account\CountersService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session principal (`AuthUser`) — the SPA's single source of truth for identity,
 * role, effective permissions, account status and badge counters.
 *
 * @mixin User
 */
final class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $kyc = $user->kycStatus();

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->displayName(),
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'permissions' => $user->permissions(),
            'permissions_version' => $user->permissions_version,
            'staff_role' => $user->isStaff() ? ($user->isSuperAdmin() ? 'Super admin' : $user->staffRole?->name) : null,
            'avatar' => MediaAssetPresenter::make($user->avatar),
            'verification' => [
                'email' => $user->hasVerifiedEmail(),
                'identity' => $kyc === KycStatus::Approved,
                'aviator' => $user->aviatorCredential?->status === AviatorStatus::Approved,
                'kyc_status' => $kyc->value,
            ],
            'status' => $user->status->value,
            'country_code' => $user->country_code,
            'currency' => $user->currency,
            'locale' => $user->locale,
            'bio' => $user->bio,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'member_since' => $user->created_at?->toIso8601ZuluString(),
            'counters' => app(CountersService::class)->for($user),
        ];
    }
}
