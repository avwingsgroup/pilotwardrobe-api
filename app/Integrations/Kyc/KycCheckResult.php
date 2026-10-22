<?php

declare(strict_types=1);

namespace App\Integrations\Kyc;

final readonly class KycCheckResult
{
    /**
     * @param  list<array{label: string, result: string, passed: bool}>  $checks
     * @param  list<array{field: string, document: ?string, profile: ?string, match: bool}>  $extracted
     */
    public function __construct(
        public array $checks,
        public array $extracted,
        public ?string $providerReference = null,
    ) {}

    public function passedCount(): int
    {
        return count(array_filter($this->checks, fn ($c) => $c['passed']));
    }
}
