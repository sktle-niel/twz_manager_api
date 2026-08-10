<?php

namespace App\Http\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
    /** Validate the single-store day-range read and hand back its storeId. */
    private function requireStoreRange(Request $request): string
    {
        $request->validate(['storeId' => ['required', 'string'], ...self::rangeRules()]);

        return (string) $request->query('storeId');
    }

    /**
     * Validate a repeated-storeIds read plus the given query rules.
     *
     * @param  array<string, list<string>>  $rules
     * @return list<string>
     */
    private function requireStores(Request $request, array $rules): array
    {
        $storeIds = $this->storeIds($request);
        Validator::make(
            [...$request->only(array_keys($rules)), 'storeIds' => $storeIds],
            ['storeIds' => ['required', 'array', 'min:1'], ...$rules],
        )->validate();

        return $storeIds;
    }

    /** @return array<string, list<string>> The from/to pair every range read validates */
    private static function rangeRules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

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
}
