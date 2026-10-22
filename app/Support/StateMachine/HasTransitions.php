<?php

declare(strict_types=1);

namespace App\Support\StateMachine;

use App\Exceptions\ApiException;

/**
 * Centralised state-transition rules for backed status enums.
 *
 * Each enum implements `transitions()` returning `[from->value => [to, …]]`.
 * Any transition not listed is illegal and surfaces as 409 INVALID_STATE_TRANSITION.
 */
trait HasTransitions
{
    /** @return array<string, list<self>> */
    abstract public static function transitions(): array;

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, static::transitions()[$this->value] ?? [], true);
    }

    public function assertCanTransitionTo(self $to, ?string $message = null): void
    {
        if (! $this->canTransitionTo($to)) {
            throw ApiException::invalidTransition($message ?? 'This action is not allowed in the current state.');
        }
    }

    /** @param list<self> $states */
    public function in(array $states): bool
    {
        return in_array($this, $states, true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
