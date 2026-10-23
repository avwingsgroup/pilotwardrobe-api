<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MediaType;
use App\Http\Controllers\Api\V1\Catalogue\HomeController;
use App\Integrations\Media\MediaStorage;
use App\Models\Category;
use App\Models\HeroSlide;
use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Gives a fresh install its storefront imagery: uploads the bundled photos in docs/PW-images
 * through the configured media driver (Cloudinary in production) and attaches them to hero
 * slides and categories that have no image yet. Never replaces an image set in the Control
 * Tower, so it is safe to re-run; `--dry-run` lists what would change.
 *
 *   php artisan content:seed-images
 */
final class SeedContentImages extends Command
{
    protected $signature = 'content:seed-images {--dry-run : List what would be attached without uploading}';

    protected $description = 'Attach the bundled storefront photos to hero slides and categories that have no image.';

    /** Hero slide position => photo. */
    private const SLIDES = [
        1 => 'cockpit.png',
        2 => 'p-0.png',
    ];

    /** Category slug => photo (only categories with a fitting photo; the rest keep the SPA placeholder). */
    private const CATEGORIES = [
        'pilotwardrobe' => 'p-6.png',
        'headsets' => 'ANR-headset.png',
        'anr-headsets' => 'p-3.png',
        'passive-headsets' => 'p-9.png',
        'headset-accessories' => 'p-4.png',
        'uniforms' => 'p-0.png',
        'shirts' => 'p-6.png',
        'jackets' => 'p-0.png',
        'eyewear' => 'p-5.png',
        'watches' => 'p-8.png',
        'flight-bags' => 'pilot-flight-bag.png',
        'kneeboards' => 'aviation-kneeboard.png',
        'flight-computers' => 'E6B-flight-computer.png',
        'avionics' => 'Aircraft-Communication-Radio.png',
        'handheld-radios' => 'Aircraft-Communication-Radio.png',
        'gps' => 'handheld-aviation-GPS.png',
        'tools' => 'Digital-multimeter.png',
        'safety' => 'Portable-oxygen.png',
        'power' => 'Portable-Power-Bank.png',
        'cameras' => 'Canon-EOS-90D-DSLR-camera.png',
        'prints' => 'Im-5.png',
    ];

    private int $attached = 0;

    private int $failed = 0;

    public function handle(MediaStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::SLIDES as $position => $file) {
            $slide = HeroSlide::query()->where('position', $position)->first();
            if ($slide === null || ! empty(($slide->published ?? [])['image_media_id'])) {
                continue;
            }
            $label = 'Hero slide '.$position.' ('.(($slide->published ?? [])['headline'] ?? 'untitled').')';
            $media = $this->upload($storage, $file, $label, $dryRun);
            if ($media !== null) {
                $slide->forceFill(['published' => array_merge($slide->published ?? [], ['image_media_id' => $media->id])])->save();
                $media->attachTo($slide, 'image');
            }
        }

        $categories = Category::query()->whereIn('slug', array_keys(self::CATEGORIES))->whereNull('image_media_id')->orderBy('slug')->get();
        foreach ($categories as $category) {
            $media = $this->upload($storage, self::CATEGORIES[$category->slug], 'Category '.$category->name, $dryRun);
            if ($media !== null) {
                $category->forceFill(['image_media_id' => $media->id])->save();
                $media->attachTo($category, 'image');
            }
        }

        if (! $dryRun && $this->attached > 0) {
            Cache::forget(HomeController::CACHE_KEY);
            Cache::forget('catalogue:tree');
        }

        $this->info(($dryRun ? 'Would attach' : 'Attached').' '.$this->attached.' image(s)'.($this->failed ? ", {$this->failed} failed" : '').'.');

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function upload(MediaStorage $storage, string $file, string $label, bool $dryRun): ?Media
    {
        $path = base_path('docs/PW-images/'.$file);
        if (! is_file($path)) {
            $this->warn("{$label}: {$file} not found, skipped.");

            return null;
        }
        if ($dryRun) {
            $this->line("{$label} ← {$file}");
            $this->attached++;

            return null;
        }

        try {
            $id = Media::newPrefixedId();
            $asset = $storage->store($path, 'pilotwardrobe/'.MediaType::Content->folder().'/storefront/'.$id, 'image', false);
        } catch (Throwable $e) {
            $this->error("{$label}: upload failed: {$e->getMessage()}");
            $this->failed++;

            return null;
        }

        $media = new Media([
            'type' => MediaType::Content, 'visibility' => 'public',
            'public_id' => $asset->publicId, 'resource_type' => 'image', 'delivery_type' => $asset->deliveryType, 'format' => $asset->format,
            'width' => $asset->width, 'height' => $asset->height, 'bytes' => $asset->bytes, 'version' => $asset->version,
            'secure_url' => $asset->secureUrl, 'placeholder' => $asset->placeholder, 'alt' => preg_replace('/^(Hero slide \d+ \(|Category )|\)$/', '', $label),
        ]);
        $media->id = $id;
        $media->save();

        $this->line("{$label} ← {$file}");
        $this->attached++;

        return $media;
    }
}
