<?php

declare(strict_types=1);

namespace App\Integrations\Media;

/** Provider-neutral result of storing an asset. */
final readonly class StoredAsset
{
    public function __construct(
        public string $publicId,
        public string $resourceType,
        public string $deliveryType,
        public ?string $format,
        public ?int $width,
        public ?int $height,
        public ?int $bytes,
        public ?int $version,
        public ?string $secureUrl,
        public ?string $placeholder = null,
    ) {}
}
