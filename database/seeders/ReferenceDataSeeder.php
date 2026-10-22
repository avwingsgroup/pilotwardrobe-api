<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BoostPackage;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\ExchangeRate;
use App\Models\FaqStat;
use App\Models\HeroSlide;
use App\Models\Page;
use App\Models\StaffRole;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Database\Seeder;

/**
 * Reference data the platform needs in every environment (including production):
 * staff-role bundles, the category tree, boost packages, commission rules, starter
 * exchange rates and baseline CMS content. Idempotent (updateOrCreate on natural keys).
 * Contains no credentials and no personal data.
 */
final class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->staffRoles();
        $this->categories();
        $this->pricing();
        $this->content();
    }

    private function staffRoles(): void
    {
        foreach (config('permissions.staff_roles') as $slug => $definition) {
            StaffRole::query()->updateOrCreate(['slug' => $slug], [
                'name' => $definition['name'],
                'is_system' => (bool) ($definition['system'] ?? false),
                'matrix' => is_array($definition['matrix']) ? $definition['matrix'] : array_fill_keys(array_keys(config('permissions.matrix')), 'manage'),
            ]);
        }
    }

    private function categories(): void
    {
        $alphaChart = [
            'columns' => ['Chest', 'Shoulder', 'Length', 'Sleeve', 'US alpha'],
            'rows' => [
                ['size' => 'XS', 'measurements' => ['chest' => '84', 'shoulder' => '37', 'length' => '64', 'sleeve' => '19.5', 'us alpha' => 'XS']],
                ['size' => 'S', 'measurements' => ['chest' => '88', 'shoulder' => '38.5', 'length' => '66', 'sleeve' => '20', 'us alpha' => 'S']],
                ['size' => 'M', 'measurements' => ['chest' => '96', 'shoulder' => '42', 'length' => '70', 'sleeve' => '21', 'us alpha' => 'M']],
                ['size' => 'L', 'measurements' => ['chest' => '104', 'shoulder' => '45', 'length' => '73', 'sleeve' => '22', 'us alpha' => 'L']],
                ['size' => 'XL', 'measurements' => ['chest' => '112', 'shoulder' => '48', 'length' => '76', 'sleeve' => '23', 'us alpha' => 'XL']],
            ],
            'note' => 'Measurements in cm · tolerance ±1.5 cm · Tall +5 cm length · Slim −2.5 cm chest',
            'fit_kit' => ['field' => 'collar', 'summary_fields' => ['collar', 'sleeve'],
                'map' => ['14' => 'XS', '14.5' => 'S', '15' => 'S', '15.5' => 'M', '16' => 'L', '16.5' => 'L', '17' => 'XL']],
        ];

        $tree = [
            ['slug' => 'pilotwardrobe', 'name' => 'Pilotwardrobe', 'sized' => true, 'size_chart' => $alphaChart, 'sizes' => ['XS', 'S', 'M', 'L', 'XL']],
            ['slug' => 'headsets', 'name' => 'Headsets & audio', 'children' => [
                ['slug' => 'anr-headsets', 'name' => 'ANR headsets'],
                ['slug' => 'passive-headsets', 'name' => 'Passive headsets'],
                ['slug' => 'headset-accessories', 'name' => 'Ear seals & accessories'],
            ], 'attributes' => [['key' => 'connector', 'label' => 'Connector', 'type' => 'select', 'options' => ['Dual GA', 'LEMO', 'U-174', 'XLR-5']]]],
            ['slug' => 'uniforms', 'name' => 'Uniform & apparel', 'sized' => true, 'size_chart' => $alphaChart, 'sizes' => ['XS', 'S', 'M', 'L', 'XL'], 'children' => [
                ['slug' => 'shirts', 'name' => 'Shirts', 'sized' => true, 'size_chart' => $alphaChart, 'sizes' => ['XS', 'S', 'M', 'L', 'XL']],
                ['slug' => 'jackets', 'name' => 'Jackets', 'sized' => true, 'size_chart' => ['columns' => ['Chest'], 'rows' => [], 'note' => 'Jacket sizes are chest-in-inches with fit (S/R/L).', 'fit_kit' => ['field' => 'jacket']], 'sizes' => ['38R', '40R', '42R', '44R', '46R']],
                ['slug' => 'epaulettes', 'name' => 'Epaulettes & wings'],
            ]],
            ['slug' => 'eyewear', 'name' => 'Eyewear'],
            ['slug' => 'watches', 'name' => 'Pilot watches'],
            ['slug' => 'flight-bags', 'name' => 'Flight bags'],
            ['slug' => 'kneeboards', 'name' => 'Kneeboards & EFB', 'children' => [
                ['slug' => 'flight-computers', 'name' => 'Flight computers'],
            ]],
            ['slug' => 'avionics', 'name' => 'Avionics & radios', 'children' => [
                ['slug' => 'handheld-radios', 'name' => 'Handheld radios'],
                ['slug' => 'gps', 'name' => 'GPS'],
            ]],
            ['slug' => 'tools', 'name' => 'Tools & test equipment'],
            ['slug' => 'safety', 'name' => 'Safety & survival'],
            ['slug' => 'power', 'name' => 'Power & charging'],
            ['slug' => 'cameras', 'name' => 'Cameras & drones'],
            ['slug' => 'prints', 'name' => 'Aviation photography'],
        ];

        foreach ($tree as $position => $node) {
            $parent = $this->category($node, null, $position);
            foreach ($node['children'] ?? [] as $childPosition => $child) {
                $this->category($child, $parent->id, $childPosition);
            }
        }
    }

    private function category(array $node, ?string $parentId, int $position): Category
    {
        return Category::query()->updateOrCreate(['slug' => $node['slug']], [
            'parent_id' => $parentId,
            'name' => $node['name'],
            'sized' => $node['sized'] ?? false,
            'size_chart' => $node['size_chart'] ?? null,
            'sizes' => $node['sizes'] ?? null,
            'attributes' => $node['attributes'] ?? null,
            'position' => $position,
            'is_active' => true,
        ]);
    }

    private function pricing(): void
    {
        $rules = [
            ['name' => 'Standard sale commission', 'description' => 'Charged to seller on release', 'kind' => 'sale_commission', 'scope' => 'all', 'rate_bps' => 800, 'min_amount' => 100, 'priority' => 100],
            ['name' => 'Avionics & instruments', 'description' => 'High-value category', 'kind' => 'sale_commission', 'scope' => 'categories', 'scope_values' => ['avionics'], 'rate_bps' => 600, 'cap_amount' => 12000, 'priority' => 50],
            ['name' => 'Photographer prints', 'description' => 'Digital + physical', 'kind' => 'sale_commission', 'scope' => 'seller_type', 'scope_values' => ['photographer'], 'rate_bps' => 1200, 'priority' => 50],
            ['name' => 'Swap fee', 'description' => 'Each side', 'kind' => 'swap_fee', 'scope' => 'all', 'rate_bps' => 400, 'min_amount' => 200, 'priority' => 100],
            ['name' => 'Buyer protection', 'description' => 'Added at checkout', 'kind' => 'buyer_protection', 'scope' => 'all', 'rate_bps' => 200, 'cap_amount' => 2500, 'priority' => 100],
        ];
        foreach ($rules as $rule) {
            // Category-scoped rules reference category ids, resolved from slugs here.
            if ($rule['scope'] === 'categories') {
                $rule['scope_values'] = Category::query()->whereIn('slug', $rule['scope_values'])->pluck('id')->all();
            }
            CommissionRule::query()->updateOrCreate(['name' => $rule['name']], $rule + ['currency' => 'USD', 'status' => 'live']);
        }

        foreach ([['runway', 'Runway', 3, 499, 'search'], ['jetstream', 'Jetstream', 7, 999, 'search'], ['featured_home', 'Featured on home', 7, 2499, 'home']] as $i => [$code, $name, $days, $price, $placement]) {
            BoostPackage::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => "{$name} · {$days} days", 'placement' => $placement,
                'duration_days' => $days, 'price_amount' => $price, 'currency' => 'USD', 'is_active' => true, 'position' => $i,
            ]);
        }

        // Starter rates only; production should run a rates feed or have finance maintain these.
        foreach ([['USD', 'NGN', 1532.4], ['USD', 'GHS', 15.2], ['USD', 'GBP', 0.79], ['USD', 'EUR', 0.92]] as [$base, $quote, $rate]) {
            ExchangeRate::query()->updateOrCreate(['base' => $base, 'quote' => $quote], ['rate' => $rate, 'source' => 'seed']);
        }
    }

    private function content(): void
    {
        $slides = [
            ['headline' => 'Buy, sell or swap premium aviation gear', 'subtext' => 'The trusted marketplace for pilots, mechanics and fleet operators. Funds held in escrow until goods land.',
                'button_label' => 'Shop verified gear', 'link' => '/marketplace', 'audience' => 'All users · always', 'locales' => 'EN', 'status' => 'live', 'schedule' => 'always'],
            ['headline' => 'Uniforms sized to your Fit Kit', 'subtext' => 'Save your measurements once and see what fits in every search.',
                'button_label' => 'Set up Fit Kit', 'link' => '/hangar/fit-kit', 'audience' => 'All users', 'locales' => 'EN', 'status' => 'live', 'schedule' => 'always'],
        ];
        foreach ($slides as $i => $slide) {
            HeroSlide::query()->updateOrCreate(['position' => $i + 1], ['published' => $slide, 'active' => true]);
        }

        $sanitizer = new HtmlSanitizer;
        foreach ([
            ['about', 'About Pilotwardrobe', '<p>Pilotwardrobe is the escrow-protected marketplace for aviation gear.</p>'],
            ['how-it-works', 'How it works', '<p>Buy, sell or swap. Payments stay in escrow until the buyer confirms the item landed.</p>'],
        ] as [$slug, $title, $html]) {
            Page::query()->updateOrCreate(['slug' => $slug], ['title' => $title, 'body_html' => $sanitizer->clean($html), 'status' => 'published', 'published_at' => now()]);
        }

        foreach ([
            ['confirm-landed', 'How long do I have to confirm goods landed?', 'Escrow'],
            ['payout-pending', 'Why is my payout still pending?', 'Wallet'],
            ['open-dispute', 'How do I open a dispute?', 'Disputes'],
            ['swap-fees', 'What does a swap cost?', 'Swaps'],
            ['verify-identity', 'Why do I need to verify my identity?', 'Account'],
        ] as [$id, $question, $tag]) {
            FaqStat::query()->updateOrCreate(['faq_id' => $id], ['question' => $question, 'tag' => $tag]);
        }
    }
}
