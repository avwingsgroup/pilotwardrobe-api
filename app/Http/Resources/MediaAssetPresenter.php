<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Integrations\Media\MediaStorage;
use App\Models\Media;

/**
 * Serialises a Media row to the contract's `MediaAsset` (§1.8).
 *
 * Public assets get fixed-width `variants` and AVIF/WebP `sources`. Private assets
 * get a single expiring signed URL in every slot and no `sources`. Callers are
 * responsible for only presenting private media to authorised viewers.
 */
final class MediaAssetPresenter
{
    private const VARIANTS = [
        'thumbnail' => 'c_fill,w_160,h_160,q_auto,f_auto',
        'small' => 'c_limit,w_320,q_auto,f_auto',
        'medium' => 'c_limit,w_640,q_auto,f_auto',
        'large' => 'c_limit,w_1440,q_auto,f_auto',
        'original' => 'q_auto,f_auto',
    ];

    private const SOURCE_WIDTHS = [320, 640, 960, 1440, 1920];

    public function __construct(private readonly MediaStorage $storage) {}

    public static function make(?Media $media, ?string $fallbackAlt = null): ?array
    {
        return $media === null ? null : app(self::class)->present($media, $fallbackAlt);
    }

    /** @param iterable<Media> $media */
    public static function many(iterable $media, ?string $altPrefix = null): array
    {
        $out = [];
        $i = 1;
        foreach ($media as $item) {
            $out[] = app(self::class)->present($item, $altPrefix ? "{$altPrefix} — photo {$i}" : null);
            $i++;
        }

        return $out;
    }

    public function present(Media $media, ?string $fallbackAlt = null): array
    {
        $base = [
            'id' => $media->id,
            'public_id' => $media->public_id,
        ];

        if ($media->isPrivate()) {
            $signed = $this->storage->temporaryUrl($media, (int) config('platform.media.private_url_ttl_seconds'));

            return $base + [
                'url' => $signed,
                'secure_url' => $signed,
                'width' => $media->width,
                'height' => $media->height,
                'format' => $media->format,
                'bytes' => $media->bytes,
                'alt' => $media->alt ?? $fallbackAlt,
                'variants' => array_fill_keys(array_keys(self::VARIANTS), $signed),
                'expires_at' => now()->addSeconds((int) config('platform.media.private_url_ttl_seconds'))->toIso8601ZuluString(),
                'private' => true,
            ];
        }

        $secure = $this->storage->url($media);
        $isImage = $media->resource_type === 'image';

        $variants = [];
        foreach (self::VARIANTS as $name => $transformation) {
            $variants[$name] = $isImage ? $this->storage->url($media, $transformation) : $secure;
        }

        $out = $base + [
            'url' => preg_replace('#^https://#', 'http://', $secure),
            'secure_url' => $secure,
            'width' => $media->width,
            'height' => $media->height,
            'format' => $media->format,
            'bytes' => $media->bytes,
            'alt' => $media->alt ?? $fallbackAlt,
            'variants' => $variants,
        ];

        if ($isImage && $media->width) {
            $widths = array_values(array_filter(self::SOURCE_WIDTHS, fn (int $w) => $w <= $media->width)) ?: [$media->width];
            $sources = [];
            foreach (['avif', 'webp'] as $format) {
                foreach ($widths as $w) {
                    $sources[] = [
                        'url' => $this->storage->url($media, "c_limit,w_{$w},f_{$format},q_auto", $format),
                        'width' => $w,
                        'format' => $format,
                    ];
                }
            }
            $out['sources'] = $sources;
        }

        $out['placeholder'] = $media->placeholder;

        return $out;
    }
}
