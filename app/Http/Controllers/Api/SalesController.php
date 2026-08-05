<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
        $storeIds = $this->storeIds($request);
        Validator::make(
            [...$request->only(['from', 'to']), 'storeIds' => $storeIds],
            [
                'storeIds' => ['required', 'array', 'min:1'],
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ],
        )->validate();

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
            /* The expenses slice is not built yet: zero is the honest value,
               and expected = gross - expenses follows it. See docs/API.md. */
            'expenses' => 0.0,
            'expected' => (float) $row->g,
        ]));
    }

    /** GET /api/sales/hourly?storeIds=…&day=… */
    public function hourly(Request $request): JsonResponse
    {
        $storeIds = $this->storeIds($request);
        Validator::make(
            [...$request->only(['day']), 'storeIds' => $storeIds],
            [
                'storeIds' => ['required', 'array', 'min:1'],
                'day' => ['required', 'date_format:Y-m-d'],
            ],
        )->validate();

        if (! $this->allowed($request->user(), $storeIds)) {
            return $this->forbidden();
        }

        $this->sync->refreshIfStale();

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
