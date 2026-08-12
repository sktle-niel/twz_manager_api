<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\GuardsReconciledDays;
use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Advance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

/*
 * Cash advances an employee drew from the drawer against their pay. The
 * ledger subtracts them from the day's expected deposit like spend, but they
 * live apart from expenses — the shop gets this money back, and expense
 * totals must stay what the branch actually spent. Same closing rule as
 * expenses: a day covered by a deposit is reconciled, and nothing may
 * quietly edit its figures after.
 */
class AdvanceController extends Controller
{
    use GuardsReconciledDays, ReadsBranchScope;

    /** GET /api/advances?storeId=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->requireStoreRange($request);

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        $advances = Advance::query()
            ->where('store_id', $storeId)
            ->whereBetween('day', [$request->query('from'), $request->query('to')])
            ->orderBy('at')
            ->get();

        return response()->json($advances->map(fn (Advance $a) => $a->toWire()));
    }

    /** POST /api/advances — JSON, one advance */
    public function store(Request $request): JsonResponse
    {
        $fields = $request->validate([
            'storeId' => ['required', 'string'],
            'day' => ['required', 'date_format:Y-m-d'],
            'employee' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['sometimes', 'string', 'max:255'],
        ]);

        if (! $this->allowed($request->user(), [$fields['storeId']])) {
            return $this->forbidden();
        }
        if ($this->dayClosed($fields['storeId'], $fields['day'])) {
            return $this->closedDay($fields['day']);
        }

        $advance = Advance::query()->create([
            'store_id' => $fields['storeId'],
            'day' => $fields['day'],
            'employee' => trim($fields['employee']),
            'amount' => round((float) $fields['amount'], 2),
            'note' => trim((string) ($fields['note'] ?? '')),
            'at' => now(),
        ]);

        return response()->json($advance->toWire());
    }

    /** PATCH /api/advances/{advance} */
    public function update(Request $request, string $advance): JsonResponse
    {
        $found = $this->editable($request, $advance);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        $patch = $request->all();
        Validator::make($patch, [
            'employee' => ['sometimes', 'string', 'max:120'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'note' => ['sometimes', 'string', 'max:255'],
        ])->validate();

        $found->fill([
            ...(isset($patch['employee']) ? ['employee' => trim((string) $patch['employee'])] : []),
            ...(isset($patch['amount']) ? ['amount' => round((float) $patch['amount'], 2)] : []),
            ...(isset($patch['note']) ? ['note' => trim((string) $patch['note'])] : []),
        ])->save();

        return response()->json($found->toWire());
    }

    /** DELETE /api/advances/{advance} */
    public function destroy(Request $request, string $advance): Response|JsonResponse
    {
        $found = $this->editable($request, $advance);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        $found->delete();

        return response()->noContent();
    }

    /**
     * The guards every mutation of an existing advance shares: it exists,
     * it is the caller's branch, and its day is not sealed by a deposit.
     */
    private function editable(Request $request, string $id): Advance|JsonResponse
    {
        $found = Advance::query()->find($id);
        if ($found === null) {
            return $this->notFound('That advance is no longer there.');
        }
        if (! $this->allowed($request->user(), [$found->store_id])) {
            return $this->forbidden();
        }
        if ($this->dayClosed($found->store_id, $found->day)) {
            return $this->closedDay($found->day);
        }

        return $found;
    }
}
