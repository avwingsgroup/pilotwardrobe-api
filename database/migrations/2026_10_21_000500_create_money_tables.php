<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payments, refunds, webhooks, wallets (append-only ledger + locked balance rows),
 * payout methods, payouts, idempotency keys and dual-control approvals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('reference', 40)->unique();         // our merchant reference sent to the gateway
            $table->string('payable_type', 30);                  // order | listing_promotion
            $table->string('payable_id', 40);
            $table->foreignPrefixed('user_id', 'users')->index();
            $table->string('gateway', 20);
            $table->string('gateway_reference', 191)->nullable(); // gateway's own transaction/session id
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('refunded_amount')->default(0);
            $table->bigInteger('gateway_fee_amount')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('authorization_url')->nullable();
            $table->string('return_url', 500)->nullable();
            $table->string('method_label', 80)->nullable();      // "Visa •••• 4417"
            $table->string('failure_reason', 255)->nullable();
            $table->jsonb('gateway_metadata')->nullable();       // sanitised — never card data
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX payments_gateway_reference_unique ON payments (gateway, gateway_reference) WHERE gateway_reference IS NOT NULL');
        // Only one open payment attempt per payable + gateway (initialize is idempotent while pending).
        DB::statement("CREATE UNIQUE INDEX payments_one_open_per_gateway ON payments (payable_type, payable_id, gateway) WHERE status IN ('pending','processing')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0 AND refunded_amount >= 0 AND refunded_amount <= amount)');

        Schema::create('refunds', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('reference', 40)->unique();
            $table->foreignPrefixed('payment_id', 'payments');
            $table->foreignPrefixed('order_id', 'orders')->nullable()->index();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending'); // pending | processing | succeeded | failed
            $table->string('reason', 60)->nullable();
            $table->text('note')->nullable();
            $table->string('gateway_refund_id', 191)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignPrefixed('requested_by', 'users')->nullable();
            $table->foreignPrefixed('approved_by', 'users')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');

        // Raw inbound webhooks: verified, deduplicated on (provider, event_id), processed asynchronously.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('provider', 20);
            $table->string('event_id', 191);
            $table->string('event_type', 120)->nullable();
            $table->string('reference', 191)->nullable()->index();
            $table->boolean('signature_valid')->default(false);
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable();
            $table->string('status', 20)->default('received'); // received | processing | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->char('currency', 3);
            $table->bigInteger('available_amount')->default(0);
            $table->bigInteger('escrow_amount')->default(0);
            $table->bigInteger('pending_release_amount')->default(0);
            $table->bigInteger('reserved_amount')->default(0); // pending withdrawals
            $table->bigInteger('lifetime_earnings_amount')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'currency']);
        });
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_nonneg CHECK (available_amount >= 0 AND escrow_amount >= 0 AND reserved_amount >= 0 AND pending_release_amount >= 0)');

        // Append-only. Updates/deletes are blocked by trigger except for the `status` lifecycle column.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('wallet_id', 'wallets');
            $table->foreignPrefixed('user_id', 'users')->index();
            $table->string('type', 30);       // sale | withdrawal | refund | fee | adjustment | payout_reversal | swap_adjustment
            $table->string('direction', 6);   // credit | debit
            $table->string('bucket', 20);     // escrow | available | reserved
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20);     // in_escrow | pending | completed | failed | reversed
            $table->string('description', 200);
            $table->string('detail', 255)->nullable();
            $table->foreignPrefixed('order_id', 'orders')->nullable()->index();
            $table->string('payout_id', 40)->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'occurred_at']);
        });
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_amount_positive CHECK (amount > 0)');

        // Idempotency markers for bucket-to-bucket moves that are not ledger rows (escrow → pending → available).
        Schema::create('wallet_movements', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->string('wallet_id', 40)->index();
            $table->string('order_id', 40)->nullable()->index();
            $table->string('payout_id', 40)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('payout_methods', function (Blueprint $table) {
            $table->prefixedId();
            $table->foreignPrefixed('user_id', 'users', 'cascade');
            $table->string('kind', 20);          // bank | mobile_money | paypal | wise
            $table->string('label', 80);
            $table->char('currency', 3);
            $table->char('country_code', 2)->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('bank_code', 20)->nullable();
            $table->string('bank_name', 80)->nullable();
            $table->text('account_name')->nullable();     // encrypted
            $table->text('account_number')->nullable();   // encrypted; never returned
            $table->string('provider', 20)->nullable();   // paystack | flutterwave | manual
            $table->text('provider_recipient_code')->nullable(); // encrypted
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement('CREATE UNIQUE INDEX payout_methods_one_default ON payout_methods (user_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('payout_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignPrefixed('user_id', 'users', 'cascade')->unique();
            $table->string('frequency', 20)->default('manual'); // manual | daily | weekly | monthly
            $table->unsignedSmallInteger('day')->nullable();
            $table->bigInteger('minimum_amount')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->foreignPrefixed('payout_method_id', 'payout_methods')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_batches', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('reference', 40)->unique();
            $table->string('provider', 20);
            $table->char('currency', 3);
            $table->unsignedInteger('payouts_count');
            $table->bigInteger('total_amount');
            $table->string('status', 20)->default('queued');
            $table->foreignPrefixed('created_by', 'users');
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('reference', 40)->unique();
            $table->foreignPrefixed('user_id', 'users')->index();
            $table->foreignPrefixed('wallet_id', 'wallets');
            $table->foreignPrefixed('payout_method_id', 'payout_methods');
            $table->foreignPrefixed('batch_id', 'payout_batches')->nullable();
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->bigInteger('fee_amount')->default(0);
            $table->bigInteger('net_amount');
            $table->char('destination_currency', 3);
            $table->bigInteger('destination_amount');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->string('status', 20)->default('pending');
            $table->boolean('requires_approval')->default(false);
            $table->string('approval_reason', 120)->nullable();
            $table->boolean('flagged')->default(false);
            $table->string('hold_reason', 255)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('provider_reference', 191)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignPrefixed('approved_by', 'users')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_positive CHECK (amount > 0 AND net_amount >= 0)');

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 18, 8);
            $table->string('source', 40)->default('manual');
            $table->timestamps();
            $table->unique(['base', 'quote']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 40)->nullable();
            $table->string('key', 120);
            $table->string('route', 120);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'key', 'route']);
            $table->index('expires_at');
        });

        // Dual control (§4.4): the change is stored here and applied only when a second approver confirms.
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->prefixedId();
            $table->string('type', 30);        // refund | payout | commission_rule | escrow_timings | settings | dispute_decision
            $table->string('permission', 60);  // approver must hold this
            $table->string('target_type', 30)->nullable();
            $table->string('target_id', 40)->nullable();
            $table->string('summary', 255);
            $table->jsonb('payload');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | failed
            $table->foreignPrefixed('requested_by', 'users');
            $table->foreignPrefixed('decided_by', 'users')->nullable();
            $table->text('decision_note')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        foreach (['approval_requests', 'idempotency_keys', 'exchange_rates', 'payouts', 'payout_batches', 'payout_schedules',
            'payout_methods', 'wallet_movements', 'ledger_entries', 'wallets', 'webhook_events', 'refunds', 'payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
