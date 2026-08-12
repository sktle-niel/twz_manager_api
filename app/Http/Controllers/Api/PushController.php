<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/*
 * Push subscriptions: each row is one browser's mailbox for reminders. The
 * browser owns the endpoint; this side only remembers it against the account
 * that subscribed, and forgets it on request or when the push service says
 * it is gone.
 */
class PushController extends Controller
{
    /** GET /api/push/key — the VAPID public key the browser subscribes with */
    public function key(): JsonResponse
    {
        return response()->json(['key' => (string) config('push.public_key')]);
    }

    /** POST /api/push/subscriptions */
    public function store(Request $request): Response
    {
        $fields = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        /* The same browser re-subscribing moves the mailbox to whoever is
           signed in now — one endpoint never serves two accounts */
        PushSubscription::query()->updateOrCreate(
            ['endpoint' => $fields['endpoint']],
            [
                'user_id' => $request->user()->id,
                'p256dh' => $fields['keys']['p256dh'],
                'auth' => $fields['keys']['auth'],
            ],
        );

        return response()->noContent();
    }

    /** DELETE /api/push/subscriptions */
    public function destroy(Request $request): Response
    {
        $fields = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        PushSubscription::query()
            ->where('endpoint', $fields['endpoint'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->noContent();
    }
}
