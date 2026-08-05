<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/*
 * How many audited days one bank deposit may cover. Reading it is open to
 * both roles — the manager's status card counts the backlog against this
 * window — but only the owner turns the dial.
 */
class ReconciliationController extends Controller
{
    private const KEY = 'batch_window_days';

    private const DEFAULT = 3;

    /** GET /api/settings/reconciliation */
    public function show(): JsonResponse
    {
        return response()->json([
            'batchWindowDays' => (int) (Setting::read(self::KEY) ?? self::DEFAULT),
        ]);
    }

    /** PATCH /api/settings/reconciliation — owner only */
    public function update(Request $request): Response
    {
        $data = $request->validate([
            'batchWindowDays' => ['required', 'integer', 'min:1', 'max:14'],
        ]);

        Setting::write(self::KEY, (string) $data['batchWindowDays']);

        return response()->noContent();
    }
}
