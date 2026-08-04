<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    /** GET /api/stores — readable by both roles; the manager UI needs branch names */
    public function index(): JsonResponse
    {
        return response()->json(
            Store::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name]),
        );
    }
}
