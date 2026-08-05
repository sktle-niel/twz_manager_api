<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The category list, edited by the owner as one whole (the Settings page
 * saves the full list per keystrokeless batch, docs/API.md). Renames and
 * removals never rewrite past expenses — the name travels on each expense.
 */
class ExpenseCategoryController extends Controller
{
    /** GET /api/expense-categories — managers pick from these */
    public function index(): JsonResponse
    {
        return response()->json(
            ExpenseCategory::query()->orderBy('position')->get()
                ->map(fn (ExpenseCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'receiptExempt' => $c->receipt_exempt,
                ]),
        );
    }

    /** PUT /api/expense-categories — owner only, the whole list at once */
    public function replace(Request $request): JsonResponse
    {
        $data = $request->validate([
            '*' => ['array'],
            '*.id' => ['required', 'string'],
            '*.name' => ['required', 'string', 'max:60'],
            '*.receiptExempt' => ['required', 'boolean'],
        ]);

        $names = array_map(fn (array $c) => mb_strtolower(trim($c['name'])), $data);
        if (in_array('', $names, true)) {
            return response()->json(['message' => 'A category cannot be blank.'], 422);
        }
        if (count($names) !== count(array_unique($names))) {
            return response()->json(['message' => 'Two categories cannot share a name.'], 422);
        }

        $keptIds = array_map(fn (array $c) => $c['id'], $data);
        ExpenseCategory::query()->whereNotIn('id', $keptIds)->delete();
        foreach ($data as $position => $category) {
            ExpenseCategory::query()->updateOrCreate(['id' => $category['id']], [
                'name' => trim($category['name']),
                'receipt_exempt' => $category['receiptExempt'],
                'position' => $position,
            ]);
        }

        return $this->index();
    }
}
