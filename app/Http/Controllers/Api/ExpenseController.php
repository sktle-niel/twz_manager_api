<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Concerns\ReadsMultipart;
use App\Http\Controllers\Controller;
use App\Models\DepositDay;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpensePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/*
 * What a branch spent, logged by its manager. Photos come in as multipart
 * per docs/API.md. A day already covered by a deposit is closed — its figures
 * are what the owner reconciled, and nothing may quietly edit them after.
 */
class ExpenseController extends Controller
{
    use ReadsBranchScope, ReadsMultipart;

    /** GET /api/expenses?storeId=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $storeId = $this->requireStoreRange($request);

        if (! $this->allowed($request->user(), [$storeId])) {
            return $this->forbidden();
        }

        $expenses = Expense::query()
            ->with('photos')
            ->where('store_id', $storeId)
            ->whereBetween('day', [$request->query('from'), $request->query('to')])
            ->orderBy('at')
            ->get();

        return response()->json($expenses->map(fn (Expense $e) => $e->toWire()));
    }

    /** POST /api/expenses — multipart: payload {items: […]}, receipts[i][] file parts */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->payload($request);
        Validator::make($payload, [
            'items' => ['required', 'array', 'min:1'],
            'items.*.storeId' => ['required', 'string'],
            'items.*.day' => ['required', 'date_format:Y-m-d'],
            'items.*.category' => ['required', 'string'],
            'items.*.note' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'gt:0'],
        ])->validate();

        $user = $request->user();
        $categories = ExpenseCategory::query()->pluck('name')->all();

        foreach ($payload['items'] as $item) {
            if (! $this->allowed($user, [$item['storeId']])) {
                return $this->forbidden();
            }
            if (! in_array($item['category'], $categories, true)) {
                return $this->unknownCategory($item['category']);
            }
            if ($this->dayClosed($item['storeId'], $item['day'])) {
                return $this->closedDay($item['day']);
            }
        }

        $created = [];
        foreach ($payload['items'] as $i => $item) {
            $expense = Expense::query()->create([
                'store_id' => $item['storeId'],
                'day' => $item['day'],
                'category' => $item['category'],
                'note' => $item['note'],
                'amount' => round((float) $item['amount'], 2),
                'at' => now(),
            ]);

            foreach ($this->files($request, "receipts.{$i}") as $file) {
                $this->attach($expense, $file);
            }

            $created[] = $expense->load('photos')->toWire();
        }

        return response()->json($created);
    }

    /** PATCH /api/expenses/{expense} — JSON, or multipart when photos change */
    public function update(Request $request, string $expense): JsonResponse
    {
        $found = $this->editable($request, $expense);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        $patch = $request->isJson() ? $request->all() : $this->payload($request);
        Validator::make($patch, [
            'category' => ['sometimes', 'string'],
            'note' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'keepReceipts' => ['sometimes', 'array'],
        ])->validate();

        if (isset($patch['category'])
            && ! ExpenseCategory::query()->where('name', $patch['category'])->exists()) {
            return $this->unknownCategory($patch['category']);
        }

        $found->fill([
            ...(isset($patch['category']) ? ['category' => $patch['category']] : []),
            ...(isset($patch['note']) ? ['note' => $patch['note']] : []),
            ...(isset($patch['amount']) ? ['amount' => round((float) $patch['amount'], 2)] : []),
        ])->save();

        /* keepReceipts lists the stored URLs that survive; anything stored
           but absent was removed on the phone, and new files are added */
        if (array_key_exists('keepReceipts', $patch)) {
            $keep = (array) $patch['keepReceipts'];
            foreach ($found->photos as $photo) {
                if (! in_array("/api/files/{$photo->path}", $keep, true)) {
                    Storage::delete($photo->path);
                    $photo->delete();
                }
            }
            foreach ($this->files($request, 'receipts') as $file) {
                $this->attach($found, $file);
            }
        }

        return response()->json($found->load('photos')->toWire());
    }

    /** DELETE /api/expenses/{expense} */
    public function destroy(Request $request, string $expense): Response|JsonResponse
    {
        $found = $this->editable($request, $expense);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        foreach ($found->photos as $photo) {
            Storage::delete($photo->path);
        }
        $found->photos()->delete();
        $found->delete();

        return response()->noContent();
    }

    /**
     * The guards every mutation of an existing expense shares: it exists,
     * it is the caller's branch, and its day is not sealed by a deposit.
     */
    private function editable(Request $request, string $id): Expense|JsonResponse
    {
        $found = Expense::query()->with('photos')->find($id);
        if ($found === null) {
            return $this->notFound('That expense is no longer there.');
        }
        if (! $this->allowed($request->user(), [$found->store_id])) {
            return $this->forbidden();
        }
        if ($this->dayClosed($found->store_id, $found->day)) {
            return $this->closedDay($found->day);
        }

        return $found;
    }

    private function attach(Expense $expense, UploadedFile $file): void
    {
        $path = $file->store("receipts/expenses/{$expense->id}");
        if ($path === false) {
            return;
        }
        ExpensePhoto::query()->create(['expense_id' => $expense->id, 'path' => $path]);
    }

    private function dayClosed(string $storeId, string $day): bool
    {
        return DepositDay::query()->where('store_id', $storeId)->where('day', $day)->exists();
    }

    private function unknownCategory(string $name): JsonResponse
    {
        return response()->json(['message' => "{$name} is not a category anymore."], 422);
    }

    private function closedDay(string $day): JsonResponse
    {
        return response()->json(
            ['message' => "{$day} is already covered by a deposit, so its expenses are final."],
            422,
        );
    }
}
