<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListingCondition;
use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Media;
use App\Models\User;
use App\Services\Catalogue\ShippingOptions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        $title = Str::title(fake()->words(4, true)).' headset';
        [$shipping, $freight] = ShippingOptions::build(null, ['method' => 'pilotwardrobe_freight', 'who_pays' => 'buyer'], 'USD');

        return [
            'seller_id' => User::factory()->kycApproved(),
            'category_id' => Category::factory(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'title' => $title,
            'description' => 'Well looked after and stored in a padded case between flights. Photos show the actual item.',
            'brand' => fake()->randomElement(['Bose', 'David Clark', 'Lightspeed']),
            'model' => 'A'.fake()->numberBetween(10, 40),
            'condition' => ListingCondition::LikeNew,
            'format' => 'physical',
            'currency' => 'USD',
            'price_amount' => 74500,
            'minimum_offer_amount' => 59600,
            'negotiable' => true,
            'swappable' => true,
            'stock' => 1,
            'shipping' => $shipping,
            'freight' => $freight,
            'location' => ['country_code' => 'NG', 'state' => 'Lagos', 'city' => 'Ikeja', 'airport_code' => 'LOS'],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Listing $listing) {
            if ($listing->status !== ListingStatus::Draft && $listing->images()->count() === 0) {
                Media::factory()->create(['owner_id' => $listing->seller_id])->attachTo($listing, 'images', 0);
            }
        });
    }

    public function status(ListingStatus $status): static
    {
        return $this->afterMaking(function (Listing $listing) use ($status) {
            $listing->status = $status;
            if (in_array($status, [ListingStatus::Active, ListingStatus::Reserved, ListingStatus::Sold], true)) {
                $listing->published_at ??= now()->subDays(fake()->numberBetween(0, 20));
            }
        });
    }

    public function active(): static
    {
        return $this->status(ListingStatus::Active);
    }

    public function draft(): static
    {
        return $this->status(ListingStatus::Draft);
    }
}
