<?php

/*
 * Extra origins for local development, comma-separated in CORS_EXTRA_ORIGINS.
 *
 * The Flutter attendance app can be built for the web (`flutter build web`) and
 * driven in a browser against a local CRM. On a phone the app is not an origin
 * and CORS never applies; in a browser it does, so the port serving the built
 * app has to be listed here or every request fails preflight.
 *
 * Empty by default, so nothing changes for a deployed environment unless the
 * variable is explicitly set.
 */
$extra = array_filter(array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_merge([
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://127.0.0.1:5174',
        env('FRONTEND_URL', 'http://localhost:5173'),
    ], $extra))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
