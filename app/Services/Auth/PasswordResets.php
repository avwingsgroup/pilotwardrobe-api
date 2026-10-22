<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password-reset links (VA9–VA12). Tokens are 64 random characters stored as SHA-256
 * so a token can be looked up without knowing the email (the SPA checks a token
 * before showing the form), and are valid for 60 minutes, single use.
 */
final class PasswordResets
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function sendLink(User $user): void
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => mb_strtolower($user->email)],
            ['token' => hash('sha256', $token), 'created_at' => now()],
        );

        $this->notifications->notify($user, 'auth.password_reset', [
            'token' => $token,
            'email' => $user->email,
        ], 'auth.password_reset:'.hash('sha256', $token));
    }

    /** Email for a valid, unexpired token; null otherwise. */
    public function emailFor(string $token): ?string
    {
        $row = DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->first();
        if ($row === null) {
            return null;
        }
        $created = Carbon::parse($row->created_at);
        if ($created->lt(now()->subMinutes((int) config('platform.auth.password_reset_ttl_minutes')))) {
            return null;
        }

        return $row->email;
    }

    public function consume(string $token): void
    {
        DB::table('password_reset_tokens')->where('token', hash('sha256', $token))->delete();
    }
}
