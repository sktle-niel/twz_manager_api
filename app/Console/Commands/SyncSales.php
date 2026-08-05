<?php

namespace App\Console\Commands;

use App\Services\Loyverse\LoyverseException;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Console\Command;

/*
 * The deep receipt pull: first run walks the whole backfill window, after
 * that each run is a small incremental top-up from the watermark. Scheduled
 * every five minutes (routes/console.php); the sales endpoints also top up
 * inline when their copy is stale, so the schedule is the safety net, not
 * the only pump.
 */
class SyncSales extends Command
{
    protected $signature = 'twz:sync-sales';

    protected $description = 'Pull receipts from Loyverse into the local sales table';

    public function handle(ReceiptSync $sync): int
    {
        try {
            $report = $sync->run((int) config('loyverse.sync_pages_command'));
        } catch (LoyverseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d page(s), %d receipt(s) upserted, %d from unlinked stores skipped.',
            $report['pages'],
            $report['upserted'],
            $report['skipped'],
        ));
        if (! $report['done']) {
            $this->warn('Page cap reached before the last page. Run again to continue; the watermark did not advance.');
        }

        return self::SUCCESS;
    }
}
