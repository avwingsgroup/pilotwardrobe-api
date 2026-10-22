<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cloudinary asset metadata. The binary lives in Cloudinary; never in PostgreSQL.
        Schema::create('media', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('owner_id', 'users')->nullable()->index();
            $table->string('type', 30);                 // MediaType
            $table->string('visibility', 10);           // public | private
            $table->string('public_id', 255)->unique();
            $table->string('resource_type', 10)->default('image');
            $table->string('delivery_type', 20)->default('upload'); // upload | authenticated
            $table->string('format', 10)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedBigInteger('version')->nullable();
            $table->string('folder', 255)->nullable();
            $table->text('secure_url')->nullable();
            $table->string('alt', 255)->nullable();
            $table->string('placeholder', 9)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('attachable_type', 60)->nullable();
            $table->string('attachable_id', 40)->nullable();
            $table->string('collection', 30)->nullable(); // images | evidence | document_front | selfie | …
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['attachable_type', 'attachable_id', 'collection', 'position']);
        });
        DB::statement('CREATE INDEX media_unattached_idx ON media (created_at) WHERE attachable_id IS NULL AND deleted_at IS NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete();
            $table->foreign('cover_media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('label', 60)->nullable();
            $table->string('full_name', 120);
            $table->string('phone', 32);
            $table->string('line1', 200);
            $table->string('line2', 200)->nullable();
            $table->string('city', 80);
            $table->string('state', 80)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX addresses_one_default ON addresses (user_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('fit_kit_profiles', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('name', 60);
            $table->string('for_person', 60)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('unit', 10)->default('us_in');
            foreach (['jacket', 'collar', 'sleeve', 'waist', 'inseam', 'shoe', 'bag', 'apparel'] as $col) {
                $table->string($col, 20)->nullable();
            }
            $table->jsonb('preferences')->nullable();
            $table->unsignedInteger('orders_used_on')->default(0);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX fit_kit_one_default ON fit_kit_profiles (user_id) WHERE is_default');

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('category', 30);
            $table->boolean('email')->default(true);
            $table->boolean('push')->default(true);
            $table->boolean('sms')->default(false);
            $table->boolean('whatsapp')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });

        // Firebase Cloud Messaging registrations; many per user.
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->text('token');
            $table->string('platform', 10); // ios | android | web
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX device_tokens_token_unique ON device_tokens (md5(token))');

        Schema::create('follows', function (Blueprint $table) {
            $table->foreignPrefixed('follower_id', 'users', 'cascade');
            $table->foreignPrefixed('seller_id', 'users', 'cascade');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['follower_id', 'seller_id']);
            $table->index('seller_id');
        });

        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade')->unique();
            $table->string('code', 20)->unique();
            $table->string('status', 20)->default('not_started');
            $table->string('step', 30)->default('personal_details');
            // Personal details — encrypted at rest (PII).
            $table->text('legal_first_name')->nullable();
            $table->text('legal_last_name')->nullable();
            $table->text('date_of_birth')->nullable();
            $table->char('nationality', 2)->nullable();
            $table->string('document_type', 30)->nullable();
            $table->char('document_country', 2)->nullable();
            $table->text('document_number')->nullable();
            $table->date('document_expires_on')->nullable();
            $table->foreignPrefixed('document_front_media_id', 'media')->nullable();
            $table->foreignPrefixed('document_back_media_id', 'media')->nullable();
            $table->foreignPrefixed('selfie_media_id', 'media')->nullable();
            $table->string('handoff_token_hash', 64)->nullable()->unique();
            $table->timestamp('handoff_expires_at')->nullable();
            // Provider (Prembly) outcome, reduced to what reviewers need.
            $table->string('provider', 20)->nullable();
            $table->string('provider_reference', 120)->nullable();
            $table->jsonb('automated_checks')->nullable();
            $table->jsonb('extracted')->nullable();
            $table->string('checks_status', 20)->nullable(); // queued | completed | failed
            $table->text('rejection_reason')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->foreignPrefixed('reviewed_by', 'users')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('aviator_credentials', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade')->unique();
            $table->string('licence_type', 20);
            $table->text('licence_number');       // encrypted
            $table->string('licence_last4', 4)->nullable();
            $table->string('issuing_authority', 120);
            $table->string('job_title', 80)->nullable();
            $table->string('organisation', 120)->nullable();
            $table->jsonb('type_ratings')->nullable();
            $table->foreignPrefixed('licence_media_id', 'media')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignPrefixed('reviewed_by', 'users')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
            $table->dropForeign(['cover_media_id']);
        });
        foreach (['aviator_credentials', 'kyc_verifications', 'follows', 'device_tokens', 'notification_preferences',
            'fit_kit_profiles', 'addresses', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
