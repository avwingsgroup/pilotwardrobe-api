<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'name' => $name,
            'sized' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function sized(array $sizes = ['XS', 'S', 'M', 'L', 'XL']): static
    {
        return $this->state([
            'sized' => true,
            'sizes' => $sizes,
            'size_chart' => [
                'columns' => ['Chest', 'Collar'],
                'rows' => array_map(fn ($s) => ['size' => $s, 'measurements' => ['chest' => '90', 'collar' => '15']], $sizes),
                'note' => 'Measurements in cm',
                'fit_kit' => ['field' => 'collar', 'summary_fields' => ['collar', 'sleeve'], 'map' => ['14' => 'XS', '14.5' => 'S', '15.5' => 'M', '16' => 'L', '17' => 'XL']],
            ],
        ]);
    }
}
