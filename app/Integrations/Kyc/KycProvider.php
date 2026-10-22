<?php

declare(strict_types=1);

namespace App\Integrations\Kyc;

use App\Integrations\ProviderException;

/**
 * Automated identity checks that support (never replace) the AD5 human review.
 * Implementations return only what reviewers need — no raw document images or
 * full provider payloads are stored.
 */
interface KycProvider
{
    public function name(): string;

    /** @throws ProviderException */
    public function runChecks(KycCheckRequest $request): KycCheckResult;
}
