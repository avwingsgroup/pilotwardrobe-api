<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\KycStatus;
use App\Enums\ListingCondition;
use App\Enums\ListingFormat;
use App\Enums\ListingStatus;
use App\Enums\MediaType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Integrations\Media\MediaStorage;
use App\Integrations\Payments\VerificationResult;
use App\Models\Address;
use App\Models\AviatorCredential;
use App\Models\Category;
use App\Models\FitKitProfile;
use App\Models\KycVerification;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Payment;
use App\Models\PayoutMethod;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Catalogue\ShippingOptions;
use App\Services\Messaging\ConversationService;
use App\Services\Orders\OrderService;
use App\Services\Payments\PaymentService;
use App\Services\Reviews\ReviewService;
use App\Services\Trading\OfferService;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Development/staging demo data mirroring the SPA mock accounts. Refuses to run in
 * production. All demo passwords are `Password1` (documented in the README).
 * Listing photos are uploaded from docs/PW-images through the configured media driver.
 */
final class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Password1';

    /** @var array<string, User> */
    private array $users = [];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must never run in production.');
        }

        $this->users();
        $this->listings();
        $this->tradingHistory();
    }

    private function users(): void
    {
        $make = function (string $key, array $attrs, ?UserRole $role = null, bool $kyc = false) {
            $user = User::query()->whereRaw('lower(email) = ?', [strtolower($attrs['email'])])->first() ?? new User;
            $user->fill($attrs + ['password' => self::PASSWORD, 'currency' => 'USD', 'locale' => 'en', 'country_code' => 'NG', 'terms_accepted_at' => now()]);
            $user->email_verified_at ??= now();
            $user->password_changed_at ??= now()->subMonths(3);
            if ($role) {
                $user->role = $role;
            }
            $user->save();
            if ($kyc) {
                KycVerification::query()->updateOrCreate(['user_id' => $user->id], [
                    'code' => ReferenceGenerator::prefixed('KY', 6), 'status' => KycStatus::Approved, 'step' => 'done',
                    'document_type' => 'passport', 'document_country' => $user->country_code,
                    'legal_first_name' => $user->first_name, 'legal_last_name' => $user->last_name,
                    'submitted_at' => now()->subMonths(6), 'reviewed_at' => now()->subMonths(6),
                    'automated_checks' => [['label' => 'Document authenticity', 'result' => 'MRZ valid', 'passed' => true], ['label' => 'Face match', 'result' => '98.4%', 'passed' => true]],
                ]);
            }

            return $this->users[$key] = $user;
        };

        $make('official', ['first_name' => 'Pilotwardrobe', 'last_name' => 'Official', 'username' => 'pilotwardrobe', 'email' => 'official@example.com',
            'seller_type' => 'official_store', 'city' => 'Lagos', 'dispatch_time' => 'wheels-up in 24h', 'response_time' => '~1h', 'response_rate' => 100,
            'bio' => 'Official Pilotwardrobe store — uniforms, epaulettes and merch, sized to your Fit Kit.', 'created_at' => now()->subYears(2)], kyc: true);
        $make('pilot', ['first_name' => 'Nehemiah', 'last_name' => 'Okoye', 'username' => 'nehemiah_o', 'email' => 'pilot@example.com', 'phone' => '+2348021184460',
            'city' => 'Lagos', 'dispatch_time' => 'ships in 2 days', 'job_title' => 'A320 First Officer', 'bio' => 'A320 FO', 'created_at' => now()->subMonths(19)], kyc: true);
        $make('aviator', ['first_name' => 'James', 'last_name' => 'Adeyemi', 'username' => 'captain-james', 'email' => 'aviator@example.com', 'phone' => '+2348031234417',
            'city' => 'Abuja', 'dispatch_time' => 'ships in 24h', 'job_title' => 'Captain', 'aircraft_type' => 'B737', 'created_at' => now()->subYears(3)], UserRole::Aviator, kyc: true);
        $make('newbie', ['first_name' => 'Ada', 'last_name' => 'Obi', 'username' => 'ada_obi', 'email' => 'newbie@example.com', 'city' => 'Lagos']);
        $make('photographer', ['first_name' => 'Tunde', 'last_name' => 'Shot Aviation', 'username' => 'tunde_shots', 'email' => 'photographer@example.com',
            'seller_type' => 'photographer', 'city' => 'Lagos', 'bio' => 'Airshow and airline photography. Prints shipped in rigid tubes.'], kyc: true);
        $make('buyer', ['first_name' => 'Kunle', 'last_name' => 'Taiwo', 'username' => 'kunle_t', 'email' => 'buyer@example.com', 'city' => 'Abuja'], kyc: true);

        AviatorCredential::query()->updateOrCreate(['user_id' => $this->users['aviator']->id], [
            'licence_type' => 'atpl', 'licence_number' => 'NCAA/ATPL/0012', 'licence_last4' => '12', 'issuing_authority' => 'NCAA (Nigeria)',
            'job_title' => 'Captain', 'organisation' => 'Air Peace', 'type_ratings' => ['A320', 'B737'], 'status' => 'approved', 'verified_at' => now()->subYear(), 'submitted_at' => now()->subYear(),
        ]);

        foreach (['pilot', 'buyer', 'aviator'] as $key) {
            $user = $this->users[$key];
            Address::query()->firstOrCreate(['user_id' => $user->id, 'label' => 'Home'], [
                'full_name' => $user->displayName(), 'phone' => $user->phone ?? '+2348000000000', 'line1' => '18 Ikoyi Crescent',
                'city' => $user->city ?? 'Lagos', 'state' => 'Lagos', 'postal_code' => '106104', 'country_code' => 'NG', 'is_default' => true,
            ]);
        }
        FitKitProfile::query()->firstOrCreate(['user_id' => $this->users['pilot']->id, 'name' => 'Uniform fit'], [
            'for_person' => 'Nehemiah', 'is_default' => true, 'unit' => 'us_in', 'jacket' => '42R', 'collar' => '15.5', 'sleeve' => '34', 'waist' => '34', 'inseam' => '32', 'shoe' => '10.5', 'bag' => 'crew',
            'preferences' => ['filter_search_by_size' => true, 'warn_outside_measurements' => true, 'use_at_checkout' => false],
        ]);
        PayoutMethod::query()->firstOrCreate(['user_id' => $this->users['pilot']->id, 'kind' => 'bank'], [
            'label' => 'GTBank · NGN', 'currency' => 'NGN', 'last4' => '0921', 'bank_code' => '058', 'bank_name' => 'GTBank', 'provider' => 'manual', 'is_default' => true,
        ]);

        // Staff (sign in with STAFF_PASSWORD_LOGIN=true locally; production uses Google SSO + security key).
        $ts = StaffRole::query()->where('slug', 'trust_safety')->first();
        $finance = StaffRole::query()->where('slug', 'finance')->first();
        $make('admin', ['first_name' => 'Amaka', 'last_name' => 'Nwosu', 'username' => 'staff_amaka', 'email' => 'admin@example.com'], UserRole::Admin)
            ->forceFill(['staff_role_id' => $ts?->id])->save();
        $make('finance', ['first_name' => 'Femi', 'last_name' => 'Bello', 'username' => 'staff_femi', 'email' => 'finance@example.com'], UserRole::Admin)
            ->forceFill(['staff_role_id' => $finance?->id])->save();
        $make('super', ['first_name' => 'Tolu', 'last_name' => 'Adeyemi', 'username' => 'staff_tolu', 'email' => 'super@example.com'], UserRole::SuperAdmin);
    }

    private function listings(): void
    {
        $catalogue = [
            // key, seller, category, title, price, condition, image(s), extra
            ['bose', 'pilot', 'anr-headsets', 'Bose A20 Aviation Headset — dual GA plugs', 74500, 'like_new', ['p-3.png', 'ANR-headset.png'], ['brand' => 'Bose', 'model' => 'A20', 'aircraft_types' => ['B737', 'A320', 'C172'], 'compare_at_price' => ['amount' => 91000, 'currency' => 'USD']]],
            ['dc_onex', 'aviator', 'anr-headsets', 'David Clark ONE-X ENC headset, carry case', 69000, 'very_good', ['p-4.png'], ['brand' => 'David Clark', 'model' => 'ONE-X']],
            ['dc_h10', 'aviator', 'passive-headsets', 'David Clark H10-13.4 passive, new gel seals', 29500, 'very_good', ['p-9.png'], ['brand' => 'David Clark', 'model' => 'H10-13.4', 'swappable' => false]],
            ['randolph', 'pilot', 'eyewear', 'Randolph Aviator CR — 55mm, bayonet temples', 21000, 'like_new', ['p-5.png'], ['brand' => 'Randolph', 'model' => 'Aviator CR', 'swappable' => false]],
            ['shirt', 'official', 'shirts', 'Pilotwardrobe Aviator Uniform Shirt — epaulette loops, wrinkle-free', 14500, 'new', ['p-6.png'], ['brand' => 'Pilotwardrobe', 'stock' => 40, 'sizes' => ['available' => ['XS', 'S', 'M', 'L'], 'unavailable' => ['XL']]]],
            ['jacket', 'official', 'jackets', "Captain's uniform jacket — 4-bar, double-breasted", 32000, 'new', ['p-0.png'], ['brand' => 'Pilotwardrobe', 'stock' => 15, 'swappable' => false, 'sizes' => ['available' => ['40R', '42R', '44R'], 'unavailable' => ['46R']]]],
            ['flightbag', 'pilot', 'flight-bags', 'Jeppesen Captain Flight Bag, leather trim', 18000, 'good', ['p-7.png', 'pilot-flight-bag.png'], ['brand' => 'Jeppesen']],
            ['chrono', 'aviator', 'watches', 'Pilot chronograph — slide rule bezel, blue dial', 41000, 'like_new', ['p-1.png'], ['brand' => 'Citizen', 'model' => 'Skyhawk']],
            ['kneeboard', 'pilot', 'kneeboards', 'Aviation kneeboard, tri-fold', 6200, 'good', ['p-2.png', 'aviation-kneeboard.png'], []],
            ['e6b', 'aviator', 'flight-computers', 'E6B flight computer, metal', 4500, 'very_good', ['E6B-flight-computer.png'], ['brand' => 'ASA']],
            ['radio', 'aviator', 'handheld-radios', 'Handheld VHF airband transceiver', 38000, 'good', ['Aircraft-Communication-Radio.png'], ['brand' => 'Icom', 'model' => 'IC-A25']],
            ['gps', 'pilot', 'gps', 'Handheld aviation GPS with mount', 52000, 'very_good', ['handheld-aviation-GPS.png'], ['brand' => 'Garmin']],
            ['oxygen', 'aviator', 'safety', 'Portable aviation oxygen system', 61000, 'like_new', ['Portable-oxygen.png'], []],
            ['multimeter', 'pilot', 'tools', 'Digital multimeter for avionics work', 7800, 'good', ['Digital-multimeter.png'], []],
            ['powerbank', 'pilot', 'power', 'Portable power bank 20,000 mAh, USB-C PD', 5200, 'like_new', ['Portable-Power-Bank.png'], []],
            ['drone', 'photographer', 'cameras', 'Compact camera drone with spare batteries', 89000, 'very_good', ['Compact -Drone.png'], []],
            ['print1', 'photographer', 'prints', 'Mountain approach — fine art print', 9500, 'new', ['Im-1.png'], ['format' => 'print', 'stock' => 25, 'swappable' => false, 'negotiable' => false]],
            ['print2', 'photographer', 'prints', 'Wingtip over the valley — fine art print', 9500, 'new', ['Im-2.png'], ['format' => 'print', 'stock' => 25, 'swappable' => false, 'negotiable' => false]],
            ['print3', 'photographer', 'prints', 'Warbird formation, B&W — fine art print', 12000, 'new', ['Im-3.png'], ['format' => 'print', 'stock' => 25, 'swappable' => false, 'negotiable' => false]],
            ['print4', 'photographer', 'prints', 'Sunrise pushback — fine art print', 12000, 'new', ['Im-4.png'], ['format' => 'print', 'stock' => 25, 'swappable' => false, 'negotiable' => false]],
        ];

        [$shipping, $freight] = ShippingOptions::build(null, ['method' => 'pilotwardrobe_freight', 'who_pays' => 'buyer', 'ships_from' => ['country_code' => 'NG', 'state' => 'Lagos', 'city' => 'Ikeja', 'airport_code' => 'LOS']], 'USD');

        foreach ($catalogue as $i => [$key, $sellerKey, $categorySlug, $title, $price, $condition, $images, $extra]) {
            $seller = $this->users[$sellerKey];
            $listing = Listing::query()->where('seller_id', $seller->id)->where('title', $title)->first() ?? new Listing;
            $listing->fill([
                'seller_id' => $seller->id,
                'category_id' => Category::query()->where('slug', $categorySlug)->value('id'),
                'title' => $title,
                'description' => $title.'. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.',
                'brand' => $extra['brand'] ?? null,
                'model' => $extra['model'] ?? null,
                'condition' => ListingCondition::from($condition),
                'format' => ListingFormat::from($extra['format'] ?? 'physical'),
                'currency' => 'USD',
                'price_amount' => $price,
                'compare_at_amount' => $extra['compare_at_price']['amount'] ?? null,
                'minimum_offer_amount' => intdiv($price * 80, 100),
                'negotiable' => $extra['negotiable'] ?? true,
                'swappable' => $extra['swappable'] ?? true,
                'stock' => $extra['stock'] ?? 1,
                'aircraft_types' => $extra['aircraft_types'] ?? [],
                'sizes' => $extra['sizes'] ?? null,
                'shipping' => $shipping,
                'freight' => $freight,
                'location' => $freight['ships_from'],
            ]);
            $listing->slug ??= Str::slug($title).'-'.Str::lower(Str::random(4));
            $listing->status = ListingStatus::Active;
            $listing->published_at ??= now()->subDays(20 - $i);
            $listing->views_count = random_int(40, 600);
            $listing->promoted_until = $key === 'bose' ? now()->addDays(7) : null;
            $listing->save();

            if ($listing->images()->count() === 0) {
                foreach ($images as $position => $file) {
                    $this->attachImage($listing, $file, $position);
                }
            }
        }
    }

    private function attachImage(Listing $listing, string $file, int $position): void
    {
        $path = base_path('docs/PW-images/'.$file);
        if (! is_file($path)) {
            return;
        }

        try {
            $id = Media::newPrefixedId();
            $publicId = 'pilotwardrobe/listings/'.$listing->seller_id.'/'.$id;
            $asset = app(MediaStorage::class)->store($path, $publicId, 'image', false);
        } catch (Throwable $e) {
            // No Cloudinary credentials (or offline): keep the listing without a photo.
            $this->command?->warn("Image {$file} not uploaded: {$e->getMessage()}");

            return;
        }

        $media = new Media([
            'owner_id' => $listing->seller_id, 'type' => MediaType::Listing, 'visibility' => 'public',
            'public_id' => $asset->publicId, 'resource_type' => 'image', 'delivery_type' => 'upload', 'format' => $asset->format,
            'width' => $asset->width, 'height' => $asset->height, 'bytes' => $asset->bytes, 'version' => $asset->version,
            'secure_url' => $asset->secureUrl, 'placeholder' => $asset->placeholder, 'alt' => $listing->title.' — photo '.($position + 1),
        ]);
        $media->id = $id;
        $media->save();
        $media->attachTo($listing, 'images', $position);
    }

    /** A paid → completed order with a review, an open offer and a conversation, created through the real services. */
    private function tradingHistory(): void
    {
        Notification::fake();
        $buyer = $this->users['buyer'];
        $orders = app(OrderService::class);

        $flightBag = Listing::query()->where('title', 'like', 'Jeppesen%')->first();
        if ($flightBag && $flightBag->status === ListingStatus::Active) {
            $order = $orders->create($buyer, ['listing_id' => $flightBag->id, 'address_id' => $buyer->addresses()->value('id')]);
            $payment = Payment::query()->create([
                'reference' => ReferenceGenerator::paymentReference(), 'payable_type' => 'order', 'payable_id' => $order->id, 'user_id' => $buyer->id,
                'gateway' => 'paystack', 'amount' => $order->total_amount, 'currency' => $order->currency, 'status' => 'pending', 'authorization_url' => 'https://checkout.paystack.com/demo',
            ]);
            app(PaymentService::class)->apply($payment, new VerificationResult(PaymentStatus::Successful, $order->total_amount, $order->currency, null, 'Visa •••• 4417'));
            $orders->dispatch($order->fresh(), $order->seller, ['carrier' => 'PW Freight · DHL Express', 'tracking_number' => 'JD014600006812345']);
            $orders->confirmDelivery($order->fresh(), $buyer);
            app(ReviewService::class)->create($order->fresh(), $buyer, ['rating' => 5, 'comment' => 'Fast dispatch, well packed. Exactly as described.', 'tags' => ['fast_dispatch', 'well_packed']]);
        }

        $bose = Listing::query()->where('title', 'like', 'Bose A20%')->first();
        if ($bose) {
            app(OfferService::class)->make($buyer, $bose, 71200, 'USD', 'Can you do $712 if I pay today?', null);
            $conversation = app(ConversationService::class)->open($buyer, $bose);
            app(ConversationService::class)->send($conversation, $buyer, 'Hi! Is the A20 still available? Does it come with the Bluetooth cable?');
            app(ConversationService::class)->send($conversation, $bose->seller, 'Yes and yes — original case, cable and the spare ear seals.');
        }
    }
}
