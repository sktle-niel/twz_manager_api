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

    /* Seconds before a request is abandoned. A 250-receipt page over a long
       window is slow on Loyverse's side; 15s was measured too tight. */
    'timeout' => (int) env('LOYVERSE_TIMEOUT', 45),

    /* The inline (page-view) sync gets a much shorter leash: PHP's own web
       execution limit is 30s, so a 45s curl wait dies as a fatal error no
       catch can reach. Better to give up fast and serve the local copy. */
    'timeout_inline' => (int) env('LOYVERSE_TIMEOUT_INLINE', 8),

    /* How far back the FIRST receipt sync reaches. After that the watermark
       takes over and every run is incremental. */
    'backfill_days' => (int) env('LOYVERSE_BACKFILL_DAYS', 90),

    /* A sales read re-syncs inline when the last run is older than this many
       seconds — near-realtime without a request per page view. */
    'receipt_stale_after' => 120,

    /* Page caps per run: small inline (a page view is waiting), deep for the
       command (backfill has thousands of receipts to walk). */
    'sync_pages_inline' => 5,
    'sync_pages_command' => 200,

    /* Shelf life of the walked item-catalog copy the sales-filter search
       reads. The scheduler rewalks every thirty minutes; this only has to
       outlive a few missed runs, not stay fresh by itself. */
    'catalog_ttl' => 86400,

];
