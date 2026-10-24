<?php

/*
|--------------------------------------------------------------------------
| Platform rules and runtime-editable defaults
|--------------------------------------------------------------------------
|
| `defaults` are the starting values for settings that staff can change at
| runtime (stored in `platform_settings`, read through PlatformSettings).
| Changes to escrow timings and gateway settings go through dual control.
|
| The remaining keys are fixed rules that only change with a deploy.
*/

return [

    // Receives Horizon long-queue-wait alerts.
    'ops_alert_email' => env('OPS_ALERT_EMAIL'),

    // Serve /api/documentation and /openapi to anyone (otherwise staff only outside local).
    'api_docs_public' => (bool) env('API_DOCS_PUBLIC', false),

    // Proxies allowed to set X-Forwarded-* (client IP, scheme): comma-separated IPs/CIDRs, or `*`.
    // Default trusts only a reverse proxy on the same host (Nginx on the VPS).
    'trusted_proxies' => env('TRUSTED_PROXIES') ?: '127.0.0.1,::1',

    'frontend_url' => rtrim((string) env('APP_FRONTEND_URL', 'http://localhost:5173'), '/'),

    'staff_email_domain' => env('STAFF_EMAIL_DOMAIN', 'pilotwardrobe.com'),

    // Fixed business rules ---------------------------------------------------

    'auth' => [
        'email_otp_ttl_seconds' => 600,
        'email_otp_resend_seconds' => 60,
        'password_reset_ttl_minutes' => 60,
        'login_max_attempts' => 5,
        'login_lock_minutes' => 15,
        'two_factor_challenge_ttl_seconds' => 600,
        'trusted_device_days' => 30,
        'token_ttl_minutes' => (int) env('AUTH_TOKEN_TTL_MINUTES', 60),
        'remember_token_ttl_days' => 30,
        'staff_idle_minutes' => 30,
        'impersonation_minutes' => 15,
        // Staff normally sign in with Google Workspace + security key. Allow password login only for local/dev.
        'allow_staff_password_login' => (bool) env('STAFF_PASSWORD_LOGIN', false),
        // With staff password login on, staff must also have an authenticator app (set up with
        // `php artisan staff:credentials`). On by default in production; local demo staff skip it.
        'staff_password_requires_two_factor' => (bool) env('STAFF_PASSWORD_REQUIRES_2FA', env('APP_ENV') === 'production'),
    ],

    'listings' => [
        'unverified_live_cap' => 3,
        'max_images' => 12,
        'minimum_offer_default_bps' => 8000, // suggested floor: 80% of asking price
    ],

    'offers' => [
        'expiry_hours' => 24,
        'per_hour_limit' => 20,
    ],

    'swaps' => [
        'expiry_hours' => 48,
        'max_offered_items' => 5,
    ],

    // Pilotwardrobe Freight prepaid-label rates by currency (minor units).
    'freight' => [
        'rates' => [
            'standard' => ['USD' => 2400, 'GBP' => 1900, 'EUR' => 2200, 'NGN' => 3500000, 'GHS' => 35000],
            'express' => ['USD' => 6200, 'GBP' => 4900, 'EUR' => 5700, 'NGN' => 9000000, 'GHS' => 90000],
        ],
    ],

    'orders' => [
        'reservation_minutes' => 15,
        'delivered_reminder_hours' => 48,
    ],

    'payments' => [
        'pending_expiry_minutes' => 60,
        // Hosts the SPA will redirect to (handoff §7.1). Validated server-side too.
        'redirect_hosts' => ['checkout.stripe.com', 'paypal.com', 'checkout.paystack.com', 'flutterwave.com', 'monnify.com'],
    ],

    'disputes' => [
        'seller_response_hours' => 48,
        'mediation_business_days' => 5,
        'appeal_window_days' => 7,
    ],

    'messages' => [
        'max_length' => 4000,
        'email_digest_minutes' => 15,
    ],

    'media' => [
        'max_kilobytes' => 15 * 1024,
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
        'pdf_mimes' => ['application/pdf'],
        'min_listing_dimension' => 320,
        'private_url_ttl_seconds' => 600,
        'unattached_ttl_hours' => 24,
    ],

    'reviews' => [
        'tags' => [
            'as_described' => 'As described',
            'fast_dispatch' => 'Fast dispatch',
            'well_packed' => 'Well packed',
            'great_communication' => 'Great communication',
        ],
    ],

    // Runtime-editable defaults ---------------------------------------------

    'defaults' => [

        'escrow' => [
            'auto_release_hours' => 72,
            'dispatch_sla_days' => 3,
            'dispute_window_hours' => 72,
            'new_seller_payout_hold_days' => 7,
        ],

        'dual_control' => [
            'refund_threshold' => ['amount' => 100000, 'currency' => 'USD'],   // $1,000
            'payout_threshold' => ['amount' => 200000, 'currency' => 'USD'],   // $2,000
            'dispute_decision_threshold' => ['amount' => 100000, 'currency' => 'USD'],
        ],

        'withdrawals' => [
            'fee_bps' => 0,
            'minimum' => ['amount' => 1000, 'currency' => 'USD'],
            'eta_business_days' => [1, 2],
        ],

        // Import duties estimate by destination country (bps of item value); cross-border only.
        'duties' => [
            'NG' => 0,
            'GH' => 0,
        ],

        'settlement_currency' => 'USD',
        'display_currencies' => ['USD', 'NGN', 'GHS', 'GBP', 'EUR'],
        'fx_margin_percent' => 1.5,

        'moderation' => [
            // Listings from these seller groups skip the review queue.
            'auto_approve_official_stores' => true,
            'auto_approve_verified_aviators' => true,
            'auto_approve_kyc_verified' => true,
        ],

        'markets' => [
            ['country_code' => 'NG', 'name' => 'Nigeria', 'currency' => 'NGN', 'enabled' => true, 'status' => 'live'],
            ['country_code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS', 'enabled' => true, 'status' => 'live'],
            ['country_code' => 'GB', 'name' => 'United Kingdom', 'currency' => 'GBP', 'enabled' => true, 'status' => 'live'],
            ['country_code' => 'US', 'name' => 'United States', 'currency' => 'USD', 'enabled' => false, 'status' => 'planned'],
        ],

        'gateways' => [
            ['code' => 'paystack', 'name' => 'Paystack', 'description' => 'Card, bank transfer, USSD', 'flow' => 'redirect',
                'enabled' => true, 'currencies' => ['NGN', 'GHS', 'USD'], 'countries' => ['NG', 'GH']],
            ['code' => 'flutterwave', 'name' => 'Flutterwave', 'description' => 'Card, bank, mobile money', 'flow' => 'redirect',
                'enabled' => true, 'currencies' => ['NGN', 'GHS', 'USD', 'GBP', 'EUR'], 'countries' => ['NG', 'GH', 'GB', 'US']],
            ['code' => 'monnify', 'name' => 'Monnify', 'description' => 'Bank transfer, card', 'flow' => 'redirect',
                'enabled' => true, 'currencies' => ['NGN'], 'countries' => ['NG']],
            ['code' => 'stripe', 'name' => 'Stripe', 'description' => 'Card, Apple Pay, Google Pay', 'flow' => 'redirect',
                'enabled' => true, 'currencies' => ['USD', 'GBP', 'EUR'], 'countries' => ['*']],
            ['code' => 'paypal', 'name' => 'PayPal', 'description' => 'PayPal balance or card', 'flow' => 'redirect',
                'enabled' => true, 'currencies' => ['USD', 'GBP', 'EUR'], 'countries' => ['*']],
        ],

        'feature_flags' => [
            ['key' => 'fit_kit', 'label' => 'Fit Kit size matching', 'detail' => '100% · all markets', 'enabled' => true],
            ['key' => 'swaps_cash', 'label' => 'Swaps with cash top-up', 'detail' => '100%', 'enabled' => true],
            ['key' => 'offers', 'label' => 'Offers on listings', 'detail' => '100%', 'enabled' => true],
            ['key' => 'digital_downloads', 'label' => 'Digital downloads', 'detail' => 'Photographers', 'enabled' => true],
            ['key' => 'whatsapp_notifications', 'label' => 'WhatsApp order updates', 'detail' => 'Opt-in', 'enabled' => false],
        ],

        'support' => [
            'avg_chat_reply_minutes' => 4,
            'offices' => [
                ['name' => 'Lagos · HQ', 'address' => '14 Kofo Abayomi St, Victoria Island'],
                ['name' => 'London', 'address' => 'Hangar 3, Biggin Hill Airport, TN16 3BH'],
            ],
        ],
    ],
];
