<?php

declare(strict_types=1);

namespace App\Integrations\Payouts;

use App\Integrations\Payments\Amounts;
use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;
use App\Models\Payout;
use App\Models\PayoutMethod;
use Illuminate\Http\Client\PendingRequest;

/** Flutterwave Transfers. Bank details are validated with account resolve before saving. */
final class FlutterwaveTransferProvider implements TransferProvider
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'flutterwave';
    }

    public function createRecipient(PayoutMethod $method, string $accountNumber): array
    {
        $response = ProviderHttp::send('flutterwave', 'accounts.resolve', fn () => $this->http()->post('/accounts/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $method->bank_code,
        ]));

        // Flutterwave transfers take raw bank details; we keep them encrypted and use the
        // account number as the "recipient" reference.
        return ['recipient_code' => $method->bank_code.':'.$accountNumber, 'account_name' => $response->json('data.account_name')];
    }

    public function transfer(Payout $payout): array
    {
        [$bank, $account] = array_pad(explode(':', (string) $payout->payoutMethod->provider_recipient_code, 2), 2, null);
        if (! $bank || ! $account) {
            throw ProviderException::permanent('flutterwave', 'Payout method is missing bank details.');
        }

        $response = ProviderHttp::send('flutterwave', 'transfers.create', fn () => $this->http()->post('/transfers', [
            'account_bank' => $bank,
            'account_number' => $account,
            'amount' => Amounts::toMajor($payout->destination_amount),
            'currency' => $payout->destination_currency,
            'reference' => $payout->reference,
            'narration' => 'Pilotwardrobe payout '.$payout->reference,
            'debit_currency' => $payout->destination_currency,
        ]));

        return ['status' => self::mapStatus($response->json('data.status')), 'provider_reference' => (string) $response->json('data.id')];
    }

    public function status(Payout $payout): array
    {
        if (! $payout->provider_reference) {
            throw ProviderException::permanent('flutterwave', 'Payout has no Flutterwave transfer id.');
        }
        $response = ProviderHttp::send('flutterwave', 'transfers.get', fn () => $this->http()->get('/transfers/'.rawurlencode($payout->provider_reference)));
        $status = self::mapStatus($response->json('data.status'));

        return ['status' => $status, 'failure_reason' => $status === 'failed' ? (string) ($response->json('data.complete_message') ?: 'Transfer failed at Flutterwave.') : null];
    }

    private static function mapStatus(mixed $status): string
    {
        return match (strtoupper((string) $status)) {
            'SUCCESSFUL' => 'paid',
            'FAILED' => 'failed',
            default => 'processing', // NEW, PENDING
        };
    }

    private function http(): PendingRequest
    {
        if (empty($this->config['secret_key'])) {
            throw ProviderException::permanent('flutterwave', 'FLUTTERWAVE_SECRET_KEY is not configured.');
        }

        return ProviderHttp::client('flutterwave')->baseUrl($this->config['base_url'])->withToken($this->config['secret_key']);
    }
}
