<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\FitKitProfile;
use App\Models\Listing;
use App\Models\User;

/**
 * Computes `sizes.fit_match` for sized listings against the viewer's default Fit Kit
 * profile. The category's size chart declares which Fit Kit field drives sizing and an
 * optional map from measurement to size label, e.g.
 *
 *   "fit_kit": { "field": "collar", "summary_fields": ["collar", "sleeve"],
 *                "map": { "15": "S", "15.5": "M", "16": "L" } }
 *
 * Without a map, the profile value must equal a size label (shoes "10.5", jackets "42R").
 */
final class FitMatcher
{
    /** @var array<string, ?FitKitProfile> */
    private array $profiles = [];

    public function defaultProfile(?User $viewer): ?FitKitProfile
    {
        if ($viewer === null) {
            return null;
        }

        return $this->profiles[$viewer->id] ??= FitKitProfile::query()->where('user_id', $viewer->id)->where('is_default', true)->first();
    }

    /** @return array{size: string, profile_name: string, summary: string}|null */
    public function match(Listing $listing, ?User $viewer): ?array
    {
        $available = $listing->availableSizes();
        $profile = $this->defaultProfile($viewer);
        $config = $listing->category?->size_chart['fit_kit'] ?? null;
        if ($available === null || $profile === null || ! is_array($config) || empty($config['field'])) {
            return null;
        }

        $raw = $profile->{$config['field']} ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = $this->normalise((string) $raw);

        $size = null;
        if (! empty($config['map']) && is_array($config['map'])) {
            foreach ($config['map'] as $measurement => $label) {
                if ($this->normalise((string) $measurement) === $value) {
                    $size = (string) $label;
                    break;
                }
            }
        } else {
            $size = (string) $raw;
        }

        $match = null;
        foreach ($available as $label) {
            if ($size !== null && $this->normalise((string) $label) === $this->normalise($size)) {
                $match = (string) $label;
                break;
            }
        }
        if ($match === null) {
            return null;
        }

        $parts = [];
        foreach ($config['summary_fields'] ?? [$config['field']] as $field) {
            if (! empty($profile->{$field})) {
                $parts[] = ucfirst($field).' '.$profile->{$field};
            }
        }
        $parts[] = 'from your saved profile';

        return ['size' => $match, 'profile_name' => $profile->name, 'summary' => implode(' · ', $parts)];
    }

    private function normalise(string $value): string
    {
        return mb_strtolower(trim(str_replace(['"', '″', 'in', ' '], '', $value)));
    }
}
