<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

enum UserStatus: string
{
    use HasTransitions;

    case Active = 'active';
    case Restricted = 'restricted';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
    case PendingVerification = 'pending_verification';

    public static function transitions(): array
    {
        return [
            'active' => [self::Restricted, self::Suspended, self::Deactivated],
            'restricted' => [self::Active, self::Suspended, self::Deactivated],
            'suspended' => [self::Active],
            'deactivated' => [self::Active, self::Suspended],
            'pending_verification' => [self::Active, self::Suspended],
        ];
    }

    public function canSignIn(): bool
    {
        return $this !== self::Suspended;
    }
}
