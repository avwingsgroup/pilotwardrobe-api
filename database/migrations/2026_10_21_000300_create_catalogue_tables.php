<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('parent_id', 40)->nullable()->index();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->foreignPrefixed('image_media_id', 'media')->nullable();
            $table->boolean('sized')->default(false);
            $table->jsonb('attributes')->nullable();   // [{ key, label, type, options? }]
            $table->jsonb('size_chart')->nullable();   // { columns, rows, note }
            $table->jsonb('sizes')->nullable();        // allowed size labels
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('listings_count')->default(0);
            $table->timestamps();
        });
        Schema::table('categories', fn (Blueprint $table) => $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete());

        Schema::create('listings', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('seller_id', 'users')->index();
            $table->foreignPrefixed('category_id', 'categories')->nullable()->index();
            $table->string('slug', 160)->unique();
            $table->string('title', 140)->default('');
            $table->text('description')->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('format', 10)->default('physical');
            $table->string('status', 20)->default('draft');

            $table->char('currency', 3)->default('USD');
            $table->bigInteger('price_amount')->nullable();
            $table->bigInteger('compare_at_amount')->nullable();
            $table->bigInteger('minimum_offer_amount')->nullable();
            $table->boolean('negotiable')->default(false);
            $table->boolean('swappable')->default(false);
            $table->boolean('auto_decline_below_minimum')->default(false);

            // Stock: `stock` units remain; `reserved_quantity` are held by unpaid orders.
            $table->unsignedInteger('stock')->default(1);
            $table->unsignedInteger('reserved_quantity')->default(0);
            // Exclusive reservation (accepted offer, accepted swap, seller reserve from Messages).
            $table->foreignPrefixed('reserved_for_user_id', 'users')->nullable();
            $table->timestamp('reserved_until')->nullable();
            $table->string('reserved_reason', 20)->nullable(); // offer | swap | seller

            $table->jsonb('attributes')->nullable();      // { key: value }
            $table->jsonb('tags')->nullable();
            $table->jsonb('aircraft_types')->nullable();
            $table->jsonb('sizes')->nullable();           // { available: [], unavailable: [] }
            $table->string('serial_number', 80)->nullable();
            $table->string('year_purchased', 4)->nullable();
            $table->jsonb('location')->nullable();        // { country_code, state, city, airport_code }
            $table->jsonb('shipping')->nullable();        // [ShippingOption]
            $table->jsonb('freight')->nullable();         // { method, ships_from, parcel_size, weight_kg, who_pays }
            $table->boolean('free_delivery')->default(false);
            $table->jsonb('licensing')->nullable();       // photographer prints / digital licensing block

            $table->timestamp('promoted_until')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);
            $table->unsignedInteger('offers_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->nullable();

            $table->text('moderation_note')->nullable();
            $table->string('moderation_flags', 255)->nullable();
            $table->foreignPrefixed('moderated_by', 'users')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['seller_id', 'status']);
            $table->index(['category_id', 'status', 'price_amount']);
        });

        DB::statement("ALTER TABLE listings ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
            setweight(to_tsvector('simple', coalesce(brand, '') || ' ' || coalesce(model, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(description, '')), 'C')
        ) STORED");
        DB::statement('CREATE INDEX listings_search_vector_idx ON listings USING GIN (search_vector)');
        DB::statement("CREATE INDEX listings_public_idx ON listings (published_at DESC) WHERE status IN ('active','reserved') AND deleted_at IS NULL");
        DB::statement('CREATE INDEX listings_promoted_idx ON listings (promoted_until) WHERE promoted_until IS NOT NULL');
        DB::statement('CREATE INDEX listings_aircraft_types_idx ON listings USING GIN (aircraft_types)');
        DB::statement("ALTER TABLE listings ADD CONSTRAINT listings_status_check CHECK (status IN ('draft','pending_review','needs_changes','active','paused','reserved','unavailable','sold','swapped','archived','rejected','removed'))");
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_price_nonneg CHECK (price_amount IS NULL OR price_amount >= 0)');
        DB::statement('ALTER TABLE listings ADD CONSTRAINT listings_reserved_le_stock CHECK (reserved_quantity <= stock)');

        Schema::create('favorites', function (Blueprint $table) {
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->foreignPrefixed('listing_id', 'listings', 'cascade');
            $table->bigInteger('price_at_save')->nullable(); // for price-drop notifications
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'listing_id']);
            $table->index('listing_id');
        });

        Schema::create('boost_packages', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('placement', 30); // search | home
            $table->unsignedSmallInteger('duration_days');
            $table->bigInteger('price_amount');
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('listing_promotions', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('listing_id', 'listings', 'cascade');
            $table->foreignPrefixed('boost_package_id', 'boost_packages');
            $table->foreignPrefixed('user_id', 'users');
            $table->string('status', 20)->default('pending_payment'); // pending_payment | active | expired | cancelled
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        foreach (['listing_promotions', 'boost_packages', 'favorites', 'listings', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
