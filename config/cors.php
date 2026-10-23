<?php

/*
 * The Vue SPA authenticates with Sanctum cookies, so CORS must name exact origins and allow
 * credentials (a wildcard origin is rejected by browsers when credentials are sent).
 * CORS_ALLOWED_ORIGINS is a comma-separated list; it defaults to APP_FRONTEND_URL.
 */
return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        // `?:` so an empty CORS_ALLOWED_ORIGINS= (as in .env.example) falls back too.
        explode(',', (string) (env('CORS_ALLOWED_ORIGINS') ?: env('APP_FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'Idempotency-Key',
        'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id',
    ],

    'exposed_headers' => ['X-Request-Id', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Idempotent-Replayed'],

    'max_age' => 600,

    'supports_credentials' => true,

];
