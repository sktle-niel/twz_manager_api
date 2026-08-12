<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Loyverse\CatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * Search over the Loyverse item catalog, for picking what the sales filter
 * excludes. Reads only the copy the scheduler walked (CatalogCache) — a full
 * catalog walk takes minutes, far past the web clock, so a search request
 * never attempts one. The frontend never pulls the catalog either way; it
 * only ever receives the matches for what was typed.
 */
class CatalogController extends Controller
{
    /** GET /api/catalog/search?q=… — owner only */
    public function search(Request $request): JsonResponse
    {
        $fields = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $q = mb_strtolower(trim($fields['q']));

        $catalog = CatalogCache::read();
        if ($catalog === null) {
            return response()->json(
                ['message' => 'The catalog is still being fetched from the POS. Try again in a minute.'],
                503,
            );
        }

        $hits = array_values(array_filter(
            $catalog,
            fn (array $item) => str_contains(mb_strtolower($item['name']), $q)
                || str_contains(mb_strtolower($item['sku']), $q),
        ));

        return response()->json(array_slice($hits, 0, 30));
    }
}
