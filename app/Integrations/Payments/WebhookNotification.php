<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * Authenticated, parsed webhook. `kind` tells the processor what to re-verify:
 * `payment` (look up by reference), `refund`, `transfer` (payouts) or `ignored`.
 */
final readonly class WebhookNotification
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $kind,
        public ?string $reference = null,
        public ?string $gatewayReference = null,
        /** @var array<string, mixed> */
        public array $payload = [],
    ) {}
}
