<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Support\AuditLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The read half of deposits. Recording one (the slip upload, the duplicate
 * check, the discrepancy form) is its own slice and is not built yet — an
 * empty ledger answers honestly in the meantime.
 */
class DepositController extends Controller
{
    use ReadsBranchScope;

    public function __construct(private readonly AuditLedger $ledger) {}

    /** GET /api/deposits?storeId=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'storeId' => ['required', 'string'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $storeId = (string) $request->query('storeId');

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        $deposits = Deposit::query()
            ->with('days')
            ->where('store_id', $storeId)
            ->whereBetween('day', [$request->query('from'), $request->query('to')])
            ->orderByDesc('day')
            ->get();

        return response()->json($deposits->map(fn (Deposit $deposit) => [
            'id' => $deposit->id,
            'storeId' => $deposit->store_id,
            'day' => $deposit->day,
            'amount' => (float) $deposit->amount,
            'reference' => $deposit->reference,
            'covers' => $deposit->days->pluck('day')->sort()->values(),
            'slipUrl' => "/api/files/{$deposit->slip_path}",
            'matched' => $deposit->matched,
        ]));
    }

    /** GET /api/deposits/pending?storeId=… — audited days still uncovered, oldest first */
    public function pending(Request $request): JsonResponse
    {
        $request->validate(['storeId' => ['required', 'string']]);
        $storeId = (string) $request->query('storeId');

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        return response()->json($this->ledger->pending($storeId));
    }
}
