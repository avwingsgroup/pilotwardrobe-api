<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Enums\PaymentStatus;

/** What the PSP says about a charge, normalised. Amounts are minor units. */
final readonly class VerificationResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $gatewayReference = null,
        public ?string $methodLabel = null,
        public ?string $failureReason = null,
        public ?int $feeAmount = null,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {}
}
