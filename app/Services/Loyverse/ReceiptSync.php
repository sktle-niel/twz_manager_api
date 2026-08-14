<?php

namespace App\Services\Loyverse;

use App\Models\Receipt;
use App\Models\Setting;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

use function Illuminate\Support\defer;

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
 *
 * Services and labor are not sales. Lines whose SKU is on the excluded list
 * (a Setting, seeded with the shop's services & labor items) are netted out
 * of a receipt's gross and cost at ingest — that money is not the drawer's
 * to deposit, and counting it would raise every expected figure the
 * reconciliation checks. Changing the list only affects receipts synced
 * after; a re-pull recomputes the past.
 */
class ReceiptSync
{
    private const WATERMARK = 'loyverse_receipts_watermark';

    private const SYNCED_AT = 'loyverse:receipts-synced-at';

    private const LOCK = 'loyverse:receipt-sync';

    public const EXCLUDED_SKUS = 'sales_excluded_skus';

    /** @var list<string>|null Parsed once per run, not per receipt */
    private ?array $excludedMemo = null;

    public function __construct(private readonly LoyverseClient $client) {}

    /**
     * The read-path entry: free when fresh, and no longer worth waiting on
     * when stale — the page is served the local copy at database speed and
     * the top-up runs after the response has gone out (`defer`), so the next
     * read finds the copy current. The one exception is a first run with no
     * local copy at all: nothing worth serving exists yet, so that sync alone
     * is waited on.
     */
    public function refreshIfStale(): void
    {
        if ((string) config('loyverse.token') === '') {
            return;
        }
        if (Cache::has(self::SYNCED_AT)) {
            return;
        }

        if (Setting::read(self::WATERMARK) === null) {
            $this->topUp();

            return;
        }
        defer(fn () => $this->topUp());
    }

    /** The bounded inline pull; never the reason a page fails. */
    private function topUp(): void
    {
        try {
            /* Still on PHP's 30-second web clock even after the response is
               flushed, so the wait for Loyverse stays far shorter than the
               command's — give up fast, the next read retries */
            $this->client->usingTimeout((int) config('loyverse.timeout_inline'));
            $this->run((int) config('loyverse.sync_pages_inline'));
        } catch (\Throwable $e) {
            Log::warning('Receipt sync skipped; serving the local copy.', ['reason' => $e->getMessage()]);
            /* A short backoff, or every page view pays the full wait while
               Loyverse is having a bad minute */
            Cache::put(self::SYNCED_AT, 'failed', 60);
        }
    }

    /**
     * One pull, whoever asked for it. The lock lives here rather than in any
     * caller, so the scheduled command and a read-path top-up can never walk
     * Loyverse twice at once — the second asker is told `locked` and serves
     * what is already local.
     *
     * `$reconcileDays` turns the walk into the nightly safety net: re-pull
     * everything updated in the trailing N days and upsert it wholesale,
     * repairing whatever a missed page or a skew gap left behind. A reconcile
     * NEVER writes the watermark — its high-water mark can sit behind the
     * incremental run's, and writing it would regress the mark.
     *
     * @return array{pages: int, upserted: int, skipped: int, done: bool, locked: bool}
     */
    public function run(int $maxPages, ?int $reconcileDays = null): array
    {
        /* Sized for the deep walks: a backfill or a reconcile at 45s a page
           must not outlive its own lock and let a second walker in */
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            return ['pages' => 0, 'upserted' => 0, 'skipped' => 0, 'done' => false, 'locked' => true];
        }

        try {
            return $this->walk($maxPages, $reconcileDays);
        } finally {
            $lock->release();
        }
    }

    /** @return array{pages: int, upserted: int, skipped: int, done: bool, locked: bool} */
    private function walk(int $maxPages, ?int $reconcileDays = null): array
    {
        $stores = Store::query()
            ->whereNotNull('loyverse_store_id')
            ->get()
            ->keyBy('loyverse_store_id');

        $since = Setting::read(self::WATERMARK);
        $sinceInstant = match (true) {
            $reconcileDays !== null => CarbonImmutable::now('UTC')->subDays($reconcileDays),
            $since !== null => CarbonImmutable::parse($since),
            default => CarbonImmutable::now('UTC')->subDays((int) config('loyverse.backfill_days')),
        };

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
        if ($done && $reconcileDays === null) {
            Setting::write(self::WATERMARK, $high->toIso8601ZuluString());
            Cache::put(self::SYNCED_AT, now()->toIso8601String(), (int) config('loyverse.receipt_stale_after'));
        }

        return ['pages' => $pages, 'upserted' => $upserted, 'skipped' => $skipped, 'done' => $done, 'locked' => false];
    }

    /** @param array<string, mixed> $raw */
    private function upsert(Store $store, array $raw): void
    {
        $type = strtoupper((string) ($raw['receipt_type'] ?? 'SALE'));
        // Refunds are stored negative: the money left the drawer on THIS day
        $sign = $type === 'REFUND' ? -1 : 1;

        $instant = CarbonImmutable::parse((string) $raw['receipt_date']);
        $local = $instant->setTimezone($store->timezone);
        $removed = $this->excludedShare($raw);

        Receipt::query()->updateOrCreate(
            ['receipt_number' => (string) $raw['receipt_number']],
            [
                'store_id' => $store->id,
                'type' => $type,
                'day' => $local->format('Y-m-d'),
                'hour' => (int) $local->format('G'),
                'receipt_date' => $instant->utc(),
                'gross' => round($sign * ((float) ($raw['total_money'] ?? 0) - $removed['gross']), 2),
                'cost' => round($sign * ($this->costOf($raw) - $removed['cost']), 2),
                'cancelled' => ! empty($raw['cancelled_at']),
                'loyverse_updated_at' => CarbonImmutable::parse(
                    (string) ($raw['updated_at'] ?? $raw['receipt_date']),
                )->utc(),
            ],
        );
    }

    /**
     * The services-and-labor share of a receipt: the lines whose SKU sits on
     * the excluded list, summed so the caller can net them out of the
     * receipt's own totals — kept as a subtraction from `total_money` rather
     * than a re-sum of the kept lines, so receipt-level discounts stay put.
     *
     * @param  array<string, mixed>  $raw
     * @return array{gross: float, cost: float}
     */
    private function excludedShare(array $raw): array
    {
        $skus = $this->excludedSkus();
        if ($skus === []) {
            return ['gross' => 0.0, 'cost' => 0.0];
        }

        $gross = 0.0;
        $cost = 0.0;
        foreach ((array) ($raw['line_items'] ?? []) as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($sku === '' || ! in_array($sku, $skus, true)) {
                continue;
            }
            $gross += (float) ($line['total_money'] ?? 0);
            $cost += (float) ($line['cost_total']
                ?? (float) ($line['cost'] ?? 0) * (float) ($line['quantity'] ?? 1));
        }

        return ['gross' => $gross, 'cost' => $cost];
    }

    /**
     * The excluded list as the settings page edits it: sku + name pairs,
     * stored as JSON. A plain comma-separated value (the original seed
     * format) still parses, names unknown.
     *
     * @return list<array{sku: string, name: string}>
     */
    public static function excludedItems(): array
    {
        $raw = trim((string) Setting::read(self::EXCLUDED_SKUS));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $pairs = is_array($decoded)
            ? array_map(fn ($item) => [
                'sku' => is_array($item) ? trim((string) ($item['sku'] ?? '')) : '',
                'name' => is_array($item) ? trim((string) ($item['name'] ?? '')) : '',
            ], $decoded)
            : array_map(fn (string $sku) => ['sku' => trim($sku), 'name' => ''], explode(',', $raw));

        return array_values(array_filter($pairs, fn (array $item) => $item['sku'] !== ''));
    }

    /** @return list<string> */
    private function excludedSkus(): array
    {
        return $this->excludedMemo ??= array_column(self::excludedItems(), 'sku');
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
