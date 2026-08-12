<?php

namespace App\Support;

use App\Http\Controllers\Api\ReconciliationController;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\Store;
use Carbon\CarbonImmutable;

/*
 * Which reminders are due right now, decided from the ledgers — pure
 * planning, no sending, so every rule here is testable without a push
 * service. Times are each branch's own clock: the expense nudge comes in
 * the evening while the day can still be logged, the deposit nudges in the
 * morning while the bank is the next errand.
 */
class ReminderPlanner
{
    public function __construct(private readonly AuditLedger $ledger) {}

    /**
     * @return list<array{storeId: string, tag: string, title: string, body: string, url: string}>
     */
    public function due(CarbonImmutable $now): array
    {
        $reminders = [];

        foreach (Store::query()->get() as $store) {
            $local = $now->setTimezone($store->timezone);
            $hour = (int) $local->format('G');
            $today = $local->format('Y-m-d');

            if ($hour === (int) config('push.expense_hour')) {
                $reminder = $this->expenseReminder($store->id, $today);
                if ($reminder !== null) {
                    $reminders[] = $reminder;
                }
            }

            if ($hour === (int) config('push.deposit_hour')) {
                $reminder = $this->depositReminder($store->id, $today);
                if ($reminder !== null) {
                    $reminders[] = $reminder;
                }
            }
        }

        return $reminders;
    }

    /**
     * The till took money today but nothing is logged against it yet. A day
     * with no sales stays quiet — a closed shop needs no reminder.
     *
     * @return array{storeId: string, tag: string, title: string, body: string, url: string}|null
     */
    private function expenseReminder(string $storeId, string $today): ?array
    {
        $sold = Receipt::query()
            ->where('store_id', $storeId)
            ->where('day', $today)
            ->where('cancelled', false)
            ->exists();
        if (! $sold) {
            return null;
        }

        $logged = Expense::query()
            ->where('store_id', $storeId)
            ->where('day', $today)
            ->exists();
        if ($logged) {
            return null;
        }

        return [
            'storeId' => $storeId,
            'tag' => "expenses:{$storeId}:{$today}",
            'title' => 'Expenses not logged yet',
            'body' => "Nothing is logged for today. Log the day's expenses before closing.",
            'url' => '/expenses',
        ];
    }

    /**
     * Days are piling up without a deposit. Quiet below two pending days —
     * one day pending is the normal morning state, not a problem.
     *
     * @return array{storeId: string, tag: string, title: string, body: string, url: string}|null
     */
    private function depositReminder(string $storeId, string $today): ?array
    {
        $pending = $this->ledger->pending($storeId);
        $count = $pending->count();
        if ($count < 2) {
            return null;
        }

        $window = ReconciliationController::batchWindowDays();
        $expected = number_format($pending->sum(fn (array $row) => (float) $row['expected']), 2);

        $body = match (true) {
            $count > $window => "{$count} days are waiting — past the usual {$window}-day window. Deposit ₱{$expected} today.",
            $count === $window => "The batch is at the usual {$window}-day window. Deposit ₱{$expected} today.",
            default => "{$count} audited days are waiting for a deposit — ₱{$expected} expected.",
        };

        return [
            'storeId' => $storeId,
            'tag' => "deposits:{$storeId}:{$today}",
            'title' => $count > $window ? 'Deposit overdue' : 'Deposit waiting',
            'body' => $body,
            'url' => '/deposits',
        ];
    }
}
