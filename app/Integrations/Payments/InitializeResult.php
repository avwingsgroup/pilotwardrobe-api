<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

final readonly class InitializeResult
{
    public function __construct(
        public string $authorizationUrl,
        public ?string $gatewayReference = null,
        /** @var array<string, mixed> sanitised data worth keeping (never card data) */
        public array $metadata = [],
    ) {}
}
