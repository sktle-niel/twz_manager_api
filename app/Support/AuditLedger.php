<?php

namespace App\Support;

use App\Models\Advance;
use App\Models\Deposit;
use App\Models\DepositDay;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
 * Composes the DayAudit rows of docs/API.md from the three ledgers: what the
 * tills took (receipts), what the branch spent (expenses), and what went to
 * the bank (deposits). One row per store per day that has any of the three.
 *
 * Status is the branch's own clock: today is "open" (no deposit expected
 * yet), a past day with no covering deposit is "pending", a covered day is
 * "matched" or "discrepancy" by whether the deposit's amount agreed.
 */
class AuditLedger
{
    /**
     * The day the audit ledger begins. Days before it were settled in the
     * world before this app existed — their sales still show in the charts,
     * but they are nobody's deposit backlog and carry no audit row.
     */
    public const START_KEY = 'audit_start_day';

    public static function startDay(): ?string
    {
        return Setting::read(self::START_KEY);
    }

    /**
     * @param  list<string>  $storeIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(array $storeIds, string $from, string $to): Collection
    {
        $start = self::startDay();
        if ($start !== null && $from < $start) {
            $from = $start;
        }
        if ($start !== null && $to < $start) {
            return collect();
        }
        $sales = Receipt::query()
            ->selectRaw('store_id, day, ROUND(SUM(gross), 2) AS gross, ROUND(SUM(gross - cost), 2) AS profit')
            ->where('cancelled', false)
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->groupBy('store_id', 'day')
            ->get();

        $spend = Expense::query()
            ->selectRaw('store_id, day, ROUND(SUM(amount), 2) AS spent')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->groupBy('store_id', 'day')
            ->get();

        $drawn = Advance::query()
            ->selectRaw('store_id, day, ROUND(SUM(amount), 2) AS advanced')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->groupBy('store_id', 'day')
            ->get();

        $covers = DepositDay::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->get();
        $deposits = Deposit::query()
            ->whereIn('id', $covers->pluck('deposit_id')->unique())
            ->get()
            ->keyBy('id');
        /* Every day each of those deposits covers — deliberately NOT bounded
           by the queried range, because a deposit's batch does not stop at
           the edge of whatever window the page happens to be looking at */
        $spans = DepositDay::query()
            ->whereIn('deposit_id', $deposits->keys())
            ->orderBy('day')
            ->get()
            ->groupBy('deposit_id');

        /* Today is per branch: the same instant is the 6th in Palawan and
           still the 5th somewhere else. Never one clock for every store. */
        $todayFor = Store::query()
            ->whereIn('id', $storeIds)
            ->get()
            ->mapWithKeys(fn (Store $s) => [$s->id => CarbonImmutable::now($s->timezone)->format('Y-m-d')]);

        $rows = [];
        $key = fn (string $store, string $day) => "{$store}|{$day}";
        $blank = fn (string $store, string $day) => [
            'storeId' => $store, 'day' => $day,
            'gross' => 0.0, 'profit' => 0.0, 'expenses' => 0.0, 'advances' => 0.0,
        ];

        foreach ($sales as $row) {
            $rows[$key($row->store_id, $row->day)] = [
                ...$blank($row->store_id, $row->day),
                'gross' => (float) $row->gross, 'profit' => (float) $row->profit,
            ];
        }
        foreach ($spend as $row) {
            $slot = &$rows[$key($row->store_id, $row->day)];
            $slot ??= $blank($row->store_id, $row->day);
            $slot['expenses'] = (float) $row->spent;
            unset($slot);
        }
        foreach ($drawn as $row) {
            $slot = &$rows[$key($row->store_id, $row->day)];
            $slot ??= $blank($row->store_id, $row->day);
            $slot['advances'] = (float) $row->advanced;
            unset($slot);
        }
        foreach ($covers as $cover) {
            $slot = &$rows[$key($cover->store_id, $cover->day)];
            $slot ??= $blank($cover->store_id, $cover->day);
            $slot['depositId'] = $cover->deposit_id;
            unset($slot);
        }

        return collect($rows)
            ->map(function (array $row) use ($deposits, $spans, $todayFor) {
                $deposit = isset($row['depositId']) ? $deposits->get($row['depositId']) : null;
                /* The house rule: the bank gets net sales minus the day's spend.
                   Advances net out too: cash an employee drew is out of the
                   drawer on this day, whoever eventually pays it back. */
                $expected = round($row['gross'] - $row['expenses'] - $row['advances'], 2);
                $open = $row['day'] >= ($todayFor[$row['storeId']] ?? $row['day']);

                return [
                    'storeId' => $row['storeId'],
                    'day' => $row['day'],
                    'gross' => $row['gross'],
                    'profit' => $row['profit'],
                    'expenses' => $row['expenses'],
                    'advances' => $row['advances'],
                    'expected' => $expected,
                    'deposited' => $deposit !== null ? (float) $deposit->amount : null,
                    'online' => $deposit !== null ? (float) $deposit->online : null,
                    /* The batch the deposit answers: every day it covers, and
                       the expected sum it was judged against when recorded.
                       Without these, a six-day deposit shown on one day's row
                       reads as a wild over-deposit. */
                    'depositCovers' => $deposit !== null
                        ? $spans->get($deposit->id)?->pluck('day')->values() ?? collect()
                        : null,
                    'depositExpected' => $deposit?->expected !== null ? (float) $deposit->expected : null,
                    'slipUrl' => $deposit !== null ? "/api/files/{$deposit->slip_path}" : null,
                    'status' => match (true) {
                        $deposit !== null => $deposit->matched ? 'matched' : 'discrepancy',
                        $open => 'open',
                        default => 'pending',
                    },
                ];
            })
            ->sortBy([['day', 'asc'], ['storeId', 'asc']])
            ->values();
    }

    /**
     * Audited days with no deposit against them yet, oldest first — past
     * days only; today is still open.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(string $storeId): Collection
    {
        $today = CarbonImmutable::now(
            Store::query()->find($storeId)?->timezone ?? 'Asia/Manila',
        )->format('Y-m-d');

        $first = Receipt::query()->where('store_id', $storeId)->min('day');
        if ($first === null) {
            $first = Expense::query()->where('store_id', $storeId)->min('day');
        }
        if ($first === null) {
            return collect();
        }

        // The count starts at the ledger's start day, never before
        $start = self::startDay();
        if ($start !== null && $first < $start) {
            $first = $start;
        }

        return $this->rows([$storeId], $first, $today)
            ->filter(fn (array $row) => $row['status'] === 'pending')
            ->values();
    }
}
