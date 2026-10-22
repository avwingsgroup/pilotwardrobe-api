<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Exceptions\ApiException;
use App\Models\ExchangeRate;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Cache;

/**
 * Converts between platform currencies using the `exchange_rates` table (maintained by
 * finance or a rates job). Conversion is display/quote-only: settlement always happens
 * in the order's own currency. Rounding is half-up to the minor unit.
 */
final class ExchangeRates
{
    public function rate(Currency|string $from, Currency|string $to): float
    {
        $from = $from instanceof Currency ? $from->value : strtoupper($from);
        $to = $to instanceof Currency ? $to->value : strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $rates = Cache::remember('fx:rates', now()->addMinutes(10), fn () => ExchangeRate::query()->get(['base', 'quote', 'rate'])
            ->mapWithKeys(fn ($r) => [$r->base.$r->quote => (float) $r->rate])->all());

        if (isset($rates[$from.$to])) {
            return $rates[$from.$to];
        }
        if (isset($rates[$to.$from]) && $rates[$to.$from] > 0) {
            return 1 / $rates[$to.$from];
        }
        // Cross via USD.
        $viaFrom = $from === 'USD' ? 1.0 : ($rates['USD'.$from] ?? (isset($rates[$from.'USD']) ? 1 / $rates[$from.'USD'] : null));
        $viaTo = $to === 'USD' ? 1.0 : ($rates['USD'.$to] ?? (isset($rates[$to.'USD']) ? 1 / $rates[$to.'USD'] : null));
        if ($viaFrom && $viaTo) {
            return $viaTo / $viaFrom;
        }

        throw ApiException::conflict("No exchange rate available for {$from} → {$to}.");
    }

    public function convert(Money $money, Currency|string $to): Money
    {
        $target = $to instanceof Currency ? $to : Currency::from(strtoupper($to));
        if ($money->currency === $target) {
            return $money;
        }

        $rate = $this->rate($money->currency, $target);
        $scale = $target->minorPerMajor() / $money->currency->minorPerMajor();

        return Money::of((int) round($money->amount * $rate * $scale, 0, PHP_ROUND_HALF_UP), $target);
    }

    public static function flush(): void
    {
        Cache::forget('fx:rates');
    }
}
