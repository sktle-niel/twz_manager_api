<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    /** GET /api/stores — readable by both roles; the manager UI needs branch names */
    public function index(): JsonResponse
    {
        return response()->json($this->everyone());
    }

    /**
     * PATCH /api/stores/{store} — which bank the branch deposits to. Owner
     * only (the route group). The manager's Deposits page reads this to
     * decide which bank's form the slip photo must carry, so the value is
     * held to the two banks the app knows and named as a field error
     * otherwise — the same shape every form in the app highlights.
     */
    public function update(Request $request, string $store): JsonResponse
    {
        $bank = $request->input('bank');
        if (! is_string($bank) || ! in_array($bank, Store::BANKS, true)) {
            return $this->fieldErrors(['bank' => 'Pick BDO or BPI.']);
        }

        $found = Store::query()->find($store);
        if ($found === null) {
            return $this->notFound('That branch is no longer there.');
        }

        $found->forceFill(['bank' => $bank])->save();

        return response()->json($this->everyone());
    }

    /** @return list<array{id: string, name: string, bank: string}> */
    private function everyone(): array
    {
        return Store::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => $store->toWire())
            ->all();
    }
}
