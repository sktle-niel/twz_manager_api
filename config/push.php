<?php

/*
 * Web Push. The VAPID pair is this app's identity toward the browsers' push
 * services; the public half is handed to the frontend to subscribe with, the
 * private half signs every send and lives only in .env.
 */
return [

    'public_key' => env('VAPID_PUBLIC_KEY', ''),

    'private_key' => env('VAPID_PRIVATE_KEY', ''),

    /* Who to contact about this sender — a mailto: or https: URL */
    'subject' => env('VAPID_SUBJECT', ''),

    /* Branch-local hours the reminders fire: expenses in the evening while
       the day can still be logged, deposits in the morning while the bank
       is the next errand. */
    'expense_hour' => 19,
    'deposit_hour' => 9,

];
