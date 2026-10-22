<?php

declare(strict_types=1);

namespace App\Integrations\Payouts;

use App\Integrations\ProviderException;
use App\Models\Payout;
use App\Models\PayoutMethod;

/** Sends seller payouts. Only Laravel initiates transfers — never the SPA. */
interface TransferProvider
{
    public function code(): string;

    /**
     * Validates bank details with the provider and returns the recipient code to store.
     *
     * @return array{recipient_code: ?string, account_name: ?string}
     *
     * @throws ProviderException
     */
    public function createRecipient(PayoutMethod $method, string $accountNumber): array;

    /**
     * @return array{status: 'processing'|'paid'|'failed', provider_reference: ?string, failure_reason?: ?string}
     *
     * @throws ProviderException
     */
    public function transfer(Payout $payout): array;

    /**
     * Authoritative transfer status from the provider (used by reconciliation and webhooks).
     *
     * @return array{status: 'processing'|'paid'|'failed', failure_reason?: ?string}
     *
     * @throws ProviderException
     */
    public function status(Payout $payout): array;
}
