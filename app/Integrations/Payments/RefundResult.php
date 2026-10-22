<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class RefundResult
{
    /** @param 'pending'|'processing'|'succeeded'|'failed' $status */
    public function __construct(
        public string $status,
        public ?string $gatewayRefundId = null,
        public ?string $failureReason = null,
    ) {}
}
