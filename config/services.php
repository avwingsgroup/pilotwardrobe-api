<?php

/*
|--------------------------------------------------------------------------
| Third-party services
|--------------------------------------------------------------------------
|
| Every credential comes from the environment; nothing secret is committed.
| `driver` keys let local/test environments swap in log/fake adapters.
*/

return [

    // ---------------------------------------------------------------- media
    'media' => [
        'driver' => env('MEDIA_DRIVER', 'cloudinary'), // cloudinary | local
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'root_folder' => env('CLOUDINARY_ROOT_FOLDER', 'pilotwardrobe'),
        'webhook_secret' => env('CLOUDINARY_API_SECRET'),
    ],

    // ---------------------------------------------------------------- email
    'zeptomail' => [
        'token' => env('ZEPTOMAIL_TOKEN'),
        'endpoint' => env('ZEPTOMAIL_ENDPOINT', 'https://api.zeptomail.com/v1.1/email'),
        'bounce_address' => env('ZEPTOMAIL_BOUNCE_ADDRESS'),
    ],

    // ---------------------------------------------------------------- sms
    'sms' => [
        // Comma-separated provider order; the first that accepts the message wins.
        'providers' => array_filter(explode(',', (string) env('SMS_PROVIDERS', 'termii,twilio'))),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'Pilotwrdrb'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        'channel' => env('TERMII_CHANNEL', 'dnd'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
    ],

    // ---------------------------------------------------------------- whatsapp
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'cloud'), // cloud | log
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
    ],

    // ---------------------------------------------------------------- push
    'fcm' => [
        'driver' => env('PUSH_DRIVER', 'fcm'), // fcm | log
        'project_id' => env('FIREBASE_PROJECT_ID'),
        // Service-account JSON: inline JSON, base64 of it, or a file path.
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    // ---------------------------------------------------------------- payments
    'payments' => [
        // `sandbox` adds an offline test gateway for local development and automated tests.
        'sandbox_enabled' => (bool) env('PAYMENTS_SANDBOX_ENABLED', false),
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
    ],

    'flutterwave' => [
        'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
        'secret_hash' => env('FLUTTERWAVE_SECRET_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
    ],

    'monnify' => [
        'api_key' => env('MONNIFY_API_KEY'),
        'secret_key' => env('MONNIFY_SECRET_KEY'),
        'contract_code' => env('MONNIFY_CONTRACT_CODE'),
        'base_url' => env('MONNIFY_BASE_URL', 'https://sandbox.monnify.com'),
    ],

    'stripe' => [
        'public_key' => env('STRIPE_KEY'),
        'secret_key' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'webhook_tolerance' => 300,
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
        'base_url' => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
    ],

    // ---------------------------------------------------------------- identity
    'kyc' => [
        'driver' => env('KYC_DRIVER', 'prembly'), // prembly | fake
    ],

    'prembly' => [
        'api_key' => env('PREMBLY_API_KEY'),
        'app_id' => env('PREMBLY_APP_ID'),
        'base_url' => env('PREMBLY_BASE_URL', 'https://api.prembly.com'),
        'webhook_secret' => env('PREMBLY_WEBHOOK_SECRET'),
    ],

    // ---------------------------------------------------------------- social & staff sign-in
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim((string) env('APP_FRONTEND_URL', 'http://localhost:5173'), '/').'/auth/google/callback'),
        // Staff Control Tower sign-in (Google Workspace, restricted to STAFF_EMAIL_DOMAIN).
        'staff_client_id' => env('GOOGLE_STAFF_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', rtrim((string) env('APP_FRONTEND_URL', 'http://localhost:5173'), '/').'/auth/facebook/callback'),
    ],

    'webauthn' => [
        'rp_id' => env('WEBAUTHN_RP_ID', 'pilotwardrobe.com'),
        'rp_name' => env('WEBAUTHN_RP_NAME', 'Pilotwardrobe Control Tower'),
    ],
];
