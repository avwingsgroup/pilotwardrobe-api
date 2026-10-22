<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\HeroSlide;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Home hero slide. Public payloads use the published version; the admin editor
 * (`draft: true`) shows pending draft edits layered on top.
 *
 * @mixin HeroSlide
 */
final class HeroSlideResource extends JsonResource
{
    private bool $withDraft = false;

    public function withDraft(): self
    {
        $this->withDraft = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var HeroSlide $slide */
        $slide = $this->resource;
        $content = $this->withDraft ? array_merge($slide->published ?? [], $slide->draft ?? []) : ($slide->published ?? []);
        $image = ! empty($content['image_media_id']) ? Media::query()->find($content['image_media_id']) : null;

        $out = [
            'id' => $slide->id,
            'position' => $slide->position,
            'headline' => $content['headline'] ?? null,
            'subtext' => $content['subtext'] ?? null,
            'button_label' => $content['button_label'] ?? null,
            'link' => $content['link'] ?? null,
            'audience' => $content['audience'] ?? null,
            'locales' => $content['locales'] ?? null,
            'status' => $content['status'] ?? 'live',
            'schedule' => $content['schedule'] ?? 'always',
            'image' => MediaAssetPresenter::make($image, $content['headline'] ?? null),
        ];

        if ($this->withDraft) {
            $out['active'] = (bool) $slide->active;
            $out['has_draft'] = ! empty($slide->draft);
        }

        return $out;
    }
}
