<?php

declare(strict_types=1);

namespace App\Integrations\Payouts;

use App\Integrations\ProviderException;
use App\Integrations\ProviderHttp;
use App\Models\Payout;
use App\Models\PayoutMethod;
use Illuminate\Http\Client\PendingRequest;

/**
 * Paystack Transfers (NGN/GHS bank and mobile money). The outcome is confirmed with
 * `GET /transfer/verify/{reference}`, triggered by `transfer.*` webhooks or the payout sweep.
 */
final class PaystackTransferProvider implements TransferProvider
{
    public function __construct(private readonly array $config) {}

    public function code(): string
    {
        return 'paystack';
    }

    public function createRecipient(PayoutMethod $method, string $accountNumber): array
    {
        $type = match ($method->kind) {
            'mobile_money' => 'mobile_money',
            default => $method->currency === 'GHS' ? 'ghipss' : 'nuban',
        };

        $response = ProviderHttp::send('paystack', 'transferrecipient.create', fn () => $this->http()->post('/transferrecipient', [
            'type' => $type,
            'name' => $method->account_name ?: 'Pilotwardrobe seller',
            'account_number' => $accountNumber,
            'bank_code' => $method->bank_code,
            'currency' => $method->currency,
        ]));

        return [
            'recipient_code' => (string) $response->json('data.recipient_code'),
            'account_name' => $response->json('data.details.account_name'),
        ];
    }

    public function transfer(Payout $payout): array
    {
        $recipient = $payout->payoutMethod->provider_recipient_code;
        if (! $recipient) {
            throw ProviderException::permanent('paystack', 'Payout method has no recipient code.');
        }

        $response = ProviderHttp::send('paystack', 'transfer.create', fn () => $this->http()->post('/transfer', [
            'source' => 'balance',
            'amount' => $payout->destination_amount,
            'currency' => $payout->destination_currency,
            'recipient' => $recipient,
            'reference' => strtolower($payout->reference),
            'reason' => 'Pilotwardrobe payout '.$payout->reference,
        ]));

        return ['status' => self::mapStatus($response->json('data.status')), 'provider_reference' => (string) $response->json('data.transfer_code')];
    }

    public function status(Payout $payout): array
    {
        $response = ProviderHttp::send('paystack', 'transfer.verify', fn () => $this->http()->get('/transfer/verify/'.rawurlencode(strtolower($payout->reference))));
        $status = self::mapStatus($response->json('data.status'));

        return ['status' => $status, 'failure_reason' => $status === 'failed' ? 'The transfer was '.$response->json('data.status').' by Paystack.' : null];
    }

    private static function mapStatus(mixed $status): string
    {
        return match ($status) {
            'success' => 'paid',
            'failed', 'reversed' => 'failed',
            default => 'processing', // pending, otp, received, queued
        };
    }

    private function http(): PendingRequest
    {
        if (empty($this->config['secret_key'])) {
            throw ProviderException::permanent('paystack', 'PAYSTACK_SECRET_KEY is not configured.');
        }

        return ProviderHttp::client('paystack')->baseUrl($this->config['base_url'])->withToken($this->config['secret_key']);
    }
}
