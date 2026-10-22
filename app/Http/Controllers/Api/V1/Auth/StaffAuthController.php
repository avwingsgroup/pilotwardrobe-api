<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthUserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionManager;
use App\Services\Auth\StaffAuthenticator;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Control Tower sign-in (AD1): Google Workspace SSO, then a WebAuthn security key. */
final class StaffAuthController extends Controller
{
    public function __construct(
        private readonly StaffAuthenticator $authenticator,
        private readonly SessionManager $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /** `POST /admin/auth/google` */
    public function google(Request $request): JsonResponse
    {
        $data = $request->validate(['id_token' => ['required', 'string', 'max:4096']]);
        $identity = $this->authenticator->verifyGoogleIdToken($data['id_token']);

        $staff = User::query()->whereRaw('lower(email) = ?', [$identity['email']])->first();
        if ($staff === null || ! $staff->isStaff() || $staff->status !== UserStatus::Active) {
            $this->audit->log('STAFF.SIGN_IN_DENIED', $identity['email'], 'No active staff account', 'danger');

            throw ApiException::forbidden('You do not have Control Tower access.');
        }

        return ApiResponse::ok($this->authenticator->beginSecondFactor($staff));
    }

    /** `POST /admin/auth/webauthn` */
    public function webauthn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'max:64'],
            'credential' => ['required', 'array'],
        ]);

        $staff = $this->authenticator->completeSecondFactor($data['challenge_id'], $data['credential']);
        if ($staff->status !== UserStatus::Active) {
            throw ApiException::forbidden('You do not have Control Tower access.');
        }

        $tokenFields = $this->sessions->start($staff, $request, false, [
            'staff' => true,
            'ttl_minutes' => 12 * 60,
        ]);
        $this->audit->log('STAFF.SIGNED_IN', $staff, 'Google Workspace + security key', 'info', actor: $staff);

        return ApiResponse::ok(array_merge(['status' => 'authenticated', 'user' => new AuthUserResource($staff)], $tokenFields));
    }
}
