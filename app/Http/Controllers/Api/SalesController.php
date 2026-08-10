<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The sales figures, served from the local receipts table. Every read first
 * offers the sync a chance to top up (cheap when fresh, bounded when stale,
 * never fatal), so the numbers track the tills within a couple of minutes
 * without any page ever waiting on Loyverse being reachable.
 *
 * `gross` is money taken — the figure a bank deposit must match. `profit` is
 * gross minus what the goods cost the shop (net of refunds): the number the
 * owner reads as kita. Deposits reconcile against gross, never profit;
 * nobody deposits a margin.
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
               expected here follows the house rule profit - expenses with
               expenses at zero. The figure a deposit is matched against
               always comes from /audits. */
            'expenses' => 0.0,
            'expected' => (float) $row->p,
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

        /* The hour-by-hour figure is PROFIT, like every headline the charts
           draw — gross stays the deposit-reconciliation number on /audits */
        $rows = Receipt::query()
            ->selectRaw('hour, ROUND(SUM(gross - cost), 2) AS amount')
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
