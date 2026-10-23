<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\OpenApiVersion;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

/*
 * OpenAPI 3.1 generated from routes, Form Requests and Resources by Scramble.
 * UI: GET /api/documentation · JSON: GET /openapi (exposed in AppServiceProvider).
 * Static export for the frontend repo / Postman import: `php artisan scramble:export --path=docs/openapi.json`.
 */
return [
    'api_path' => 'api/v1',

    'api_domain' => null,

    'export_path' => 'docs/openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'openapi_version' => OpenApiVersion::V3_1,

    'info' => [
        'version' => env('API_VERSION', '1.0.0'),

        'description' => <<<'MD'
Pilotwardrobe marketplace API (v1). All responses use the envelope `{ "success": true, "data": … }`;
lists add `meta` and `links`; errors are `{ "success": false, "message", "code", "errors"? }`.

**Auth.** The SPA uses Sanctum cookie sessions (`GET /sanctum/csrf-cookie`, then send `X-XSRF-TOKEN`).
Clients outside `SANCTUM_STATEFUL_DOMAINS` (mobile, Postman) get `access_token` from login and send `Authorization: Bearer <token>`.

**Money** is integer minor units (`*_amount`) with an ISO `currency`. **Idempotency:** send `Idempotency-Key`
on checkout, payment, offer, refund and withdrawal mutations. Every response carries `X-Request-Id`.

See `docs/backend-api-contract.md` for state machines, error codes and the permission matrix.
MD,
    ],

    'ui' => [
        'title' => 'Pilotwardrobe API',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', false),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],

    // Bearer auth on routes with `auth`/`auth:*` middleware (the strategy's defaults). Keep this a
    // class-string: objects here (e.g. SecurityScheme::http()) break `php artisan config:cache`.
    'security_strategy' => MiddlewareAuthSecurityStrategy::class,
];
