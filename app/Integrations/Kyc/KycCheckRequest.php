<?php

declare(strict_types=1);

namespace App\Integrations\Kyc;

final readonly class KycCheckRequest
{
    public function __construct(
        public string $reference,
        public string $firstName,
        public string $lastName,
        public ?string $dateOfBirth,     // YYYY-MM-DD
        public string $documentType,     // passport | national_id | nin | bvn | drivers_licence | voters_card
        public ?string $documentNumber,
        public ?string $documentCountry,
        public ?string $documentImageUrl, // short-lived signed URL
        public ?string $selfieImageUrl,   // short-lived signed URL
    ) {}
}
