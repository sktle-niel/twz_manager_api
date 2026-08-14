<?php

namespace App\Console\Commands;

use App\Services\Loyverse\LoyverseException;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Console\Command;

/*
 * The deep receipt pull: first run walks the whole backfill window, after
 * that each run is a small incremental top-up from the watermark. Scheduled
 * every minute (routes/console.php), which keeps the local copy inside the
 * sales reads' freshness window — the endpoints still top up for themselves
 * when the schedule is not running, but after the response, never in it.
 */
class SyncSales extends Command
{
    protected $signature = 'twz:sync-sales
        {--days= : Reconcile — re-pull the trailing N days wholesale; never moves the watermark}';

    protected $description = 'Pull receipts from Loyverse into the local sales table';

    public function handle(ReceiptSync $sync): int
    {
        $days = $this->option('days');
        $reconcile = $days !== null ? max(1, (int) $days) : null;
        $pages = (int) config('loyverse.sync_pages_command');

        try {
            $report = $sync->run($pages, $reconcile);
            /* At its scheduled hour the every-minute incremental may briefly
               hold the lock. The nightly reconcile is the safety net — it
               waits the lock out rather than silently skipping a night. */
            for ($try = 0; $reconcile !== null && $report['locked'] && $try < 3; $try++) {
                sleep(20);
                $report = $sync->run($pages, $reconcile);
            }
        } catch (LoyverseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($report['locked']) {
            $this->info('Another sync already holds the lock; nothing to do.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d page(s), %d receipt(s) upserted, %d from unlinked stores skipped.',
            $report['pages'],
            $report['upserted'],
            $report['skipped'],
        ));
        if (! $report['done']) {
            $this->warn($reconcile !== null
                ? 'Page cap reached before the last page — tonight\'s coverage is partial; the next nightly run re-covers the window.'
                : 'Page cap reached before the last page. Run again to continue; the watermark did not advance.');
        }

        return self::SUCCESS;
    }
}
