<?php

namespace App\Support;

use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/*
 * The global search, server-side — GET /api/search per docs/API.md. The
 * frontend used to build this index in the browser from data already on the
 * page; the matching rules moved here unchanged so the same queries keep
 * working: every token must appear somewhere in a record's haystack, and a
 * recognised date ("july 3", "7/3/2026") narrows by the record's own day
 * instead of substring-matching half the corpus.
 *
 * Each haystack spells the record's date several ways, its amounts as bare
 * digits, and its own kind — one mechanism instead of a mode per field.
 */
class GlobalSearch
{
    /** How far back the corpus reaches; the frontend footer says this out loud */
    public const WINDOW_DAYS = 90;

    /** Per group; `total` still counts every match so the UI can say the rest */
    public const PER_GROUP = 6;

    private const MONTHS = [
        'january', 'february', 'march', 'april', 'may', 'june',
        'july', 'august', 'september', 'october', 'november', 'december',
    ];

    private const STATUS_LABEL = [
        'open' => 'Open',
        'pending' => 'Pending deposit',
        'matched' => 'Matched',
        'discrepancy' => 'Discrepancy',
    ];

    /**
     * @param  list<string>  $storeIds
     * @return array<string, array{items: list<array<string, mixed>>, total: int}>
     */
    public function run(User $user, array $storeIds, string $q): array
    {
        ['hint' => $hint, 'tokens' => $tokens] = $this->parse($q);

        $stores = Store::query()->whereIn('id', $storeIds)->get()->keyBy('id');
        $names = $stores->map(fn (Store $s) => $s->name);

        if ($hint === null && $tokens === []) {
            return $this->shape(collect(), collect(), collect(), collect(), collect());
        }

        /* Today is per branch; the window ends at the latest branch-local day */
        $to = $stores
            ->map(fn (Store $s) => CarbonImmutable::now($s->timezone)->format('Y-m-d'))
            ->max() ?? CarbonImmutable::now('Asia/Manila')->format('Y-m-d');
        $from = CarbonImmutable::createFromFormat('Y-m-d', $to)
            ->subDays(self::WINDOW_DAYS)
            ->format('Y-m-d');

        $match = fn (string $haystack) => $this->tokensHit($tokens, $haystack);

        $days = (new AuditLedger)->rows($storeIds, $from, $to)
            ->filter(function (array $row) use ($hint, $match, $names) {
                $covers = $row['depositCovers'];
                $judged = $row['depositExpected']
                    ?? (($covers !== null && count($covers) > 1) ? null : $row['expected']);

                return ($hint === null || $this->hintHits($hint, [$row['day']]))
                    && $match($this->hay(
                        'audited day audit',
                        $names[$row['storeId']] ?? $row['storeId'],
                        $this->dateWords($row['day']),
                        self::STATUS_LABEL[$row['status']],
                        $this->signWord($row['deposited'], $row['online'], $judged),
                        $this->num($row['profit']),
                        $this->num($row['expenses']),
                        $this->num($row['expected']),
                        $row['deposited'] !== null ? $this->num($row['deposited']) : null,
                    ));
            })
            ->sortByDesc('day')
            ->values();

        $expenses = Expense::query()
            ->with('photos')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->orderByDesc('day')
            ->orderByDesc('at')
            ->get()
            ->filter(fn (Expense $e) => ($hint === null || $this->hintHits($hint, [$e->day]))
                && $match($this->hay(
                    'expense',
                    $e->note,
                    $e->category,
                    $names[$e->store_id] ?? $e->store_id,
                    $this->dateWords($e->day),
                    $this->num($e->amount),
                    $e->at->timezone($stores[$e->store_id]->timezone ?? 'Asia/Manila')->format('g:i A'),
                    $e->photos->isEmpty() ? 'no receipt' : 'receipt',
                )))
            ->map(fn (Expense $e) => $e->toWire())
            ->values();

        $deposits = Deposit::query()
            ->with('days')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('day', [$from, $to])
            ->orderByDesc('day')
            ->get()
            ->filter(function (Deposit $d) use ($hint, $match, $names) {
                $covered = $d->days->pluck('day')->all();

                return ($hint === null || $this->hintHits($hint, [$d->day, ...$covered]))
                    && $match($this->hay(
                        'deposit slip',
                        $d->reference,
                        $names[$d->store_id] ?? $d->store_id,
                        $d->matched ? 'Matched' : 'Discrepancy',
                        $this->signWord(
                            (float) $d->amount,
                            (float) $d->online,
                            $d->expected !== null ? (float) $d->expected : null,
                        ),
                        $this->num($d->amount),
                        (float) $d->online > 0 ? $this->num($d->online) : null,
                        $d->expected !== null ? $this->num($d->expected) : null,
                        implode(' ', array_map($this->dateWords(...), [$d->day, ...$covered])),
                    ));
            })
            ->map(fn (Deposit $d) => $d->toWire())
            ->values();

        /* Accounts and branches are the owner's to see, and they carry no day
           of their own — a dated query is not asking for them */
        $managers = collect();
        $branches = collect();
        if ($user->isOwner() && $hint === null) {
            $holders = User::query()
                ->where('role', User::ROLE_MANAGER)
                ->orderBy('name')
                ->get();

            $managers = $holders
                ->filter(fn (User $m) => $match($this->hay(
                    'manager account',
                    $m->name,
                    $m->username,
                    $m->store_id !== null ? ($names[$m->store_id] ?? null) : 'unassigned',
                    $m->active ? 'active' : 'disabled',
                )))
                ->map(fn (User $m) => Identity::manager($m))
                ->values();

            $branches = $stores
                ->values()
                ->map(function (Store $s) use ($holders, $match) {
                    $held = $holders->firstWhere('store_id', $s->id);
                    $hit = $match($this->hay(
                        'branch store',
                        $s->name,
                        $held?->name ?? 'unassigned no manager',
                    ));

                    return $hit
                        ? ['id' => $s->id, 'name' => $s->name, 'managerName' => $held?->name]
                        : null;
                })
                ->filter()
                ->values();
        }

        return $this->shape($days, $expenses, $deposits, $managers, $branches);
    }

