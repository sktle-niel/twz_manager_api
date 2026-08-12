<?php

namespace App\Http\Concerns;

use App\Models\DepositDay;
use Illuminate\Http\JsonResponse;

/*
 * The one rule every day-scoped write shares: a day covered by a deposit is
 * reconciled, its figures are what the owner matched against, and nothing —
 * expense or advance — may quietly edit them after.
 */
trait GuardsReconciledDays
{
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
}
