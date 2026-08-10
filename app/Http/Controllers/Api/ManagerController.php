<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\Identity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

/*
 * The branch manager accounts — created HERE, by the owner, never synced
 * from anywhere. Loyverse knows stores and receipts; who is allowed to open
 * this app is this system's own ledger.
 *
 * One branch, one manager is the invariant, and the swap enforces it:
 * assigning an occupied branch exchanges the two managers server-side, so
 * the client can never even ask for a doubled-up branch.
 */
class ManagerController extends Controller
{
    /** GET /api/managers */
    public function index(): JsonResponse
    {
        return response()->json($this->everyone());
    }

    /** POST /api/managers — the owner sets both halves of the credential */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'name' => ['required', 'string', 'max:80'],
                'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
                'storeId' => ['required', 'string'],
                'password' => ['required', 'string', PasswordRule::min(8)],
            ],
            [
                'username.regex' => 'Letters, numbers, dots, dashes and underscores only.',
            ],
        );

        $username = mb_strtolower(trim($data['username']));
        if (User::query()->whereRaw('lower(username) = ?', [$username])->exists()) {
            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => ['username' => 'That username is taken.'],
            ], 422);
        }
        if (Store::query()->whereKey($data['storeId'])->doesntExist()) {
            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => ['storeId' => 'That branch is no longer there.'],
            ], 422);
        }
        if (User::query()->where('role', User::ROLE_MANAGER)->where('store_id', $data['storeId'])->exists()) {
            return response()->json([
                'message' => 'Check the highlighted fields.',
                'fields' => ['storeId' => 'That branch already has a manager.'],
            ], 422);
        }

        $manager = User::query()->create([
            'name' => trim($data['name']),
            'username' => $username,
            'password' => $data['password'],
            'role' => User::ROLE_MANAGER,
            'store_id' => $data['storeId'],
            'active' => true,
        ]);

        return response()->json(Identity::manager($manager));
    }

    /** PATCH /api/managers/{manager}/branch — occupied branches swap, server-side */
    public function assign(Request $request, string $manager): JsonResponse
    {
        $data = $request->validate(['storeId' => ['required', 'string']]);

        $moving = User::query()->where('role', User::ROLE_MANAGER)->find($manager);
        if ($moving === null) {
            return response()->json(['message' => 'That account is no longer there.'], 404);
        }
        if (Store::query()->whereKey($data['storeId'])->doesntExist()) {
            return response()->json(['message' => 'That branch is no longer there.'], 422);
        }

        $holder = User::query()
            ->where('role', User::ROLE_MANAGER)
            ->where('store_id', $data['storeId'])
            ->whereKeyNot($moving->id)
            ->first();

        $vacated = $moving->store_id;
        $moving->forceFill(['store_id' => $data['storeId']])->save();
        if ($holder !== null) {
            $holder->forceFill(['store_id' => $vacated])->save();
        }

        return response()->json($this->everyone());
    }

    /** @return list<array<string, mixed>> */
    private function everyone(): array
    {
        return User::query()
            ->where('role', User::ROLE_MANAGER)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => Identity::manager($user))
            ->all();
    }
}
