<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Support\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/*
 * One query endpoint for the whole app — audited days, expenses, deposits,
 * and (for the owner) accounts and branches, over the last 90 days. The
 * matching lives in App\Support\GlobalSearch; this door only checks who is
 * asking and for which branches.
 */
class SearchController extends Controller
{
    use ReadsBranchScope;

    /** GET /api/search?q=…&storeIds=… */
    public function query(Request $request): JsonResponse
    {
        $storeIds = $this->storeIds($request);
        Validator::make(
            ['q' => $request->query('q'), 'storeIds' => $storeIds],
            [
                'q' => ['required', 'string', 'min:2', 'max:80'],
                'storeIds' => ['required', 'array', 'min:1'],
            ],
        )->validate();

        if (! $this->allowed($request->user(), $storeIds)) {
            return $this->forbidden();
        }

        return response()->json(
            (new GlobalSearch)->run($request->user(), $storeIds, (string) $request->query('q')),
        );
    }
}
