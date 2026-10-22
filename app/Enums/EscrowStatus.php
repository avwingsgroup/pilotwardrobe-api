<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\StateMachine\HasTransitions;

/** Handoff §8.6. */
enum EscrowStatus: string
{
    use HasTransitions;

    case NotFunded = 'not_funded';
    case Held = 'held';
    case Frozen = 'frozen';
    case OnHold = 'on_hold';
    case Releasing = 'releasing';
    case Released = 'released';
    case Refunded = 'refunded';

    public static function transitions(): array
    {
        return [
            'not_funded' => [self::Held],
            'held' => [self::Releasing, self::Frozen, self::OnHold, self::Refunded],
            'frozen' => [self::Releasing, self::Refunded, self::Held],
            'on_hold' => [self::Held, self::Refunded, self::Releasing],
            'releasing' => [self::Released],
            'released' => [],
            'refunded' => [],
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::NotFunded => 'Not funded',
            self::OnHold => 'On hold',
            default => ucfirst($this->value),
        };
    }
}
