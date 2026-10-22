<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Integrations\Media\MediaStorage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upload pipeline for `POST /media` (handoff §6):
 *
 *  1. MIME detected from content (never trusted from the client), size ≤ 15 MB.
 *  2. JPEG/PNG/WebP are re-encoded locally, which drops EXIF (incl. GPS) after applying
 *     orientation; HEIC is converted to JPEG by a Cloudinary incoming transformation.
 *  3. The public id is generated server-side: `{root}/{folder}/{owner}/{media id}`.
 *  4. Private types use authenticated delivery and are only exposed via signed URLs.
 */
final class MediaService
{
    public function __construct(private readonly MediaStorage $storage) {}

    public function upload(User $owner, UploadedFile $file, MediaType $type, ?string $alt = null): Media
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $isPdf = in_array($mime, config('platform.media.pdf_mimes'), true);
        $isImage = in_array($mime, config('platform.media.image_mimes'), true);

        if (! $isImage && ! ($isPdf && $type->allowsPdf())) {
            throw ApiException::validation(['file' => $type->allowsPdf()
                ? 'Upload a JPEG, PNG, WebP, HEIC or PDF file.'
                : 'Upload a JPEG, PNG, WebP or HEIC image.']);
        }

        [$path, $convertTo, $width, $height] = $isImage ? $this->sanitiseImage($file->getRealPath(), $mime) : [$file->getRealPath(), null, null, null];

        if ($type === MediaType::Listing && $width !== null && min($width, $height) < (int) config('platform.media.min_listing_dimension')) {
            throw ApiException::validation(['file' => 'Photos must be at least 320 px on the shortest side.']);
        }

        $id = Media::newPrefixedId();
        $publicId = implode('/', [config('services.cloudinary.root_folder', 'pilotwardrobe'), $type->folder(), $owner->id, $id]);

        try {
            $asset = $this->storage->store($path, $publicId, 'image', $type->isPrivate(), $convertTo);
        } finally {
            if ($path !== $file->getRealPath()) {
                @unlink($path);
            }
        }

        $media = new Media([
            'owner_id' => $owner->id,
            'type' => $type,
            'visibility' => $type->isPrivate() ? 'private' : 'public',
            'public_id' => $asset->publicId,
            'resource_type' => $asset->resourceType,
            'delivery_type' => $asset->deliveryType,
            'format' => $asset->format,
            'width' => $asset->width ?? $width,
            'height' => $asset->height ?? $height,
            'bytes' => $asset->bytes,
            'version' => $asset->version,
            'folder' => dirname($asset->publicId),
            'secure_url' => $asset->secureUrl,
            'alt' => $alt !== null ? mb_substr(strip_tags($alt), 0, 255) : null,
            'placeholder' => $asset->placeholder,
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
        ]);
        $media->id = $id;
        $media->save();

        return $media;
    }

    /**
     * Resolves media ids submitted with a request: each must belong to `$owner`, be of an
     * allowed type, and be either unattached or already attached to `$target`.
     *
     * @param  list<string>  $ids
     * @param  list<MediaType>  $types
     * @return list<Media> in the submitted order
     */
    public function claim(User $owner, array $ids, array $types, string $field, ?Model $target = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));
        if ($ids === []) {
            return [];
        }

        $found = Media::query()->whereIn('id', $ids)->where('owner_id', $owner->id)
            ->whereIn('type', array_map(fn (MediaType $t) => $t->value, $types))
            ->get()->keyBy('id');

        $out = [];
        foreach ($ids as $i => $id) {
            $media = $found->get($id);
            $attachedElsewhere = $media && $media->attachable_id !== null
                && ($target === null || $media->attachable_id !== $target->getKey() || $media->attachable_type !== $target->getMorphClass());
            if ($media === null || $attachedElsewhere) {
                throw ApiException::validation(["{$field}.{$i}" => 'This file is not available. Upload it again.']);
            }
            $out[] = $media;
        }

        return $out;
    }

    public function claimOne(User $owner, ?string $id, MediaType $type, string $field, ?Model $target = null): ?Media
    {
        return $id ? ($this->claim($owner, [$id], [$type], $field, $target)[0] ?? null) : null;
    }

    /** Destroys the provider asset and soft-deletes the row. Provider failure is logged, not fatal. */
    public function delete(Media $media): void
    {
        try {
            $this->storage->delete($media);
        } catch (Throwable $e) {
            Log::warning('media.delete_failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        }
        $media->delete();
    }

    /** @return array{0: string, 1: ?string, 2: ?int, 3: ?int} [path, convertTo, width, height] */
    private function sanitiseImage(string $path, string $mime): array
    {
        if (in_array($mime, ['image/heic', 'image/heif'], true)) {
            return [$path, 'jpg', null, null];
        }

        $raw = (string) file_get_contents($path);
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw ApiException::validation(['file' => 'This image could not be read. Try another file.']);
        }

        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $image = match ($orientation) {
                3 => imagerotate($image, 180, 0),
                6 => imagerotate($image, -90, 0),
                8 => imagerotate($image, 90, 0),
                default => $image,
            } ?: $image;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $out = tempnam(sys_get_temp_dir(), 'pwimg');
        $ok = match ($mime) {
            'image/png' => (function () use ($image, $out) {
                imagesavealpha($image, true);

                return imagepng($image, $out, 6);
            })(),
            'image/webp' => imagewebp($image, $out, 90),
            default => imagejpeg($image, $out, 90),
        };
        imagedestroy($image);

        if (! $ok) {
            throw ApiException::validation(['file' => 'This image could not be processed. Try another file.']);
        }

        return [$out, null, $width, $height];
    }
}
