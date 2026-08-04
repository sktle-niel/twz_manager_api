<?php

/*
 * Loyverse POS — the upstream the sales figures come from.
 *
 * The token has no scopes: it grants full read AND write over the entire
 * merchant account (docs/LOYVERSE.md in the frontend repo). It lives in the
 * server's .env and nowhere else — never in this repo, never in a VITE_
 * variable, never in a URL.
 *
 * The rate limit is 300 requests per 300 seconds PER ACCOUNT, shared with
 * every other integration the merchant runs. `budget` is the slice of that
 * window this app allows itself; the headroom is deliberate.
 */
return [

    'base_url' => env('LOYVERSE_BASE_URL', 'https://api.loyverse.com/v1.0'),

    'token' => env('LOYVERSE_API_TOKEN', ''),

    /* Requests this app may spend per window. 240 of 300 leaves a fifth of
       the account budget for whatever else talks to Loyverse. */
    'budget' => (int) env('LOYVERSE_RATE_BUDGET', 240),

    /* The window Loyverse meters, in seconds. Not ours to tune — documented
       here so the budget guard and the docs agree on one number. */
    'window' => 300,

    /* How long a successful token validation is trusted before the status
       endpoint would probe again. */
    'status_ttl' => 3600,

];
