<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Services\Loyverse\ReceiptSync;
use App\Support\AuditLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    use ReadsBranchScope;

    public function __construct(
        private readonly AuditLedger $ledger,
        private readonly ReceiptSync $sync,
    ) {}

    /** GET /api/audits?storeIds=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $storeIds = $this->requireStores($request, self::rangeRules());

        if (! $this->allowed($request->user(), $storeIds)) {
            return $this->forbidden();
        }

        /* The audit rows are composed from the receipts copy, so this read
           keeps that copy fresh the same way the sales reads do — the
           Dashboard leans on this endpoint now */
        $this->sync->refreshIfStale();

        return response()->json(
            $this->ledger->rows($storeIds, $request->query('from'), $request->query('to')),
        );
    }
}
