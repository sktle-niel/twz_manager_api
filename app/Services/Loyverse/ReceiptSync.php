<?php

namespace App\Services\Loyverse;

use App\Models\Receipt;
use App\Models\Setting;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/*
 * Pulls receipts from Loyverse into the local table, the way docs/LOYVERSE.md
 * prescribes: incrementally by updated_at (a cancelled or refunded receipt
 * gets its updated_at bumped, so polling on created_at would miss the exact
 * corrections this app exists to catch), with a two-minute overlap against
 * clock skew, upserted by receipt_number so a correction overwrites its old
 * self.
 *
 * The watermark only advances when a run reaches the last page. A capped run
 * that stopped mid-walk keeps the old mark and simply resumes from it next
 * time — receipts are never skipped because pagination order is not ours to
 * assume.
 */
class ReceiptSync
{
    private const WATERMARK = 'loyverse_receipts_watermark';

    private const SYNCED_AT = 'loyverse:receipts-synced-at';

    private const LOCK = 'loyverse:receipt-sync';

    public function __construct(private readonly LoyverseClient $client) {}

    /**
     * The read-path entry: cheap when fresh, bounded when stale, and never
     * the reason a page fails — Loyverse being down serves yesterday's copy.
     */
    public function refreshIfStale(): void
    {
        if ((string) config('loyverse.token') === '') {
            return;
        }
        if (Cache::has(self::SYNCED_AT)) {
            return;
        }

        $lock = Cache::lock(self::LOCK, 60);
        if (! $lock->get()) {
            return; // Someone else is already pulling; stale-but-consistent is fine
        }

        try {
            /* On PHP's 30-second web clock, so the wait for Loyverse must be
               far shorter than the command's — give up fast, serve local */
            $this->client->usingTimeout((int) config('loyverse.timeout_inline'));
            $this->run((int) config('loyverse.sync_pages_inline'));
        } catch (\Throwable $e) {
            Log::warning('Receipt sync skipped; serving the local copy.', ['reason' => $e->getMessage()]);
            /* A short backoff, or every page view pays the full wait while
               Loyverse is having a bad minute */
            Cache::put(self::SYNCED_AT, 'failed', 60);
        } finally {
            $lock->release();
        }
    }

    /** @return array{pages: int, upserted: int, skipped: int, done: bool} */
    public function run(int $maxPages): array
    {
        $stores = Store::query()
            ->whereNotNull('loyverse_store_id')
            ->get()
            ->keyBy('loyverse_store_id');

        $since = Setting::read(self::WATERMARK);
        $sinceInstant = $since !== null
            ? CarbonImmutable::parse($since)
            : CarbonImmutable::now('UTC')->subDays((int) config('loyverse.backfill_days'));

        $cursor = null;
        $pages = 0;
        $upserted = 0;
        $skipped = 0;
        $high = $sinceInstant;

        do {
            $query = ['updated_at_min' => $sinceInstant->subMinutes(2)->toIso8601ZuluString()];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }

            $answer = $this->client->receipts($query);
            $pages++;

            foreach ((array) ($answer['receipts'] ?? []) as $raw) {
                $store = $stores->get((string) ($raw['store_id'] ?? ''));
                if ($store === null) {
                    $skipped++; // A store nobody linked; its receipts are not ours to count

                    continue;
                }

                $this->upsert($store, $raw);
                $upserted++;

                $updated = CarbonImmutable::parse($raw['updated_at'] ?? $raw['receipt_date']);
                if ($updated->greaterThan($high)) {
                    $high = $updated;
                }
            }

            $cursor = $answer['cursor'] ?? null;
        } while ($cursor !== null && $pages < $maxPages);

        $done = $cursor === null;
        if ($done) {
            Setting::write(self::WATERMARK, $high->toIso8601ZuluString());
            Cache::put(self::SYNCED_AT, now()->toIso8601String(), (int) config('loyverse.receipt_stale_after'));
        }

        return ['pages' => $pages, 'upserted' => $upserted, 'skipped' => $skipped, 'done' => $done];
    }

    /** @param array<string, mixed> $raw */
    private function upsert(Store $store, array $raw): void
    {
        $type = strtoupper((string) ($raw['receipt_type'] ?? 'SALE'));
        // Refunds are stored negative: the money left the drawer on THIS day
        $sign = $type === 'REFUND' ? -1 : 1;

        $instant = CarbonImmutable::parse((string) $raw['receipt_date']);
        $local = $instant->setTimezone($store->timezone);

        Receipt::query()->updateOrCreate(
            ['receipt_number' => (string) $raw['receipt_number']],
            [
                'store_id' => $store->id,
                'type' => $type,
                'day' => $local->format('Y-m-d'),
                'hour' => (int) $local->format('G'),
                'receipt_date' => $instant->utc(),
                'gross' => round($sign * (float) ($raw['total_money'] ?? 0), 2),
                'cost' => round($sign * $this->costOf($raw), 2),
                'cancelled' => ! empty($raw['cancelled_at']),
                'loyverse_updated_at' => CarbonImmutable::parse(
                    (string) ($raw['updated_at'] ?? $raw['receipt_date']),
                )->utc(),
            ],
        );
    }

    /**
     * What the goods on the receipt cost the shop — the other half of gross
     * profit. Loyverse carries it per line as cost_total (cost x quantity).
     *
     * @param  array<string, mixed>  $raw
     */
    private function costOf(array $raw): float
    {
        $total = 0.0;
        foreach ((array) ($raw['line_items'] ?? []) as $line) {
            $total += (float) ($line['cost_total']
                ?? (float) ($line['cost'] ?? 0) * (float) ($line['quantity'] ?? 1));
        }

        return $total;
    }
}
