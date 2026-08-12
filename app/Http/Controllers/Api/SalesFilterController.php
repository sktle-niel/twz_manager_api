<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The items that never count toward gross and profit — services and labor,
 * whose money is not the drawer's to deposit. The list is sku+name pairs in
 * one Setting; ReceiptSync nets matching lines out of every receipt at
 * ingest.
 *
 * Changing the list changes the past: the receipts table holds aggregates
 * the old filter already shaped, and only a re-pull can recompute them. So a
 * save that actually changes the list clears the receipts and the sync
 * watermark, and the scheduler's next tick rebuilds the backfill window.
 */
class SalesFilterController extends Controller
{
    /** GET /api/settings/sales-filter */
    public function show(): JsonResponse
    {
        return response()->json(ReceiptSync::excludedItems());
    }

    /** PUT /api/settings/sales-filter — whole-list replace, owner only */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:500'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.name' => ['required', 'string', 'max:255'],
        ]);

        $items = collect($data['items'])
            ->map(fn (array $item) => ['sku' => trim($item['sku']), 'name' => trim($item['name'])])
            ->unique('sku')
            ->values()
            ->all();

        $before = ReceiptSync::excludedItems();
        Setting::write(ReceiptSync::EXCLUDED_SKUS, (string) json_encode($items));

        /* Same SKUs in the same order — nothing to recompute. Anything else
           costs a re-pull, deliberately: stale aggregates would quietly
           disagree with the list this page shows. */
        if (array_column($items, 'sku') !== array_column($before, 'sku')) {
            DB::table('receipts')->delete();
            DB::table('settings')->where('key', 'loyverse_receipts_watermark')->delete();
            Cache::forget('loyverse:receipts-synced-at');
        }

        return response()->json($items);
    }
}
