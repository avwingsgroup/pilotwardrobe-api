<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value: integer minor units + ISO-4217 currency.
 *
 * Floats are never used. Percentages are expressed in basis points
 * (1% = 100 bps) and rounded half-up to the nearest minor unit.
 */
final class Money implements JsonSerializable
{
    public function __construct(
        public readonly int $amount,
        public readonly Currency $currency,
    ) {}

    public static function of(int $amount, Currency|string $currency): self
    {
        return new self($amount, $currency instanceof Currency ? $currency : Currency::from(strtoupper($currency)));
    }

    public static function zero(Currency|string $currency): self
    {
        return self::of(0, $currency);
    }

    /** Builds Money from a nullable amount column; null in → null out. */
    public static function nullable(?int $amount, Currency|string|null $currency): ?self
    {
        return $amount === null || $currency === null ? null : self::of($amount, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /** Share of this amount at `$basisPoints` (800 = 8%), rounded half-up. */
    public function percentage(int $basisPoints): self
    {
        return new self(intdiv($this->amount * $basisPoints + 5000, 10000), $this->currency);
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amount <= $other->amount ? $this : $other;
    }

    public function max(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amount >= $other->amount ? $this : $other;
    }

    public function negate(): self
    {
        return new self(-$this->amount, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function format(?string $locale = null): string
    {
        return MoneyFormatter::format($this, $locale);
    }

    /** Wire shape: `{ amount, currency, formatted }`. */
    public function toArray(bool $withFormatted = true): array
    {
        $out = ['amount' => $this->amount, 'currency' => $this->currency->value];
        if ($withFormatted) {
            $out['formatted'] = $this->format();
        }

        return $out;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency->value} vs {$other->currency->value}.");
        }
    }
}
