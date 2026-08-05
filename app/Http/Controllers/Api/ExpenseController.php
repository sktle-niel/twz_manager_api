<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
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
 * per docs/API.md: the fields in one `payload` JSON part, each file list
 * under its own name with a [] suffix (PHP keeps only the last of repeated
 * bare names). A day already covered by a deposit is closed — its figures
 * are what the owner reconciled, and nothing may quietly edit them after.
 */
class ExpenseController extends Controller
{
    use ReadsBranchScope;

    /** GET /api/expenses?storeId=…&from=…&to=… */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'storeId' => ['required', 'string'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $storeId = (string) $request->query('storeId');

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
                return response()->json(
                    ['message' => "{$item['category']} is not a category anymore."],
                    422,
                );
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
        $found = Expense::query()->with('photos')->find($expense);
        if ($found === null) {
            return response()->json(['message' => 'That expense is no longer there.'], 404);
        }
        if (! $this->allowed($request->user(), [$found->store_id])) {
            return $this->forbidden();
        }
        if ($this->dayClosed($found->store_id, $found->day)) {
            return $this->closedDay($found->day);
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
            return response()->json(
                ['message' => "{$patch['category']} is not a category anymore."],
                422,
            );
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
        $found = Expense::query()->with('photos')->find($expense);
        if ($found === null) {
            return response()->json(['message' => 'That expense is no longer there.'], 404);
        }
        if (! $this->allowed($request->user(), [$found->store_id])) {
            return $this->forbidden();
        }
        if ($this->dayClosed($found->store_id, $found->day)) {
            return $this->closedDay($found->day);
        }

        foreach ($found->photos as $photo) {
            Storage::delete($photo->path);
        }
        $found->photos()->delete();
        $found->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> The `payload` part, parsed exactly like a JSON body */
    private function payload(Request $request): array
    {
        $raw = (string) $request->input('payload', '');
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<UploadedFile> */
    private function files(Request $request, string $key): array
    {
        $found = $request->file($key);
        if ($found === null) {
            return [];
        }

        return is_array($found) ? array_values($found) : [$found];
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

    private function closedDay(string $day): JsonResponse
    {
        return response()->json(
            ['message' => "{$day} is already covered by a deposit, so its expenses are final."],
            422,
        );
    }
}
