<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Actions\Account\DeleteAccount;
use App\Enums\MediaType;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\UpdateProfileRequest;
use App\Http\Resources\AuthUserResource;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Auth\UsernameGenerator;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class ProfileController extends Controller
{
    /** `PATCH /me` */
    public function update(UpdateProfileRequest $request, MediaService $media, UsernameGenerator $usernames, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if (isset($data['username']) && mb_strtolower($data['username']) !== mb_strtolower($user->username)) {
            if (! $usernames->isAvailable($data['username'])) {
                throw ApiException::validation(['username' => 'That username is taken.']);
            }
            $data['username'] = mb_strtolower($data['username']);
        }

        DB::transaction(function () use ($user, $data, $media, $audit) {
            foreach (['avatar_media_id' => MediaType::Avatar, 'cover_media_id' => MediaType::Cover] as $field => $type) {
                if (array_key_exists($field, $data) && $data[$field] !== $user->{$field}) {
                    $asset = $media->claimOne($user, $data[$field], $type, $field, $user);
                    $asset?->attachTo($user, $type->value);
                }
            }

            if (isset($data['country_code'])) {
                $data['country_code'] = strtoupper($data['country_code']);
            }

            $before = $user->only(array_keys($data));
            $user->fill($data)->save();
            $audit->log('USER.PROFILE_UPDATED', $user, implode(', ', array_keys($data)), before: $before, after: $user->only(array_keys($data)), actor: $user);
        });

        return ApiResponse::ok(new AuthUserResource($user->fresh()), 'Profile updated.');
    }

    /** `POST /me/deactivate` — hides listings until the next sign-in. */
    public function deactivate(Request $request, SessionManager $sessions, NotificationService $notifications, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user, $audit) {
            $user->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();
            $audit->log('USER.DEACTIVATED', $user, 'Self-service', 'warning', actor: $user);
        });

        $notifications->notify($user, 'account.deactivated', [], 'account.deactivated:'.$user->id.':'.now()->timestamp);
        $sessions->endCurrent($request);
        $sessions->revokeAll($user);

        return ApiResponse::ok(['status' => 'deactivated'], 'Your account is deactivated. Sign in again any time to reactivate it.');
    }

    /** `DELETE /me` */
    public function destroy(Request $request, DeleteAccount $delete, SessionManager $sessions): Response
    {
        $user = $request->user();
        $delete->assertDeletable($user);
        $sessions->endCurrent($request);
        $delete->handle($user);

        return ApiResponse::noContent();
    }
}
