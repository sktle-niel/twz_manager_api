<?php

namespace App\Http\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/*
 * The two facts every branch-scoped read shares.
 *
 * One: the contract sends arrays as repeated bare keys (?storeIds=a&storeIds=b,
 * docs/API.md "Query arrays"), which PHP collapses to the LAST value — so the
 * list is read off the raw query string, not the parsed bag.
 *
 * Two: the storeIds argument is the caller's request, never their authority.
 * A manager reads their own branch and nothing else; the owner reads any.
 */
trait ReadsBranchScope
{
    /** @return list<string> */
    private function storeIds(Request $request, string $key = 'storeIds'): array
    {
        $values = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING')) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($k) === $key && $v !== '') {
                $values[] = urldecode($v);
            }
        }

        return array_values(array_unique($values));
    }

    /** @param list<string> $storeIds */
    private function allowed(?User $user, array $storeIds): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user->isOwner()) {
            return true;
        }

        return $storeIds === [$user->store_id];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You do not have access to that.'], 403);
    }
}
