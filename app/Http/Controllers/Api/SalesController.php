<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Models\ReceiptItem;
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

    /**
     * GET /api/sales/items?storeId=…&from=…&to=…
     *
     * What was actually sold, item by item, split into the goods that make
     * up net sales and the services and labor that never do. Both are real
     * money over the counter; only the first is the branch's to bank, and
     * the split here is the same one the ledger runs on — the `excluded`
     * flag was decided when the receipt was pulled.
     *
     * One branch at a time: this is the manager's page, and the owner reads
     * any branch through the same door.
     */
    public function items(Request $request): JsonResponse
    {
        $storeId = $this->requireStoreRange($request);

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        $this->sync->refreshIfStale();

        /* The join only skips cancelled receipts — store and day are carried
           on the line itself, so the range never filters through it */
        $rows = ReceiptItem::query()
            ->join('receipts', 'receipts.receipt_number', '=', 'receipt_items.receipt_number')
            ->where('receipts.cancelled', false)
            ->where('receipt_items.store_id', $storeId)
            ->whereBetween('receipt_items.day', [
                (string) $request->query('from'),
                (string) $request->query('to'),
            ])
            ->groupBy('receipt_items.sku', 'receipt_items.name', 'receipt_items.excluded')
            ->selectRaw(
                'receipt_items.sku AS sku, receipt_items.name AS name, '
                .'receipt_items.excluded AS excluded, '
                .'ROUND(SUM(receipt_items.quantity), 3) AS qty, '
                .'ROUND(SUM(receipt_items.gross), 2) AS amount',
            )
            ->orderByDesc('amount')
            ->get();

        [$labor, $parts] = $rows->partition(fn ($row) => (bool) $row->excluded);

        $shape = fn ($row) => [
            'sku' => $row->sku,
            'name' => $row->name,
            'quantity' => (float) $row->qty,
            'amount' => (float) $row->amount,
        ];

        return response()->json([
            'parts' => $parts->map($shape)->values(),
            'labor' => $labor->map($shape)->values(),
            'partsTotal' => round($parts->sum(fn ($row) => (float) $row->amount), 2),
            'laborTotal' => round($labor->sum(fn ($row) => (float) $row->amount), 2),
        ]);
    }
}
