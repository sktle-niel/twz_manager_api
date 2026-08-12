<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The owner account
    |--------------------------------------------------------------------------
    |
    | What a fresh database seeds the owner as — the first sign-in, not the
    | account forever. The seeder creates the owner with this password only
    | when no owner exists yet; from then on the app owns it, so changing the
    | password on the Account page sticks and no reseed puts this one back.
    | The username, by contrast, keeps following .env on every seed — it is
    | configuration, not something the app rewrites.
    |
    */

    'owner_username' => (string) env('OWNER_USERNAME', 'twowheelszone'),

    'owner_password' => (string) env('OWNER_PASSWORD', 'password'),

    /*
    |--------------------------------------------------------------------------
    | Admin reset PIN
    |--------------------------------------------------------------------------
    |
    | The second lock on an owner setting somebody else's password. Signing in
    | as the owner is the first; knowing this is the second, so a borrowed
    | laptop left signed in is not enough to take over a branch account.
    |
    | This is the PIN a fresh install starts life with, not the PIN forever.
    | The moment the owner changes it, the new one lives hashed in the settings
    | table and this value is ignored. It sits in .env so the first sign-in has
    | a known PIN — not so it can be edited later: a web request cannot rewrite
    | .env, and a cached config would not notice if it did.
    |
    */

    'reset_pin' => (string) env('ADMIN_RESET_PIN', '8017'),

    /*
    |--------------------------------------------------------------------------
    | How many wrong PINs before the door pauses
    |--------------------------------------------------------------------------
    |
    | Four digits is ten thousand guesses, which is nothing to a script. Only
    | failures count, and a correct PIN clears the slate.
    |
    */

    'reset_pin_attempts' => 5,

    'reset_pin_lockout_seconds' => 900,

    /*
    |--------------------------------------------------------------------------
    | One-time setup key
    |--------------------------------------------------------------------------
    |
    | Lets a file-manager-only deploy finish itself from the browser: visiting
    | /setup/{key} runs the migrations, seeds a fresh database, and sets the
    | ledger's start day — the work SSH would otherwise do. Empty (the
    | default) means the route does not exist. Set it in the deployed .env,
    | run setup once, then delete the line.
    |
    */

    'setup_key' => (string) env('SETUP_KEY', ''),

];
