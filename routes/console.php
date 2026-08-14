<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* The receipts ledger keeps itself current: one cron entry on the server
   (see README) runs everything scheduled here. Every minute, so the copy is
   always inside the sales reads' freshness window and a page view almost
   never has to top up for itself — an incremental run is one or two requests
   against a budget of 240 per five minutes. withoutOverlapping, because a
   backfill still walking its pages must not race a second copy. */
Schedule::command('twz:sync-sales')->everyMinute()->withoutOverlapping();

/* The safety net under the incremental pass: once a night, off-peak, re-pull
   the trailing week and upsert it wholesale — whatever a missed page, a clock
   skew, or a quiet edit left behind is repaired here. The watermark is not
   touched; the incremental run stays authoritative. */
Schedule::command('twz:sync-sales --days=7')->dailyAt('03:30')->withoutOverlapping();

/* The item catalog changes rarely; a half-hour-old copy is plenty for the
   sales-filter search, and the walk is minutes long — scheduler work, never
   a page view's */
Schedule::command('twz:sync-catalog')->everyThirtyMinutes()->withoutOverlapping();

/* Reminders fire on each branch's own clock; hourly is enough resolution,
   and the command's own once-per-day cache absorbs the repetition */
Schedule::command('twz:send-reminders')->hourly()->withoutOverlapping();
