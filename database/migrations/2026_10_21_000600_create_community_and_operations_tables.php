<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Messaging, reviews, disputes, notifications, support, CMS, moderation reports, audit and platform settings. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('listing_id', 'listings')->nullable();
            $table->foreignPrefixed('buyer_id', 'users');
            $table->foreignPrefixed('seller_id', 'users');
            $table->string('last_message_preview', 200)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('buyer_last_read_at')->nullable();
            $table->timestamp('seller_last_read_at')->nullable();
            $table->timestamp('buyer_notified_at')->nullable();   // email digest throttle (1 / 15 min)
            $table->timestamp('seller_notified_at')->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->timestamps();
            $table->index(['buyer_id', 'last_message_at']);
            $table->index(['seller_id', 'last_message_at']);
        });
        DB::statement('CREATE UNIQUE INDEX conversations_listing_parties_unique ON conversations (listing_id, buyer_id, seller_id) WHERE listing_id IS NOT NULL');

        Schema::create('messages', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('conversation_id', 'conversations', 'cascade');
            $table->foreignPrefixed('sender_id', 'users')->nullable(); // null = system message
            $table->string('kind', 20)->default('text');               // text | offer | swap | system
            $table->text('body');
            $table->jsonb('flags')->nullable();                         // off-platform payment patterns
            $table->jsonb('context')->nullable();                       // { offer_id, swap_request_id, … }
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('order_id', 'orders');
            $table->foreignPrefixed('author_id', 'users');
            $table->foreignPrefixed('subject_id', 'users')->index();
            $table->foreignPrefixed('listing_id', 'listings')->nullable()->index();
            $table->string('role', 10); // role of the author on the order: buyer | seller
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            $table->jsonb('tags')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
            $table->unique(['order_id', 'author_id']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('disputes', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('number', 20)->unique();
            $table->foreignPrefixed('order_id', 'orders');
            $table->foreignPrefixed('buyer_id', 'users')->index();
            $table->foreignPrefixed('seller_id', 'users')->index();
            $table->foreignPrefixed('mediator_id', 'users')->nullable();
            $table->string('reason', 30);
            $table->string('requested', 30);
            $table->bigInteger('requested_amount')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('frozen_amount');
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->string('decision', 40)->nullable();
            $table->bigInteger('amount_to_buyer')->nullable();
            $table->string('policy_reference', 255)->nullable();
            $table->string('previous_order_status', 30)->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('decision_due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        DB::statement("CREATE UNIQUE INDEX disputes_one_active_per_order ON disputes (order_id) WHERE status IN ('open','awaiting_seller','under_review','appealed')");

        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('dispute_id', 'disputes', 'cascade');
            $table->foreignPrefixed('author_id', 'users')->nullable();
            $table->string('author_role', 10); // buyer | seller | mediator | system
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->index(['dispute_id', 'created_at']);
        });

        Schema::create('dispute_proposals', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('dispute_id', 'disputes', 'cascade');
            $table->foreignPrefixed('proposed_by', 'users');
            $table->string('kind', 30);       // full_refund | partial_refund | return_and_refund
            $table->string('summary', 500);
            $table->bigInteger('amount');      // amount to buyer
            $table->string('status', 20)->default('pending'); // pending | accepted | declined | superseded
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('type', 60);       // template key, e.g. order.dispatched
            $table->string('category', 30);
            $table->string('icon', 20);
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->string('link', 255)->nullable();
            $table->jsonb('actions')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('dedupe_key', 160)->nullable()->unique();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        DB::statement('CREATE INDEX notifications_unread_idx ON notifications (user_id) WHERE read_at IS NULL');

        // One row per channel delivery attempt. `dedupe_key` makes retried jobs exactly-once per channel.
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id', 40)->nullable()->index();
            $table->string('user_id', 40)->nullable()->index();
            $table->string('channel', 20);    // email | sms | whatsapp | push
            $table->string('template', 60);
            $table->string('recipient', 255)->nullable();
            $table->jsonb('payload')->nullable();             // rendered content for the channel
            $table->string('provider', 20)->nullable();
            $table->string('provider_message_id', 191)->nullable()->index();
            $table->string('status', 20)->default('queued'); // queued | sent | delivered | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('reporter_id', 'users')->nullable();
            $table->string('target_type', 20); // listing | user | conversation | message | review
            $table->string('target_id', 40);
            $table->string('reason', 60);
            $table->text('detail')->nullable();
            $table->string('severity', 10)->default('medium'); // low | medium | high | critical
            $table->string('source', 30)->default('user_report'); // user_report | automated
            $table->string('status', 20)->default('open'); // open | actioned | dismissed
            $table->foreignPrefixed('assignee_id', 'users')->nullable();
            $table->string('resolution', 20)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignPrefixed('resolved_by', 'users')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('number', 20)->unique();
            $table->foreignPrefixed('user_id', 'users')->nullable();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->string('phone', 32)->nullable();
            $table->string('topic', 30);
            $table->string('subject', 200);
            $table->text('message');
            $table->foreignPrefixed('order_id', 'orders')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('priority', 10)->default('normal');
            $table->timestamp('first_response_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('newsletter_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 254);
            $table->string('status', 20)->default('pending'); // pending | confirmed | unsubscribed
            $table->string('token_hash', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX newsletter_email_lower_unique ON newsletter_subscriptions (lower(email))');

        Schema::create('pages', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            $table->longText('body_html');       // sanitised on write
            $table->string('kind', 20)->default('page'); // page | help
            $table->string('status', 20)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hero_slides', function (Blueprint $table) {
            $table->prefixedId();
            $table->unsignedSmallInteger('position');
            $table->jsonb('published');          // live version shown on /home
            $table->jsonb('draft')->nullable();  // edits awaiting /admin/content/publish
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('faq_stats', function (Blueprint $table) {
            $table->id();
            $table->string('faq_id', 80)->unique();
            $table->string('question', 255);
            $table->string('tag', 40);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();
        });

        // Daily component health written by the scheduler from real checks (drives VC10 status).
        Schema::create('status_checks', function (Blueprint $table) {
            $table->id();
            $table->string('component', 40);
            $table->date('day');
            $table->string('state', 10); // up | degraded | down
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->timestamps();
            $table->unique(['component', 'day']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('title', 200);
            $table->text('body');
            $table->jsonb('channels');           // ["email","push","in_app"]
            $table->jsonb('audience');           // { roles, countries, kyc, … }
            $table->string('link', 255)->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled | sending | sent | failed
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignPrefixed('created_by', 'users');
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('table', 40);
            $table->jsonb('filters')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->foreignPrefixed('requested_by', 'users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->prefixedId();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('message', 255)->nullable();
            $table->boolean('notify_users')->default(true);
            $table->foreignPrefixed('created_by', 'users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->jsonb('value');
            $table->timestamps();
        });

        // Immutable audit trail (§4.5). A trigger rejects UPDATE and DELETE.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('actor_id', 40)->nullable()->index();
            $table->string('actor_name', 120)->nullable();
            $table->boolean('actor_is_system')->default(false);
            $table->string('action', 60);
            $table->string('severity', 10)->default('info'); // info | success | warning | danger
            $table->string('target_type', 30)->nullable();
            $table->string('target_id', 40)->nullable();
            $table->string('target_label', 200)->nullable();
            $table->text('detail')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pw_reject_modification() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% rows are immutable', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION pw_reject_modification();

            -- Ledger entries may only change `status` (e.g. in_escrow → completed); amounts are final.
            CREATE OR REPLACE FUNCTION pw_ledger_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'ledger_entries rows cannot be deleted';
                END IF;
                IF NEW.amount <> OLD.amount OR NEW.direction <> OLD.direction OR NEW.currency <> OLD.currency
                   OR NEW.wallet_id <> OLD.wallet_id OR NEW.type <> OLD.type THEN
                    RAISE EXCEPTION 'ledger_entries amounts are immutable';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_guard BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION pw_ledger_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_guard ON ledger_entries; DROP FUNCTION IF EXISTS pw_ledger_guard();');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_immutable ON audit_logs; DROP FUNCTION IF EXISTS pw_reject_modification();');
        foreach (['audit_logs', 'platform_settings', 'maintenance_windows', 'exports', 'broadcasts', 'status_checks', 'faq_stats',
            'hero_slides', 'pages', 'newsletter_subscriptions', 'support_tickets', 'reports', 'notification_deliveries',
            'notifications', 'dispute_proposals', 'dispute_messages', 'disputes', 'reviews', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
