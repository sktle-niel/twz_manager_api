<?php

namespace App\Http\Concerns;

use App\Models\DepositDay;
use App\Models\Store;
use App\Support\AuditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/*
 * The rules every day-scoped write shares.
 *
 * One: a day covered by a deposit is reconciled, its figures are what the
 * owner matched against, and nothing — expense or advance — may quietly edit
 * them after.
 *
 * Two: the day has to be one the ledger will actually reconcile. Outside that
 * window the spend still saves and is then deducted from nothing: a day before
 * the ledger's start day carries no audit row at all, and a day in the
 * branch's future stays `open` forever, so it never joins a deposit. Either
 * way real money sits recorded and invisible, which is the one thing this app
 * exists to prevent.
 */
trait GuardsReconciledDays
{
    /** @var array<string, string> Branch clocks, resolved once per request */
    private array $timezoneMemo = [];

    private function dayClosed(string $storeId, string $day): bool
    {
        return DepositDay::query()->where('store_id', $storeId)->where('day', $day)->exists();
    }

    private function closedDay(string $day): JsonResponse
    {
        return response()->json(
            ['message' => "{$day} is already covered by a deposit, so its figures are final."],
            422,
        );
    }

    /**
     * Null when the day is one the ledger will reconcile; the refusal
     * otherwise. Today counts — a day stays `open` until the branch's own
     * midnight passes, and spend logged during it is deducted the moment the
     * day turns pending.
     */
    private function dayOutOfRange(string $storeId, string $day): ?JsonResponse
    {
        $start = AuditLedger::startDay();
        if ($start !== null && $day < $start) {
            return response()->json(
                ['message' => "The ledger begins on {$start}, so {$day} can never be reconciled."],
                422,
            );
        }

        /* The branch's own clock — the same one the ledger calls today.
           Memoised because a batch of expenses asks about the same branch
           once per item, and that was a query per item. */
        $today = CarbonImmutable::now($this->timezoneOf($storeId))->format('Y-m-d');

        if ($day > $today) {
            return response()->json(
                ['message' => "{$day} has not happened yet at this branch. Check the date."],
                422,
            );
        }

        return null;
    }

    private function timezoneOf(string $storeId): string
    {
        return $this->timezoneMemo[$storeId] ??=
            Store::query()->find($storeId)?->timezone ?? 'Asia/Manila';
    }
}
