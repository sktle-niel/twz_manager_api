<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/*
 * The browser-run installer, for deploys that never open a shell: one visit
 * to /setup/{key} does what SSH would — migrate, seed a fresh database, set
 * the ledger's start day, and rebuild the caches. Idempotent on purpose, so
 * a second visit reports rather than wrecks. Guarded by SETUP_KEY from .env;
 * with no key configured the route answers 404 like it does not exist.
 */
class SetupController extends Controller
{
    public function __invoke(Request $request, string $key): JsonResponse
    {
        $expected = (string) config('twz.setup_key');
        if ($expected === '' || ! hash_equals($expected, $key)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $done = [];

        Artisan::call('migrate', ['--force' => true]);
        $done['migrate'] = trim(Artisan::output()) ?: 'nothing to migrate';

        if (User::query()->count() === 0) {
            Artisan::call('db:seed', ['--force' => true]);
            $done['seed'] = 'branches and accounts seeded — change every password and the reset PIN now';
        } else {
            $done['seed'] = 'skipped: accounts already exist';
        }

        $start = (string) $request->query('start', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1) {
            Setting::write('audit_start_day', $start);
            $done['start_day'] = $start;
        } else {
            $done['start_day'] = Setting::read('audit_start_day') ?? 'not set — pass ?start=YYYY-MM-DD';
        }

        /* A cached testing/dev config poisons every run after it — the boot
           speedup is only worth having on the real server */
        if (app()->environment('production')) {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            $done['caches'] = 'config and routes cached';
        } else {
            $done['caches'] = 'skipped outside production';
        }

        $done['next'] = 'Delete the SETUP_KEY line from .env, set the cron entry, and sign in.';

        return response()->json($done);
    }
}
