<?php

declare(strict_types=1);

namespace App\Integrations\Media;

use App\Integrations\ProviderException;
use App\Models\Media;
use Cloudinary\Cloudinary;
use Throwable;

/**
 * Cloudinary adapter.
 *
 * - Public assets: `type=upload`; delivery URLs are built deterministically from
 *   public id, version and transformation (no API call).
 * - Private assets (`kyc`, `dispute_evidence`, `message`): `type=authenticated`;
 *   exposed only through `private_download_url` links that carry `expires_at`.
 */
final class CloudinaryMediaStorage implements MediaStorage
{
    private ?Cloudinary $client = null;

    public function __construct(private readonly array $config) {}

    public function store(string $localPath, string $publicId, string $resourceType, bool $private, ?string $convertTo = null): StoredAsset
    {
        $options = [
            'public_id' => $publicId,
            'resource_type' => $resourceType,
            'type' => $private ? 'authenticated' : 'upload',
            'overwrite' => false,
            'unique_filename' => false,
            'image_metadata' => false,
            'colors' => $resourceType === 'image',
        ];
        if ($convertTo !== null) {
            // Incoming transformation: the stored original is re-encoded (drops HEIC metadata/GPS).
            $options['format'] = $convertTo;
        }

        try {
            $result = $this->client()->uploadApi()->upload($localPath, $options);
        } catch (Throwable $e) {
            throw ProviderException::transient('cloudinary', 'Upload failed: '.$e->getMessage(), $e);
        }

        $placeholder = null;
        if (! empty($result['colors'][0][0]) && is_string($result['colors'][0][0])) {
            $placeholder = strtolower($result['colors'][0][0]);
        }

        return new StoredAsset(
            publicId: (string) $result['public_id'],
            resourceType: (string) ($result['resource_type'] ?? $resourceType),
            deliveryType: (string) ($result['type'] ?? ($private ? 'authenticated' : 'upload')),
            format: isset($result['format']) ? (string) $result['format'] : null,
            width: isset($result['width']) ? (int) $result['width'] : null,
            height: isset($result['height']) ? (int) $result['height'] : null,
            bytes: isset($result['bytes']) ? (int) $result['bytes'] : null,
            version: isset($result['version']) ? (int) $result['version'] : null,
            secureUrl: $private ? null : (string) ($result['secure_url'] ?? ''),
            placeholder: $placeholder,
        );
    }

    public function delete(Media $media): void
    {
        try {
            $this->client()->uploadApi()->destroy($media->public_id, [
                'resource_type' => $media->resource_type,
                'type' => $media->delivery_type,
                'invalidate' => true,
            ]);
        } catch (Throwable $e) {
            throw ProviderException::transient('cloudinary', 'Delete failed: '.$e->getMessage(), $e);
        }
    }

    public function url(Media $media, ?string $transformation = null, ?string $format = null): string
    {
        $segments = array_filter([
            (($this->config['secure'] ?? true) ? 'https' : 'http').'://res.cloudinary.com',
            $this->config['cloud_name'],
            $media->resource_type,
            $media->delivery_type,
            $transformation,
            $media->version ? 'v'.$media->version : null,
        ]);
        $extension = $format ?? $media->format;

        return implode('/', $segments).'/'.$media->public_id.($extension ? '.'.$extension : '');
    }

    public function temporaryUrl(Media $media, int $ttlSeconds): string
    {
        return $this->client()->uploadApi()->privateDownloadUrl($media->public_id, (string) $media->format, [
            'type' => $media->delivery_type,
            'resource_type' => $media->resource_type,
            'expires_at' => time() + $ttlSeconds,
        ]);
    }

    private function client(): Cloudinary
    {
        if (empty($this->config['cloud_name']) || empty($this->config['api_key']) || empty($this->config['api_secret'])) {
            throw ProviderException::permanent('cloudinary', 'Cloudinary credentials are not configured.');
        }

        return $this->client ??= new Cloudinary([
            'cloud' => [
                'cloud_name' => $this->config['cloud_name'],
                'api_key' => $this->config['api_key'],
                'api_secret' => $this->config['api_secret'],
            ],
            'url' => ['secure' => (bool) ($this->config['secure'] ?? true)],
            'api' => ['timeout' => 60, 'upload_timeout' => 120],
        ]);
    }
}
