<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Exceptions\ApiException;

/**
 * Normalises the wizard's freight step (VB2) into the listing's stored shipping options.
 *
 * Freight methods:
 *  - `pilotwardrobe_freight` — prepaid tracked label at platform rates (standard + express)
 *  - `self`                  — seller ships and sets the price
 *  - `handoff`               — meet at a listed airport, free, escrow still applies
 * `who_pays: seller` makes every option free for the buyer (free delivery).
 * An explicit `shipping` array may be sent instead; prices are always the listing currency.
 */
final class ShippingOptions
{
    /**
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>, 2: bool} [shipping, freight, free_delivery]
     */
    public static function build(?array $shipping, ?array $freight, string $currency): array
    {
        $freePaidBySeller = ($freight['who_pays'] ?? 'buyer') === 'seller';

        if (! empty($shipping)) {
            $options = [];
            foreach (array_values($shipping) as $option) {
                $method = (string) ($option['method'] ?? 'standard');
                $amount = (int) ($option['price']['amount'] ?? $option['amount'] ?? 0);
                $options[] = self::option($method, $option['label'] ?? null, $option['description'] ?? null,
                    $freePaidBySeller ? 0 : max(0, $amount), $option['estimated_days'] ?? null);
            }

            return [self::dedupeIds($options), $freight, $freePaidBySeller || min(array_column($options, 'price_amount')) === 0];
        }

        if (empty($freight['method'])) {
            return [[], $freight, false];
        }

        $rates = config('platform.freight.rates');
        $options = match ($freight['method']) {
            'pilotwardrobe_freight' => [
                self::option('standard', 'Standard cargo', '5–10 business days · tracked', $freePaidBySeller ? 0 : self::rate($rates, 'standard', $currency), ['min' => 5, 'max' => 10]),
                self::option('express', 'Express airlift', '2–3 business days · tracked & signed', $freePaidBySeller ? 0 : self::rate($rates, 'express', $currency), ['min' => 2, 'max' => 3]),
            ],
            'self' => [
                self::option('self', 'Seller-arranged shipping', 'Tracking uploaded within 3 days',
                    $freePaidBySeller ? 0 : (int) ($freight['price']['amount'] ?? $freight['price_amount'] ?? throw ApiException::validation(['freight.price.amount' => 'Set your shipping price.'])),
                    $freight['estimated_days'] ?? ['min' => 3, 'max' => 10]),
            ],
            'handoff' => [
                self::option('handoff', 'Hand-off at airfield', 'Meet at '.($freight['ships_from']['airport_code'] ?? 'a listed airport').' · escrow still applies', 0, null),
            ],
            default => throw ApiException::validation(['freight.method' => 'Choose a freight option.']),
        };

        if (! empty($freight['allow_handoff']) && $freight['method'] !== 'handoff') {
            $options[] = self::option('handoff', 'Hand-off at airfield', 'Meet at a listed airport · escrow still applies', 0, null);
        }

        return [$options, $freight, $freePaidBySeller];
    }

    private static function option(string $method, ?string $label, ?string $description, int $amount, ?array $days): array
    {
        return [
            'id' => 'shp_'.match ($method) {
                'standard' => 'std',
                'express' => 'exp',
                default => $method,
            },
            'method' => $method,
            'label' => $label ?? ucfirst(str_replace('_', ' ', $method)),
            'description' => $description,
            'price_amount' => $amount,
            'estimated_days' => $days,
        ];
    }

    private static function rate(array $rates, string $tier, string $currency): int
    {
        return (int) ($rates[$tier][$currency] ?? $rates[$tier]['USD'] ?? 0);
    }

    private static function dedupeIds(array $options): array
    {
        $seen = [];
        foreach ($options as $i => $option) {
            $id = $option['id'];
            $n = 2;
            while (isset($seen[$id])) {
                $id = $option['id'].'_'.$n++;
            }
            $seen[$id] = true;
            $options[$i]['id'] = $id;
        }

        return $options;
    }
}
