<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Services and labor are not sales: the money a customer pays for labor or a
 * diagnostic never sits in the drawer as parts takings, so those lines must
 * not raise the gross and profit the deposit reconciliation checks.
 *
 * Seeds the excluded-SKU list with the shop's SERVICES & LABOR items (the
 * owner's own price list grouping, 24 items), then clears the stored
 * receipts and the sync watermark so the next sync re-pulls the whole
 * backfill window with the filter applied — the aggregates in `receipts`
 * cannot be recomputed without the line data, only re-fetched.
 */
return new class extends Migration
{
    private const SKUS = '11898,10943,11370,11567,11084,19181,19219,19105,19220,19143,'
        .'19160,19106,17137,11083,15822,15823,15824,12065,14429,14558,10812,17907,17885,17883';

    public function up(): void
    {
        Setting::write('sales_excluded_skus', self::SKUS);

        DB::table('receipts')->delete();
        DB::table('settings')->where('key', 'loyverse_receipts_watermark')->delete();
        // The freshness stamp too, so the very next read or cron tick re-pulls
        Cache::forget('loyverse:receipts-synced-at');
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'sales_excluded_skus')->delete();
    }
};
