<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsBranchScope;
use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\DepositProof;
use App\Models\Expense;
use App\Models\ExpensePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/*
 * Stored photos, served same-origin and behind the same session cookie as
 * everything else (docs/API.md "Stored images") — receipts and slips are
 * financial records, not public assets, so no public disk and no symlink.
 *
 * Being signed in is not enough, and used to be.
 *
 * Every JSON endpoint that hands out one of these URLs already scopes it to
 * the caller's branch — but this door accepted any path from any account, so
 * a manager who kept a URL, was sent one in a chat, or read one over a
 * colleague's shoulder could pull any branch's deposit slips and expense
 * receipts, for as long as the file existed. In a cash business those photos
 * ARE the evidence. The path is now resolved back to the row that registered
 * it and run through the same branch check the JSON reads use.
 *
 * A denial answers 404 rather than 403: whether a particular photo exists is
 * itself something another branch has no business learning.
 */
class FileController extends Controller
{
    use ReadsBranchScope;

    /** GET /api/files/{path} */
    public function show(Request $request, string $path): Response
    {
        /* Only the receipts and avatars trees are servable, and no path
           segment may climb out of them */
        $servable = str_starts_with($path, 'receipts/') || str_starts_with($path, 'avatars/');
        if (! $servable || str_contains($path, '..')) {
            abort(404);
        }

        /* Colleagues' faces, shown beside their names on screens both roles
           already see. There is no branch to scope them to, and gating them
           would break the manager list to protect nothing. */
        if (str_starts_with($path, 'avatars/')) {
            return $this->serve($path);
        }

        $branch = $this->branchOf($path);
        if ($branch === null || ! $this->allowed($request->user(), [$branch])) {
            abort(404);
        }

        return $this->serve($path);
    }

    /**
     * The branch a stored `receipts/` path belongs to, or null when nothing
     * claims it.
     *
     * Resolved by looking the exact stored string up, never by parsing: a
     * slip carries its STORE id in the path but a proof carries a DEPOSIT id
     * in the same position, and a rule that read one as the other would
     * quietly hand a manager the wrong verdict. Three indexed lookups at
     * worst, and only for paths that got past the prefix check.
     */
    private function branchOf(string $path): ?string
    {
        $slipStore = Deposit::query()->where('slip_path', $path)->value('store_id');
        if ($slipStore !== null) {
            return (string) $slipStore;
        }

        $depositId = DepositProof::query()->where('path', $path)->value('deposit_id');
        if ($depositId !== null) {
            $store = Deposit::query()->whereKey($depositId)->value('store_id');

            return $store === null ? null : (string) $store;
        }

        $expenseId = ExpensePhoto::query()->where('path', $path)->value('expense_id');
        if ($expenseId !== null) {
            $store = Expense::query()->whereKey($expenseId)->value('store_id');

            return $store === null ? null : (string) $store;
        }

        return null;
    }

    /**
     * The same headers Laravel puts on its own private-file route: never
     * sniffed into something executable, and unable to pull anything of its
     * own if it ever renders as a document. Both are inert for the <img>
     * tags these actually appear in.
     */
    private function serve(string $path): Response
    {
        if (! Storage::exists($path)) {
            abort(404);
        }

        return Storage::response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
