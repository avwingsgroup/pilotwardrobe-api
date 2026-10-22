<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts, authentication state and RBAC.
 *
 * Ids are opaque prefixed strings (see App\Support\Ids\HasPrefixedId). Email and
 * username uniqueness is case-insensitive via unique indexes on lower(...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->boolean('is_system')->default(false);
            $table->jsonb('matrix');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('username', 40);
            $table->string('email', 254);
            $table->string('phone', 32)->nullable();
            $table->string('password')->nullable();
            $table->string('role', 20)->default('user');
            $table->string('status', 30)->default('active');
            $table->foreignPrefixed('staff_role_id', 'staff_roles')->nullable()->index();
            $table->char('country_code', 2)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('locale', 10)->default('en');
            $table->text('bio')->nullable();
            $table->string('avatar_media_id', 40)->nullable();
            $table->string('cover_media_id', 40)->nullable();

            // Public seller profile
            $table->string('seller_type', 20)->default('individual'); // individual | photographer | official_store
            $table->string('job_title', 80)->nullable();
            $table->string('aircraft_type', 40)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 80)->nullable();
            $table->string('dispatch_time', 60)->nullable();
            $table->unsignedSmallInteger('response_rate')->nullable();
            $table->string('response_time', 40)->nullable();

            // Denormalised counters, maintained transactionally by the services that change them.
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('sales_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->jsonb('rating_breakdown')->default(DB::raw("'{\"1\":0,\"2\":0,\"3\":0,\"4\":0,\"5\":0}'::jsonb"));

            // Security
            $table->timestamp('email_verified_at')->nullable();
            $table->text('two_factor_secret')->nullable();            // encrypted
            $table->text('two_factor_recovery_codes')->nullable();    // encrypted JSON of hashes
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedInteger('permissions_version')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->string('signup_method', 20)->default('email');
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();

            // Trust & safety
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->jsonb('limits')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'status']);
            $table->index('created_at');
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('user','aviator','admin','super_admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','restricted','suspended','deactivated','pending_verification'))");

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('permission', 60);
            $table->string('effect', 10); // grant | revoke
            $table->foreignPrefixed('granted_by', 'users')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'permission']);
        });
        DB::statement("ALTER TABLE user_permission_overrides ADD CONSTRAINT upo_effect_check CHECK (effect IN ('grant','revoke'))");

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('provider', 20);
            $table->string('provider_user_id', 191);
            $table->string('email', 254)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id', 40)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // Email OTPs, SMS 2FA codes and similar short-lived secrets. Only hashes are stored.
        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('purpose', 30); // email_verification | two_factor_sms | withdrawal
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose', 'consumed_at']);
        });

        // One row per signed-in device (cookie session or API token) — drives `/me/security` sessions.
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('session_id')->nullable()->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('device', 120)->nullable();
            $table->string('location', 120)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->boolean('is_staff')->default(false);
            $table->boolean('is_impersonation')->default(false);
            $table->foreignPrefixed('impersonator_id', 'users')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        // "Trust this device for 30 days" after 2FA. Only a hash of the cookie value is stored.
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('token_hash', 64)->unique();
            $table->string('device', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('fingerprint', 64);
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['user_id', 'fingerprint']);
        });

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('name', 80)->nullable();
            $table->text('credential_id');        // base64url
            $table->text('public_key');           // PEM
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX webauthn_credential_id_unique ON webauthn_credentials (md5(credential_id))');

        Schema::create('invitations', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('kind', 10); // user | staff
            $table->string('email', 254);
            $table->string('role', 20)->default('user');
            $table->foreignPrefixed('staff_role_id', 'staff_roles')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignPrefixed('invited_by', 'users')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        Schema::create('user_notes', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->foreignPrefixed('author_id', 'users');
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['user_notes', 'invitations', 'webauthn_credentials', 'known_devices', 'trusted_devices', 'auth_sessions',
            'one_time_codes', 'sessions', 'password_reset_tokens', 'social_accounts', 'user_permission_overrides', 'users', 'staff_roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
