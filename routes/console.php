<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* The receipts ledger keeps itself current: one cron entry on the server
   (see README) runs everything scheduled here. withoutOverlapping, because
   a backfill still walking its pages must not race a second copy. */
Schedule::command('twz:sync-sales')->everyFiveMinutes()->withoutOverlapping();
