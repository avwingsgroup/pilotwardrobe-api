<?php

declare(strict_types=1);

namespace App\Integrations\Media;

use App\Models\Media;

/**
 * Where uploaded files live. Laravel owns the provider credentials; the SPA only
 * ever receives delivery URLs (public) or short-lived signed URLs (private).
 */
interface MediaStorage
{
    /**
     * @param  string  $localPath  sanitised temporary file (EXIF already stripped)
     * @param  bool  $private  use authenticated delivery
     */
    public function store(string $localPath, string $publicId, string $resourceType, bool $private, ?string $convertTo = null): StoredAsset;

    public function delete(Media $media): void;

    /** Delivery URL for a public asset with an optional transformation (e.g. `c_limit,w_640,q_auto,f_auto`). */
    public function url(Media $media, ?string $transformation = null, ?string $format = null): string;

    /** Expiring URL for a private asset. */
    public function temporaryUrl(Media $media, int $ttlSeconds): string;
}
