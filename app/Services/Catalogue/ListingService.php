<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\AviatorStatus;
use App\Enums\ListingCondition;
use App\Enums\ListingFormat;
use App\Enums\ListingStatus;
use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Jobs\Catalogue\NotifyPriceDrop;
use App\Models\Category;
use App\Models\Listing;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\PlatformSettings;
use App\Support\Money\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Listing lifecycle for sellers (V6 wizard, VB3/VB4): drafts, publishing, moderation
 * routing, the KYC live-listing cap and every seller state action (handoff §8.1).
 */
final class ListingService
{
    /** Fields whose change on a live listing sends it back to moderation (when moderation applies). */
    private const MATERIAL_FIELDS = ['title', 'description', 'category_id', 'brand', 'model', 'condition'];

    public function __construct(
        private readonly MediaService $media,
        private readonly PlatformSettings $settings,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function createDraft(User $seller, array $data): Listing
    {
        return DB::transaction(function () use ($seller, $data) {
            $listing = new Listing(['seller_id' => $seller->id, 'currency' => $seller->currency ?: 'USD']);
            $listing->slug = $this->uniqueSlug($data['title'] ?? 'listing');
            $this->fill($listing, $seller, $data);
            $listing->save();
            $this->syncImages($listing, $seller, $data);

            return $listing;
        });
    }

    /** Autosave / edit. Returns the refreshed listing. */
    public function update(Listing $listing, User $seller, array $data): Listing
    {
        if (in_array($listing->status, [ListingStatus::Sold, ListingStatus::Swapped, ListingStatus::Removed, ListingStatus::Rejected], true)) {
            throw ApiException::invalidTransition('This listing can no longer be edited.');
        }

        return DB::transaction(function () use ($listing, $seller, $data) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $oldPrice = $listing->price_amount;
            $wasLive = $listing->status === ListingStatus::Active;

            if ($listing->status === ListingStatus::Draft && isset($data['title']) && $data['title'] !== $listing->title) {
                $listing->slug = $this->uniqueSlug($data['title'], $listing->id);
            }

            $this->fill($listing, $seller, $data);
            $imagesChanged = $this->syncImages($listing, $seller, $data);

            if ($wasLive && ($imagesChanged || $listing->isDirty(self::MATERIAL_FIELDS)) && ! $this->autoApproves($seller)) {
                $listing->status = ListingStatus::PendingReview;
                $listing->submitted_at = now();
            }
            $listing->save();

            if ($wasLive && $oldPrice !== null && $listing->price_amount !== null && $listing->price_amount < $oldPrice) {
                NotifyPriceDrop::dispatch($listing->id, $oldPrice)->afterCommit();
            }

            return $listing->fresh();
        });
    }

    /** `POST /listings/{id}/publish` */
    public function publish(Listing $listing, User $seller): Listing
    {
        $listing->status->assertCanTransitionTo(ListingStatus::Active, 'Only drafts can be published.');
        $this->assertPublishable($listing);
        $this->assertWithinLiveCap($seller, $listing);

        $listing = DB::transaction(function () use ($listing, $seller) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $listing->submitted_at = now();

            if ($this->autoApproves($seller)) {
                $listing->status = ListingStatus::Active;
                $listing->published_at = now();
            } else {
                $listing->status = ListingStatus::PendingReview;
            }
            $listing->save();
            $this->audit->log('LISTING.SUBMITTED', $listing, $listing->status->value, actor: $seller);

            return $listing;
        });

        $this->notifications->notify($seller, $listing->status === ListingStatus::Active ? 'listing.published' : 'listing.in_review', [
            'title' => $listing->title, 'slug' => $listing->slug, 'listing_id' => $listing->id,
        ], 'listing.publish:'.$listing->id.':'.$listing->status->value);