    /**
     * @return array<string, array{items: list<array<string, mixed>>, total: int}>
     */
    private function shape(
        Collection $days,
        Collection $expenses,
        Collection $deposits,
        Collection $managers,
        Collection $branches,
    ): array {
        $group = fn (Collection $all) => [
            'items' => $all->take(self::PER_GROUP)->values()->all(),
            'total' => $all->count(),
        ];

        return [
            'days' => $group($days),
            'expenses' => $group($expenses),
            'deposits' => $group($deposits),
            'managers' => $group($managers),
            'branches' => $group($branches),
        ];
    }

    /** @param list<string> $tokens */
    private function tokensHit(array $tokens, string $haystack): bool
    {
        foreach ($tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{year?: int, month?: int, day?: int}  $hint
     * @param  list<string>  $days  `Y-m-d` — a hit on any of them is a hit
     */
    private function hintHits(array $hint, array $days): bool
    {
        foreach ($days as $day) {
            [$y, $m, $d] = array_map(intval(...), explode('-', $day));
            $hit = (! isset($hint['year']) || $y === $hint['year'])
                && (! isset($hint['month']) || $m === $hint['month'])
                && (! isset($hint['day']) || $d === $hint['day']);
            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a query into a date and whatever else was typed — the same rules
     * the client's index used, so "meals july 3" still means meals ON that
     * day, while bare numbers ("480", "712063") stay ordinary tokens.
     *
     * @return array{hint: ?array{year?: int, month?: int, day?: int}, tokens: list<string>}
     */
    private function parse(string $raw): array
    {
        $words = preg_split(
            '/\s+/u',
            mb_strtolower(str_replace(',', ' ', trim($raw))),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        $hint = [];
        $loose = [];
        $numbers = [];
        $dated = false;

        foreach ($words as $w) {
            if (preg_match('#^(\d{4})[-/](\d{1,2})(?:[-/](\d{1,2}))?$#', $w, $m)) {
                $hint['year'] = (int) $m[1];
                $hint['month'] = (int) $m[2];
                if (($m[3] ?? '') !== '') {
                    $hint['day'] = (int) $m[3];
                }
                $dated = true;

                continue;
            }

            if (preg_match('#^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?$#', $w, $m)) {
                $hint['month'] = (int) $m[1];
                $hint['day'] = (int) $m[2];
                if (($m[3] ?? '') !== '') {
                    $hint['year'] = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
                }
                $dated = true;

                continue;
            }

            // A month name, or enough of one to be unambiguous ("jul", "sept")
            $month = null;
            if (mb_strlen($w) >= 3) {
                foreach (self::MONTHS as $i => $name) {
                    if (str_starts_with($name, $w)) {
                        $month = $i + 1;
                        break;
                    }
                }
            }
            if ($month !== null) {
                $hint['month'] = $month;
                $dated = true;

                continue;
            }

            if (preg_match('/^\d{4}$/', $w) || preg_match('/^\d{1,2}(st|nd|rd|th)?$/', $w)) {
                $numbers[] = $w;

                continue;
            }

            $loose[] = $w;
        }

        if (! $dated) {
            return ['hint' => null, 'tokens' => [...$loose, ...$numbers]];
        }

        // With a month named, bare numbers become the day or the year
        foreach ($numbers as $n) {
            if (preg_match('/^\d{4}$/', $n)) {
                $hint['year'] = (int) $n;
            } elseif (! isset($hint['day'])) {
                $hint['day'] = (int) $n;
            } else {
                $loose[] = $n;
            }
        }

        return ['hint' => $hint, 'tokens' => $loose];
    }

    /** Every way a reader might write this day, so "july" reaches it */
    private function dateWords(string $day): string
    {
        $d = CarbonImmutable::createFromFormat('Y-m-d', $day);

        return implode(' ', [
            $d->format('D, M j'),
            strtolower($d->format('F')),
            $d->format('j'),
            $d->format('Y'),
            $day,
            $d->format('n/j/Y'),
        ]);
    }

    /** 'over' or 'short', so those words find a deposit the way the UI names it */
    private function signWord(?float $deposited, ?float $online, ?float $judged): ?string
    {
        if ($deposited === null || $judged === null) {
            return null;
        }
        $diff = round(($deposited + ($online ?? 0.0)) - $judged, 2);

        return $diff > 0 ? 'over' : ($diff < 0 ? 'short' : null);
    }

    /** Amounts as the bare digits a reader would type: 4500, 180.5 */
    private function num(mixed $value): string
    {
        $s = number_format((float) $value, 2, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }

    private function hay(string|int|float|null ...$parts): string
    {
        $kept = array_filter(
            $parts,
            fn ($p) => $p !== null && $p !== '',
        );

        return mb_strtolower(implode(' ', $kept));
    }
}
