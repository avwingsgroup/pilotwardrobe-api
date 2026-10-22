<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\OneTimeCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Short numeric codes (email OTP, SMS 2FA). Only hashes are stored; each code allows
 * 5 attempts and issuing a new one invalidates older ones for the same purpose.
 */
final class OneTimeCodes
{
    public const MAX_ATTEMPTS = 5;

    public function issue(User $user, string $purpose, int $length, int $ttlSeconds): string
    {
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $purpose, $code, $ttlSeconds) {
            OneTimeCode::query()->where('user_id', $user->id)->where('purpose', $purpose)->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            OneTimeCode::query()->create([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]);
        });

        return $code;
    }

    /** @return 'ok'|'invalid'|'expired' */
    public function verify(User $user, string $purpose, string $code): string
    {
        $record = OneTimeCode::query()->where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->lockForUpdate()->first();

        if ($record === null || $record->attempts >= self::MAX_ATTEMPTS) {
            return 'invalid';
        }
        if ($record->expires_at->isPast()) {
            return 'expired';
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            return 'invalid';
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return 'ok';
    }

    public function lastIssuedAt(User $user, string $purpose): ?Carbon
    {
        return OneTimeCode::query()->where('user_id', $user->id)->where('purpose', $purpose)->latest('id')->first()?->created_at;
    }
}
