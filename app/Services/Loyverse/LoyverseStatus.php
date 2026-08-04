<?php

namespace App\Services\Loyverse;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/*
 * What the Settings page shows about the POS connection, priced for the
 * shared budget: a successful validation is trusted for status_ttl rather
 * than re-proven on every page view, and a status probe never throws — the
 * page renders what is known, the Reconnect button is the loud path.
 */
class LoyverseStatus
{
    private const CACHE_KEY = 'loyverse:status';

    public function __construct(private readonly LoyverseClient $client) {}

    /** @return array{connected: bool, storesLinked: int, tokenHint: string} */
    public function summary(): array
    {
        return [
            'connected' => $this->connected(),
            'storesLinked' => Store::query()->whereNotNull('loyverse_store_id')->count(),
            'tokenHint' => $this->tokenHint(),
        ];
    }

    /**
     * Re-validates the token against Loyverse right now.
     * Lets the client's exceptions fly — the Reconnect endpoint turns them
     * into the messages the owner reads.
     */
    public function revalidate(): void
    {
        try {
            $this->client->merchant();
        } catch (LoyverseAuthException $e) {
            Cache::put(self::CACHE_KEY, false, (int) config('loyverse.status_ttl'));
            throw $e;
        }

        Cache::put(self::CACHE_KEY, true, (int) config('loyverse.status_ttl'));
    }

    private function connected(): bool
    {
        if ((string) config('loyverse.token') === '') {
            return false;
        }

        $known = Cache::get(self::CACHE_KEY);
        if (is_bool($known)) {
            return $known;
        }

        /* Nothing cached — spend one budgeted request to find out, but never
           let a status read fail the page it decorates */
        try {
            $this->revalidate();

            return true;
        } catch (LoyverseException) {
            return false;
        }
    }

    private function tokenHint(): string
    {
        $token = (string) config('loyverse.token');

        return $token === '' ? '' : substr($token, -4);
    }
}
