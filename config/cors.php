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

    /*
     * No fallback. An unset FRONTEND_ORIGINS used to mean "trust
     * localhost:5173", which quietly shipped a trusted origin to production
     * — and because VerifyOriginOnUnsafeRequests folds this list into the
     * origins it accepts WRITES from, the default was not merely a CORS
     * courtesy. Development sets the variable explicitly; production leaves
     * it empty, because the PWA is same-origin there and needs nothing.
     */
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_ORIGINS', ''))),
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Cookies are the credential, so the browser must be told they may travel
    'supports_credentials' => true,

];
