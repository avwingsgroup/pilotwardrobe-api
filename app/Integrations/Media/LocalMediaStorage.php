<?php

declare(strict_types=1);

namespace App\Integrations\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Development/test stand-in for Cloudinary (`MEDIA_DRIVER=local`). Files go to the
 * local disks; transformations are ignored and private files are served through a
 * temporary signed route. Never use in production.
 */
final class LocalMediaStorage implements MediaStorage
{
    public function store(string $localPath, string $publicId, string $resourceType, bool $private, ?string $convertTo = null): StoredAsset
    {
        $size = $resourceType === 'image' ? @getimagesize($localPath) : false;
        $extension = $convertTo ?? match ($size[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            default => $resourceType === 'raw' ? 'pdf' : 'bin',
        };

        $disk = $private ? 'local' : 'public';
        $path = 'media/'.$publicId.'.'.$extension;
        Storage::disk($disk)->put($path, (string) file_get_contents($localPath));

        return new StoredAsset(
            publicId: $publicId,
            resourceType: $resourceType,
            deliveryType: $private ? 'authenticated' : 'upload',
            format: $extension,
            width: $size[0] ?? null,
            height: $size[1] ?? null,
            bytes: (int) filesize($localPath),
            version: time(),
            secureUrl: $private ? null : Storage::disk('public')->url($path),
            placeholder: '#dddddd',
        );
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->isPrivate() ? 'local' : 'public')->delete('media/'.$media->public_id.'.'.$media->format);
    }

    public function url(Media $media, ?string $transformation = null, ?string $format = null): string
    {
        return Storage::disk('public')->url('media/'.$media->public_id.'.'.$media->format);
    }

    public function temporaryUrl(Media $media, int $ttlSeconds): string
    {
        return URL::temporarySignedRoute('media.local-private', now()->addSeconds($ttlSeconds), ['media' => $media->id]);
    }
}
