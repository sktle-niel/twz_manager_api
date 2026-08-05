<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Support\AuditLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuditController extends Controller
{
    use ReadsBranchScope;

    public function __construct(private readonly AuditLedger $ledger) {}

    /** GET /api/audits?storeIds=…&from=…&to=… */
    public function index(Request $request): JsonResponse
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

        return response()->json(
            $this->ledger->rows($storeIds, $request->query('from'), $request->query('to')),
        );
    }
}
