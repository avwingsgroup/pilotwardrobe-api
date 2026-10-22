<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Exceptions\ApiException;
use App\Integrations\Payments\Gateways\FlutterwaveGateway;
use App\Integrations\Payments\Gateways\MonnifyGateway;
use App\Integrations\Payments\Gateways\PayPalGateway;
use App\Integrations\Payments\Gateways\PaystackGateway;
use App\Integrations\Payments\Gateways\SandboxGateway;
use App\Integrations\Payments\Gateways\StripeGateway;
use App\Services\Platform\PlatformSettings;

/**
 * Resolves gateways by code and decides which ones a buyer can use for a currency
 * and country (platform settings + whether credentials are configured).
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly PlatformSettings $settings) {}

    /** @return list<string> */
    public function codes(): array
    {
        return ['paystack', 'flutterwave', 'monnify', 'stripe', 'paypal', 'sandbox'];
    }

    public function get(string $code): PaymentGateway
    {
        return $this->resolved[$code] ??= match ($code) {
            'paystack' => new PaystackGateway(config('services.paystack')),
            'flutterwave' => new FlutterwaveGateway(config('services.flutterwave')),
            'monnify' => new MonnifyGateway(config('services.monnify')),
            'stripe' => new StripeGateway(config('services.stripe')),
            'paypal' => new PayPalGateway(config('services.paypal')),
            'sandbox' => new SandboxGateway,
            default => throw ApiException::validation(['gateway' => 'Choose a payment method.']),
        };
    }

    /** Swap an implementation (tests). */
    public function set(string $code, PaymentGateway $gateway): void
    {
        $this->resolved[$code] = $gateway;
    }

    /**
     * Gateways to show at checkout (`GET /payment-methods`), each with `enabled` and a
     * plain-language `unavailable_reason`.
     *
     * @return list<array<string, mixed>>
     */
    public function methodsFor(?string $currency, ?string $country): array
    {
        $out = [];
        foreach ($this->settings->get('gateways', []) as $definition) {
            $gateway = $this->get($definition['code']);
            $reason = match (true) {
                ! ($definition['enabled'] ?? false) => 'Temporarily unavailable.',
                ! $gateway->isConfigured() => 'Not available yet.',
                $currency !== null && ! in_array(strtoupper($currency), $definition['currencies'] ?? [], true) => "Doesn't support {$currency}.",
                $country !== null && ! in_array('*', $definition['countries'] ?? [], true) && ! in_array(strtoupper($country), $definition['countries'] ?? [], true) => 'Not available in your country.',
                default => null,
            };

            $out[] = [
                'code' => $definition['code'],
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'flow' => $definition['flow'] ?? 'redirect',
                'logo' => $definition['logo'] ?? null,
                'enabled' => $reason === null,
                'unavailable_reason' => $reason,
            ];
        }

        if ($this->get('sandbox')->isConfigured()) {
            $out[] = ['code' => 'sandbox', 'name' => 'Sandbox (test)', 'description' => 'Local test payments', 'flow' => 'redirect',
                'logo' => null, 'enabled' => true, 'unavailable_reason' => null];
        }

        return $out;
    }

    /** Throws a 422/503 when the gateway cannot take this payment. */
    public function assertUsable(string $code, string $currency): PaymentGateway
    {
        if ($code === 'sandbox') {
            $gateway = $this->get('sandbox');
            if (! $gateway->isConfigured()) {
                throw ApiException::validation(['gateway' => 'Choose a payment method.']);
            }

            return $gateway;
        }

        $definition = $this->settings->gateway($code);
        if ($definition === null) {
            throw ApiException::validation(['gateway' => 'Choose a payment method.']);
        }
        if (! in_array($currency, $definition['currencies'] ?? [], true)) {
            throw ApiException::validation(['gateway' => "{$definition['name']} doesn't support {$currency}. Choose another method."]);
        }

        $gateway = $this->get($code);
        if (! ($definition['enabled'] ?? false) || ! $gateway->isConfigured()) {
            throw ApiException::gatewayUnavailable();
        }

        return $gateway;
    }
}
