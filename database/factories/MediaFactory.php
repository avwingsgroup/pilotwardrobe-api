<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaType;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'type' => MediaType::Listing,
            'visibility' => 'public',
            'public_id' => 'pilotwardrobe/test/'.Str::lower(Str::random(16)),
            'resource_type' => 'image',
            'delivery_type' => 'upload',
            'format' => 'jpg',
            'width' => 1600,
            'height' => 1067,
            'bytes' => 284113,
            'version' => 1,
            'alt' => null,
            'placeholder' => '#d9dde3',
        ];
    }

    public function type(MediaType $type): static
    {
        return $this->state([
            'type' => $type,
            'visibility' => $type->isPrivate() ? 'private' : 'public',
            'delivery_type' => $type->isPrivate() ? 'authenticated' : 'upload',
        ]);
    }
}
