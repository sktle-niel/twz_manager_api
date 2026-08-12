<?php

namespace App\Console\Commands;

use App\Services\Loyverse\CatalogCache;
use App\Services\Loyverse\LoyverseException;
use Illuminate\Console\Command;

/*
 * Rewalks the item catalog into the cache. Scheduled every thirty minutes
 * (routes/console.php) — the catalog changes rarely, and the sales-filter
 * search reads only this copy.
 */
class SyncCatalog extends Command
{
    protected $signature = 'twz:sync-catalog';

    protected $description = 'Walk the Loyverse item catalog into the search cache';

    public function handle(CatalogCache $catalog): int
    {
        try {
            $count = $catalog->rebuild();
        } catch (LoyverseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$count} catalog item(s) cached.");

        return self::SUCCESS;
    }
}
