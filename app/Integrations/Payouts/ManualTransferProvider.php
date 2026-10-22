<?php

declare(strict_types=1);

namespace App\Integrations\Payouts;

use App\Models\Payout;
use App\Models\PayoutMethod;

/**
 * For destinations without an automated rail (Wise, PayPal payouts until integrated):
 * the payout stays `processing` until finance marks it paid from the Control Tower.
 */
final class ManualTransferProvider implements TransferProvider
{
    public function code(): string
    {
        return 'manual';
    }

    public function createRecipient(PayoutMethod $method, string $accountNumber): array
    {
        return ['recipient_code' => null, 'account_name' => null];
    }

    public function transfer(Payout $payout): array
    {
        return ['status' => 'processing', 'provider_reference' => null];
    }

    public function status(Payout $payout): array
    {
        return ['status' => 'processing'];
    }
}
