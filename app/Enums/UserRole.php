<?php

declare(strict_types=1);

namespace App\Enums;

/** Primary account role (§2). `visitor` is implicit for signed-out requests and never stored. */
enum UserRole: string
{
    case User = 'user';
    case Aviator = 'aviator';
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::SuperAdmin;
    }

    public function isMember(): bool
    {
        return $this === self::User || $this === self::Aviator;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
