<?php

declare(strict_types=1);

namespace App\Integrations\Payouts;

use App\Models\PayoutMethod;

final class TransferProviderRegistry
{
    /** @var array<string, TransferProvider> */
    private array $resolved = [];

    public function get(string $code): TransferProvider
    {
        return $this->resolved[$code] ??= match ($code) {
            'paystack' => new PaystackTransferProvider(config('services.paystack')),
            'flutterwave' => new FlutterwaveTransferProvider(config('services.flutterwave')),
            default => new ManualTransferProvider,
        };
    }

    public function set(string $code, TransferProvider $provider): void
    {
        $this->resolved[$code] = $provider;
    }

    /** Picks the rail for a new payout method: Paystack for NGN/GHS banks & mobile money, Flutterwave for other banks. */
    public function providerCodeFor(PayoutMethod $method): string
    {
        return match (true) {
            in_array($method->kind, ['bank', 'mobile_money'], true) && in_array($method->currency, ['NGN', 'GHS'], true)
                && ! empty(config('services.paystack.secret_key')) => 'paystack',
            $method->kind === 'bank' && ! empty(config('services.flutterwave.secret_key')) => 'flutterwave',
            default => 'manual',
        };
    }
}
