<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class InitializeRequest
{
    public function __construct(
        public string $customerEmail,
        public string $customerName,
        public string $returnUrl,
        public string $cancelUrl,
        public string $description,
        /** @var array<string, string> */
        public array $metadata = [],
    ) {}
}
