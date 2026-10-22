<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Offers, swaps, carts, promo codes, commission rules, orders. All money is bigint minor units. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('listing_id', 'listings', 'cascade');
            $table->foreignPrefixed('buyer_id', 'users');
            $table->foreignPrefixed('seller_id', 'users');
            // Who made this offer and who must answer it (a seller counter creates a child offer seller → buyer).
            $table->foreignPrefixed('proposer_id', 'users');
            $table->foreignPrefixed('recipient_id', 'users');
            $table->string('parent_offer_id', 40)->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('counter_amount')->nullable();
            $table->string('size', 20)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('recipient_read_at')->nullable();
            $table->timestamps();
            $table->index(['recipient_id', 'status']);
            $table->index(['proposer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
        Schema::table('offers', fn (Blueprint $table) => $table->foreign('parent_offer_id')->references('id')->on('offers')->nullOnDelete());
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_amount_positive CHECK (amount > 0)');
        // A buyer can have only one live offer per listing.
        DB::statement("CREATE UNIQUE INDEX offers_one_pending_per_buyer ON offers (listing_id, buyer_id) WHERE status = 'pending'");

        Schema::create('offer_events', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('offer_id', 'offers', 'cascade');
            $table->string('kind', 20);   // offer | counter | accepted | declined | cancelled | expired | view
            $table->string('label', 160);
            $table->string('detail', 255)->nullable();
            $table->foreignPrefixed('actor_id', 'users')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['offer_id', 'occurred_at']);
        });

        Schema::create('swap_requests', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('target_listing_id', 'listings');
            $table->foreignPrefixed('proposer_id', 'users');
            $table->foreignPrefixed('counterparty_id', 'users');
            $table->bigInteger('cash_adjustment_amount')->default(0); // signed: + proposer pays
            $table->char('currency', 3);
            $table->bigInteger('swap_fee_amount')->default(0);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('counterparty_read_at')->nullable();
            $table->timestamps();
            $table->index(['counterparty_id', 'status']);
            $table->index(['proposer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('swap_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('swap_request_id', 'swap_requests', 'cascade');
            $table->foreignPrefixed('listing_id', 'listings');
            $table->bigInteger('estimated_value');
            $table->unique(['swap_request_id', 'listing_id']);
        });

        Schema::create('swap_events', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('swap_request_id', 'swap_requests', 'cascade');
            $table->string('kind', 20);
            $table->string('label', 160);
            $table->string('detail', 255)->nullable();
            $table->foreignPrefixed('actor_id', 'users')->nullable();
            $table->timestamp('occurred_at');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->foreignPrefixed('listing_id', 'listings', 'cascade');
            $table->string('size', 20)->nullable();
            $table->boolean('size_from_fit_kit')->default(false);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'listing_id']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('name', 120);
            $table->string('kind', 30)->default('discount'); // discount | free_protection | boost
            $table->string('status', 20)->default('draft');  // draft | scheduled | live | ended
            $table->string('audience', 255)->nullable();
            $table->jsonb('rules')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignPrefixed('created_by', 'users')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('campaign_id', 'campaigns')->nullable();
            $table->string('code', 40);
            $table->string('discount_type', 10); // percent | fixed
            $table->unsignedInteger('discount_value'); // bps for percent, minor units for fixed
            $table->char('currency', 3)->nullable();
            $table->bigInteger('min_order_amount')->nullable();
            $table->bigInteger('max_discount_amount')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignPrefixed('created_by', 'users')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX promo_codes_code_upper_unique ON promo_codes (upper(code))');

        // AD12 commission rules. `kind` selects which charge the rule prices.
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('kind', 30)->default('sale_commission'); // sale_commission | swap_fee | buyer_protection
            $table->string('scope', 20)->default('all');            // all | categories | seller_type | country
            $table->jsonb('scope_values')->nullable();
            $table->unsignedInteger('rate_bps');
            $table->bigInteger('min_amount')->nullable();
            $table->bigInteger('cap_amount')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('status', 20)->default('live'); // live | scheduled | disabled
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['kind', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('number', 20)->unique();
            $table->foreignPrefixed('buyer_id', 'users')->index();
            $table->foreignPrefixed('seller_id', 'users')->index();
            $table->string('status', 30)->default('pending_payment');
            $table->string('status_before_hold', 30)->nullable();
            $table->string('escrow_status', 20)->default('not_funded');
            $table->string('escrow_status_before_hold', 20)->nullable();
            $table->string('kind', 20)->default('purchase'); // purchase | swap_leg
            $table->foreignPrefixed('offer_id', 'offers')->nullable();
            $table->foreignPrefixed('swap_request_id', 'swap_requests')->nullable()->index();
            $table->foreignPrefixed('promo_code_id', 'promo_codes')->nullable();

            $table->char('currency', 3);
            $table->bigInteger('items_amount');
            $table->bigInteger('freight_amount')->default(0);
            $table->bigInteger('buyer_protection_amount')->default(0);
            $table->bigInteger('duties_amount')->nullable();
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('swap_fee_amount')->default(0);
            $table->bigInteger('cash_adjustment_amount')->default(0);
            $table->bigInteger('total_amount');
            $table->unsignedInteger('platform_fee_bps')->default(0);
            $table->bigInteger('platform_fee_amount')->default(0);
            $table->bigInteger('seller_net_amount')->default(0);
            $table->bigInteger('refunded_amount')->default(0);

            $table->jsonb('shipping_address')->nullable(); // snapshot
            $table->jsonb('shipping_option')->nullable();  // snapshot
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->jsonb('route')->nullable();
            $table->timestamp('estimated_delivery_from')->nullable();
            $table->timestamp('estimated_delivery_to')->nullable();

            $table->string('risk', 10)->default('low');
            $table->jsonb('risk_signals')->nullable();
            $table->string('hold_reason', 255)->nullable();

            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('dispatch_due_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_release_at')->nullable();
            $table->timestamp('dispute_window_ends_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Seller proceeds wait in `pending_release` until the dispute window / new-seller hold ends.
            $table->timestamp('funds_available_at')->nullable();
            $table->timestamp('funds_settled_at')->nullable();
            $table->timestamp('delivered_reminder_sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['escrow_status']);
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment','paid','awaiting_dispatch','in_transit','out_for_delivery','delivered','completed','cancelled','disputed','refunded','partially_refunded','on_hold'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_escrow_check CHECK (escrow_status IN ('not_funded','held','frozen','on_hold','releasing','released','refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_nonneg CHECK (total_amount >= 0 AND refunded_amount >= 0 AND refunded_amount <= total_amount)');
        DB::statement("CREATE INDEX orders_pending_expiry_idx ON orders (reserved_until) WHERE status = 'pending_payment'");
        DB::statement("CREATE INDEX orders_auto_release_idx ON orders (auto_release_at) WHERE status = 'delivered'");
        DB::statement('CREATE INDEX orders_funds_pending_idx ON orders (funds_available_at) WHERE funds_settled_at IS NULL AND funds_available_at IS NOT NULL');

        Schema::create('order_items', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('order_id', 'orders', 'cascade');
            $table->foreignPrefixed('listing_id', 'listings');
            $table->string('title', 140);
            $table->string('listing_slug', 160);
            $table->foreignPrefixed('image_media_id', 'media')->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('format', 10)->default('physical');
            $table->string('size', 20)->nullable();
            $table->boolean('size_from_fit_kit')->default(false);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('unit_price');
            $table->bigInteger('total');
            $table->timestamps();
        });

        // Order timeline (tracking steps, status changes).
        Schema::create('order_events', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('order_id', 'orders', 'cascade');
            $table->string('code', 40);
            $table->string('label', 160);
            $table->string('detail', 255)->nullable();
            $table->foreignPrefixed('actor_id', 'users')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['order_id', 'occurred_at']);
        });

        Schema::create('download_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('order_id', 'orders', 'cascade');
            $table->foreignPrefixed('media_id', 'media');
            $table->foreignPrefixed('user_id', 'users');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['download_grants', 'order_events', 'order_items', 'orders', 'commission_rules', 'promo_codes', 'campaigns',
            'cart_items', 'swap_events', 'swap_request_items', 'swap_requests', 'offer_events', 'offers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
