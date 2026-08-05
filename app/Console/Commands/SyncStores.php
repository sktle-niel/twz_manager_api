<?php

namespace App\Console\Commands;

use App\Services\Loyverse\LoyverseException;
use App\Services\Loyverse\StoreSync;
use Illuminate\Console\Command;

/*
 * The moment dummy branches become real ones: pull Loyverse's store list and
 * link it to ours. Run once at setup and again whenever a branch opens or
 * closes — the store list is not worth a scheduled job, branches change a
 * few times a year.
 */
class SyncStores extends Command
{
    protected $signature = 'twz:sync-stores';

    protected $description = 'Pull the store list from Loyverse and link the local branches to it';

    public function handle(StoreSync $sync): int
    {
        try {
            $report = $sync->run();
        } catch (LoyverseException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['linked'] as $line) {
            $this->info("linked   {$line}");
        }
        foreach ($report['created'] as $name) {
            $this->info("created  {$name}");
        }
        foreach ($report['already'] as $name) {
            $this->line("kept     {$name} (already linked)");
        }
        foreach ($report['localOnly'] as $name) {
            $this->warn("local    {$name} has no Loyverse store — left alone, check the name");
        }

        return self::SUCCESS;
    }
}
