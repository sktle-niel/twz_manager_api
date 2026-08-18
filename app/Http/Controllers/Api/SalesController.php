<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The sales figures, served from the local receipts table. Every read offers
 * the sync a chance to top up, but the top-up runs after the response has
 * gone out — the page always gets the local copy at database speed, and the
 * numbers track the tills within a couple of minutes without any read ever
 * waiting on Loyverse being reachable.
 *
 * `gross` is net sales — the figure a bank deposit must match after refunds
 * and excluded service/labor lines are removed. `profit` is the cost-adjusted
 * margin held for reporting, but deposit reconciliation is based on net sales,
 * never the margin.
 */
class SalesController extends Controller
{
    use ReadsBranchScope;

    public function __construct(private readonly ReceiptSync $sync) {}

    /** GET /api/sales/daily?storeIds=…&storeIds=…&from=…&to=… */
    public function daily(Request $request): JsonResponse
    {
        $storeIds = $this->requireStores($request, self::rangeRules());

        if (! $this->allowed($request->user(), $storeIds)) {
            return $this->forbidden();
        }

        $this->sync->refreshIfStale();

        $rows = Receipt::query()
            ->selectRaw('store_id, day, ROUND(SUM(gross), 2) AS g, ROUND(SUM(gross - cost), 2) AS p')
            ->where('cancelled', false)
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$request->query('from'), $request->query('to')])
            ->groupBy('store_id', 'day')
            ->orderBy('day')
            ->get();

        return response()->json($rows->map(fn ($row) => [
            'storeId' => $row->store_id,
            'day' => $row->day,
            'gross' => (float) $row->g,
            'profit' => (float) $row->p,
            /* Sales rows carry no expense join (the audit ledger owns that);
               expected here follows the house rule net sales - expenses with
               expenses at zero. The figure a deposit is matched against
               always comes from /audits. */
            'expenses' => 0.0,
            'expected' => (float) $row->g,
        ]));
    }

    /** GET /api/sales/hourly?storeIds=…&day=… */
    public function hourly(Request $request): JsonResponse
    {
        $storeIds = $this->requireStores($request, ['day' => ['required', 'date_format:Y-m-d']]);

        if (! $this->allowed($request->user(), $storeIds)) {
            return $this->forbidden();
        }

        $this->sync->refreshIfStale();

        /* The hour-by-hour figure is net sales — the day-to-day charts are the
           same base the audit pages reconcile deposits against. */
        $rows = Receipt::query()
           ->selectRaw('hour, ROUND(SUM(gross), 2) AS amount')
            ->where('cancelled', false)
            ->whereIn('store_id', $storeIds)
            ->where('day', $request->query('day'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return response()->json($rows->map(fn ($row) => [
            'hour' => (int) $row->hour,
            'amount' => (float) $row->amount,
        ]));
    }
}