        return $listing;
    }

    /**
     * Seller state actions. Allowed sources follow the contract:
     * pause←active, resume←paused|unavailable, archive←active|paused|unavailable, mark-sold←active|reserved|paused,
     * mark-unavailable←active, relist←sold|archived, resubmit←needs_changes.
     */
    public function transition(Listing $listing, User $seller, string $action): Listing
    {
        return DB::transaction(function () use ($listing, $seller, $action) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $from = $listing->status;

            [$allowed, $to] = match ($action) {
                'pause' => [[ListingStatus::Active], ListingStatus::Paused],
                'resume' => [[ListingStatus::Paused, ListingStatus::Unavailable], ListingStatus::Active],
                'archive' => [[ListingStatus::Active, ListingStatus::Paused, ListingStatus::Unavailable], ListingStatus::Archived],
                'mark-sold' => [[ListingStatus::Active, ListingStatus::Reserved, ListingStatus::Paused], ListingStatus::Sold],
                'mark-unavailable' => [[ListingStatus::Active], ListingStatus::Unavailable],
                'relist' => [[ListingStatus::Sold, ListingStatus::Archived], ListingStatus::Active],
                'resubmit' => [[ListingStatus::NeedsChanges], ListingStatus::PendingReview],
                default => throw ApiException::notFound(),
            };

            if (! in_array($from, $allowed, true) || ! $from->canTransitionTo($to)) {
                throw ApiException::invalidTransition("You can't {$this->verb($action)} a listing that is ".str_replace('_', ' ', $from->value).'.');
            }

            if ($action === 'mark-sold' && $listing->reserved_quantity > 0) {
                throw ApiException::invalidTransition('This listing has an order awaiting payment.');
            }

            if (in_array($action, ['relist', 'resume'], true)) {
                $this->assertWithinLiveCap($seller, $listing);
                if ($action === 'relist' && ! $this->autoApproves($seller)) {
                    $to = ListingStatus::PendingReview;
                }
                if ($listing->stock < 1) {
                    $listing->stock = 1;
                }
            }
            if ($action === 'resubmit') {
                $this->assertPublishable($listing);
                $listing->submitted_at = now();
            }

            $listing->status = $to;
            if ($to === ListingStatus::Active) {
                $listing->published_at ??= now();
                $listing->reserved_for_user_id = null;
                $listing->reserved_until = null;
                $listing->reserved_reason = null;
            }
            $listing->save();

            $this->audit->log('LISTING.'.strtoupper(str_replace('-', '_', $action)), $listing, "{$from->value} → {$to->value}", actor: $seller);

            return $listing;
        });
    }

    /** `DELETE /listings/{id}` — drafts, archived and needs-changes listings without orders only. */
    public function delete(Listing $listing, User $seller): void
    {
        if (! in_array($listing->status, [ListingStatus::Draft, ListingStatus::Archived, ListingStatus::NeedsChanges, ListingStatus::Rejected], true)) {
            throw ApiException::invalidTransition('Archive the listing before deleting it.');
        }
        if (OrderItem::query()->where('listing_id', $listing->id)->exists()) {
            throw ApiException::invalidTransition('Listings with orders cannot be deleted. Archive it instead.');
        }

        DB::transaction(function () use ($listing, $seller) {
            foreach ($listing->images()->get() as $image) {
                $this->media->delete($image);
            }
            $listing->delete();
            $this->audit->log('LISTING.DELETED', $listing, null, 'warning', actor: $seller);
        });
    }

    /** Seller reserves an active listing for a buyer (Messages → "Reserve for buyer"). */
    public function reserveFor(Listing $listing, User $seller, User $buyer, int $hours = 24, string $reason = 'seller'): void
    {
        DB::transaction(function () use ($listing, $seller, $buyer, $hours, $reason) {
            $listing = Listing::query()->lockForUpdate()->findOrFail($listing->id);
            $listing->status->assertCanTransitionTo(ListingStatus::Reserved, 'Only live listings can be reserved.');
            if ($buyer->id === $listing->seller_id) {
                throw ApiException::validation(['buyer_id' => 'You cannot reserve your own listing for yourself.']);
            }

            $listing->forceFill([
                'status' => ListingStatus::Reserved,
                'reserved_for_user_id' => $buyer->id,
                'reserved_until' => now()->addHours($hours),
                'reserved_reason' => $reason,
            ])->save();

            $this->audit->log('LISTING.RESERVED', $listing, "For {$buyer->id} ({$reason})", actor: $seller);
        });
    }

    /** Releases an exclusive reservation (offer expired, swap cancelled, order cancelled). */
    public function releaseReservation(Listing $listing): void
    {
        if ($listing->status === ListingStatus::Reserved) {
            $listing->forceFill([
                'status' => ListingStatus::Active,
                'reserved_for_user_id' => null,
                'reserved_until' => null,
                'reserved_reason' => null,
            ])->save();
        }
    }

    public function autoApproves(User $seller): bool
    {
        $rules = $this->settings->get('moderation', []);

        return ($rules['auto_approve_official_stores'] ?? false) && $seller->isOfficialStore()
            || ($rules['auto_approve_verified_aviators'] ?? false) && ($seller->isAviator() || $seller->aviatorCredential?->status === AviatorStatus::Approved)
            || ($rules['auto_approve_kyc_verified'] ?? false) && $seller->isKycApproved();
    }

    public function assertWithinLiveCap(User $seller, Listing $listing): void
    {
        if ($seller->isKycApproved() || $seller->hasPermission('listings.unlimited')) {
            return;
        }

        $live = Listing::query()->where('seller_id', $seller->id)->where('id', '!=', $listing->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, ListingStatus::live()))->count();

        if ($live >= (int) config('platform.listings.unverified_live_cap')) {
            throw ApiException::kycRequired('Verify your identity to have more than 3 live listings.');
        }
    }

    public function assertPublishable(Listing $listing): void
    {
        $errors = [];
        if (mb_strlen(trim($listing->title)) < 5) {
            $errors['title'] = 'Add a title of at least 5 characters.';
        }
        if (mb_strlen(trim((string) $listing->description)) < 20) {
            $errors['description'] = 'Describe the item in at least 20 characters.';
        }
        if ($listing->category_id === null) {
            $errors['category_id'] = 'Choose a category.';
        }
        if ($listing->format !== ListingFormat::Digital && $listing->condition === null) {
            $errors['condition'] = 'Choose a condition.';
        }
        if (($listing->price_amount ?? 0) <= 0) {
            $errors['price.amount'] = 'Set a price.';
        }
        if ($listing->images()->count() === 0) {
            $errors['media_ids'] = 'Add at least one photo.';
        }
        if ($listing->format !== ListingFormat::Digital && empty($listing->shipping)) {
            $errors['shipping'] = 'Choose how the item will be delivered.';
        }
        if ($listing->format === ListingFormat::Digital && $listing->downloads()->count() === 0) {
            $errors['download_media_ids'] = 'Upload the file buyers will download.';
        }
        if ($listing->category?->sized && empty($listing->sizes['available'])) {
            $errors['sizes.available'] = 'Add at least one available size.';
        }
        if ($listing->negotiable && $listing->minimum_offer_amount !== null && $listing->minimum_offer_amount > (int) $listing->price_amount) {
            $errors['minimum_offer.amount'] = 'The minimum offer must be below the asking price.';
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }

    /** @param array<string, mixed> $data */
    private function fill(Listing $listing, User $seller, array $data): void
    {
        $simple = ['title', 'description', 'brand', 'model', 'serial_number', 'year_purchased', 'negotiable', 'swappable',
            'auto_decline_below_minimum', 'attributes', 'tags', 'licensing'];
        foreach ($simple as $field) {
            if (array_key_exists($field, $data)) {
                $listing->{$field} = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
            }
        }

        if (array_key_exists('category_id', $data)) {
            $listing->category_id = $data['category_id'] ? $this->resolveCategory((string) $data['category_id'])->id : null;
        }
        if (array_key_exists('condition', $data)) {
            $listing->condition = $data['condition'] ? ListingCondition::from($data['condition']) : null;
        }
        if (array_key_exists('format', $data)) {
            $listing->format = ListingFormat::from($data['format']);
        }
        if (array_key_exists('aircraft_types', $data)) {
            $listing->aircraft_types = array_values(array_unique(array_map(fn ($t) => strtoupper(trim((string) $t)), $data['aircraft_types'] ?? [])));
        }
        if (array_key_exists('stock', $data) && $data['stock'] !== null) {
            $listing->stock = max((int) $data['stock'], $listing->reserved_quantity);
        }

        $currency = $data['price']['currency'] ?? $data['currency'] ?? null;
        if ($currency !== null) {
            $currency = Currency::from(strtoupper($currency))->value;
            if ($listing->exists && $listing->status !== ListingStatus::Draft && $currency !== $listing->currency) {
                throw ApiException::validation(['price.currency' => 'The currency of a published listing cannot be changed.']);
            }
            $listing->currency = $currency;
        }
        foreach (['price' => 'price_amount', 'compare_at_price' => 'compare_at_amount', 'minimum_offer' => 'minimum_offer_amount'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $listing->{$column} = isset($data[$input]['amount']) ? (int) $data[$input]['amount'] : null;
            }
        }
        if ($listing->negotiable && $listing->minimum_offer_amount === null && $listing->price_amount) {
            $listing->minimum_offer_amount = intdiv($listing->price_amount * (int) config('platform.listings.minimum_offer_default_bps'), 10000);
        }

        if (array_key_exists('sizes', $data)) {
            $sizes = $data['sizes'] ?? [];
            $listing->sizes = [
                'available' => array_values(array_unique(array_map('strval', $sizes['available'] ?? []))),
                'unavailable' => array_values(array_unique(array_map('strval', $sizes['unavailable'] ?? []))),
            ];
        }

        if (array_key_exists('location', $data)) {
            $listing->location = $data['location'] ? array_intersect_key($data['location'], array_flip(['country_code', 'state', 'city', 'airport_code'])) : null;
        }

        if (array_key_exists('freight', $data) || array_key_exists('shipping', $data)) {
            [$shipping, $freight, $free] = ShippingOptions::build($data['shipping'] ?? null, $data['freight'] ?? $listing->freight, $listing->currency);
            $listing->shipping = $shipping;
            $listing->freight = $freight;
            $listing->free_delivery = $free;
            if (! empty($freight['ships_from']) && empty($listing->location)) {
                $listing->location = $freight['ships_from'];
            }
        }
    }

    /** Attaches `media_ids` (ordered) as listing photos and `download_media_ids` for digital goods. */
    private function syncImages(Listing $listing, User $seller, array $data): bool
    {
        $changed = false;

        foreach (['media_ids' => ['images', [MediaType::Listing]], 'download_media_ids' => ['downloads', [MediaType::Listing, MediaType::Content]]] as $field => [$collection, $types]) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $ids = array_slice(array_values($data[$field] ?? []), 0, (int) config('platform.listings.max_images'));
            $media = $this->media->claim($seller, $ids, $types, $field, $listing);

            $current = $listing->{$collection}()->pluck('id')->all();
            $removed = array_diff($current, $ids);
            foreach ($listing->{$collection}()->whereIn('id', $removed)->get() as $old) {
                $this->media->delete($old);
            }
            foreach ($media as $position => $item) {
                if ($collection === 'images' && $item->alt === null) {
                    $item->alt = mb_substr($listing->title.' — photo '.($position + 1), 0, 255);
                }
                $item->attachTo($listing, $collection, $position);
            }
            $changed = $changed || $current !== $ids;
        }

        return $changed;
    }

    private function resolveCategory(string $idOrSlug): Category
    {
        $category = Category::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('id', $idOrSlug)->orWhere('slug', $idOrSlug))->first();

        return $category ?? throw ApiException::validation(['category_id' => 'Choose a valid category.']);
    }

    private function uniqueSlug(string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug(Str::limit($title, 100, '')) ?: 'listing';
        for ($i = 0; $i < 10; $i++) {
            $slug = $base.'-'.Str::lower(Str::random(4));
            $exists = Listing::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
            if (! $exists) {
                return $slug;
            }
        }

        return $base.'-'.Str::lower(Str::random(10));
    }

    private function verb(string $action): string
    {
        return match ($action) {
            'mark-sold' => 'mark as sold',
            'mark-unavailable' => 'mark as unavailable',
            default => $action,
        };
    }
}
