<?php

namespace App\Services\Loyverse;

use App\Models\Store;
use Illuminate\Support\Str;

/*
 * Links the branch list to Loyverse's store list, the way docs/LOYVERSE.md
 * (frontend repo) settles it: our ids are ours, theirs are theirs, and the
 * loyverse_store_id column is the only bridge. A branch that is renamed or
 * re-linked never rewrites history, because nothing else ever points at a
 * Loyverse id.
 *
 * Matching is by name, slugified on both sides, so "La Paz" finds the branch
 * seeded as `lapaz` and casing or spacing differences never split a branch
 * in two. A Loyverse store nothing matches becomes a new local branch; a
 * local branch Loyverse does not know is left alone and reported, never
 * deleted — it may hold history.
 */
class StoreSync
{
    public function __construct(private readonly LoyverseClient $client) {}

    /**
     * @return array{linked: list<string>, created: list<string>, already: list<string>, localOnly: list<string>}
     */
    public function run(): array
    {
        $remote = collect($this->client->stores()['stores'] ?? [])
            ->filter(fn (array $store) => empty($store['deleted_at']))
            ->values();

        $report = ['linked' => [], 'created' => [], 'already' => [], 'localOnly' => []];

        foreach ($remote as $store) {
            $loyverseId = (string) $store['id'];
            $name = trim((string) $store['name']);

            $existing = Store::query()->where('loyverse_store_id', $loyverseId)->first();
            if ($existing !== null) {
                $report['already'][] = $existing->name;

                continue;
            }

            $match = Store::query()
                ->whereNull('loyverse_store_id')
                ->get()
                ->first(fn (Store $local) => Str::slug($local->name) === Str::slug($name));

            if ($match !== null) {
                $match->forceFill(['loyverse_store_id' => $loyverseId])->save();
                $report['linked'][] = "{$match->name} <- {$name}";

                continue;
            }

            $created = Store::query()->create([
                'id' => $this->freshId($name),
                'name' => $name,
            ]);
            $created->forceFill(['loyverse_store_id' => $loyverseId])->save();
            $report['created'][] = $name;
        }

        $report['localOnly'] = Store::query()
            ->whereNull('loyverse_store_id')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return $report;
    }

    /** A slug id that cannot collide with a branch that already exists */
    private function freshId(string $name): string
    {
        $base = Str::slug($name) ?: 'store';
        $id = $base;
        for ($n = 2; Store::query()->whereKey($id)->exists(); $n++) {
            $id = "{$base}-{$n}";
        }

        return $id;
    }
}
