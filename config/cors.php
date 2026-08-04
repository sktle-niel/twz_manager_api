<?php

/*
 * In production the API is same-origin under /api and this config is idle.
 * It exists for development, where Vite serves the PWA on its own port —
 * either through the Vite proxy (no CORS involved) or directly against
 * FRONTEND_ORIGINS with credentialed requests.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        explode(',', (string) env('FRONTEND_ORIGINS', 'http://localhost:5173')),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Cookies are the credential, so the browser must be told they may travel
    'supports_credentials' => true,

];
