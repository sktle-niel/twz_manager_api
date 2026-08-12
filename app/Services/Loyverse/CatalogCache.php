<?php

namespace App\Services\Loyverse;

use Illuminate\Support\Facades\Cache;

/*
 * The item catalog, walked whole and kept in the cache. The walk takes
 * minutes for a real catalog — far past PHP's 30-second web clock — so it
 * runs on the scheduler's clock, never a page view's. Searches only ever
 * read the walked copy; while none exists yet they say so instead of
 * trying to fetch one.
 */
class CatalogCache
{
    private const CACHE = 'loyverse:catalog';

    public function __construct(private readonly LoyverseClient $client) {}

    /** @return list<array{sku: string, name: string}>|null null = never walked */
    public static function read(): ?array
    {
        return Cache::get(self::CACHE);
    }

    /**
     * Walk the whole catalog and store it. A failed walk throws and leaves
     * the previous copy serving searches.
     */
    public function rebuild(): int
    {
        $items = [];
        $cursor = null;
        do {
            $answer = $this->client->items($cursor !== null ? ['cursor' => $cursor] : []);
            foreach ((array) ($answer['items'] ?? []) as $raw) {
                $name = trim((string) ($raw['item_name'] ?? ''));
                // SKUs live on variants; a one-variant item is the common case
                foreach ((array) ($raw['variants'] ?? []) as $variant) {
                    $sku = trim((string) ($variant['sku'] ?? ''));
                    if ($sku === '') {
                        continue;
                    }
                    $option = trim((string) ($variant['option1_value'] ?? ''));
                    $items[] = [
                        'sku' => $sku,
                        'name' => $option !== '' ? "{$name} — {$option}" : $name,
                    ];
                }
            }
            $cursor = $answer['cursor'] ?? null;
        } while ($cursor !== null);

        Cache::put(self::CACHE, $items, (int) config('loyverse.catalog_ttl'));

        return count($items);
    }
}
