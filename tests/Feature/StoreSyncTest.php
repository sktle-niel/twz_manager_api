<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Services\Loyverse\StoreSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['loyverse.token' => 'tok-test']);
    }

    /** @param list<array{id: string, name: string}> $stores */
    private function loyverseAnswers(array $stores): void
    {
        Http::fake(['api.loyverse.com/v1.0/stores*' => Http::response(['stores' => $stores])]);
    }

    public function test_matching_names_link_without_touching_our_ids(): void
    {
        // "La Paz" must find the branch seeded as `lapaz` — slugs, not ids
        $this->loyverseAnswers([
            ['id' => 'lv-001', 'name' => 'Arevalo'],
            ['id' => 'lv-002', 'name' => 'La Paz'],
        ]);

        $report = app(StoreSync::class)->run();

        $this->assertCount(2, $report['linked']);
        $this->assertSame('lv-001', Store::query()->find('arevalo')->loyverse_store_id);
        $this->assertSame('lv-002', Store::query()->find('lapaz')->loyverse_store_id);
        // The unmatched seeded branches are reported, never deleted
        $this->assertSame(['Jaro', 'Molo'], $report['localOnly']);
    }

    public function test_an_unknown_loyverse_store_becomes_a_new_branch(): void
    {
        $this->loyverseAnswers([['id' => 'lv-009', 'name' => 'Villa Anita']]);

        $report = app(StoreSync::class)->run();

        $this->assertSame(['Villa Anita'], $report['created']);
        $created = Store::query()->where('loyverse_store_id', 'lv-009')->sole();
        $this->assertSame('villa-anita', $created->id);
        $this->assertSame('Villa Anita', $created->name);
    }

    public function test_running_twice_changes_nothing(): void
    {
        $this->loyverseAnswers([
            ['id' => 'lv-001', 'name' => 'Arevalo'],
            ['id' => 'lv-009', 'name' => 'Villa Anita'],
        ]);

        app(StoreSync::class)->run();
        $second = app(StoreSync::class)->run();

        $this->assertSame([], $second['linked']);
        $this->assertSame([], $second['created']);
        $this->assertCount(2, $second['already']);
        $this->assertSame(5, Store::query()->count());
    }

    public function test_a_deleted_loyverse_store_is_ignored(): void
    {
        $this->loyverseAnswers([
            ['id' => 'lv-001', 'name' => 'Arevalo', 'deleted_at' => '2026-01-01T00:00:00Z'],
        ]);

        $report = app(StoreSync::class)->run();

        $this->assertSame([], $report['linked']);
        $this->assertNull(Store::query()->find('arevalo')->loyverse_store_id);
    }

    public function test_a_colliding_name_gets_a_fresh_id(): void
    {
        // Arevalo is linked already; a second, distinct Loyverse store with
        // the same name is a different branch and needs its own id
        Store::query()->find('arevalo')->forceFill(['loyverse_store_id' => 'lv-001'])->save();
        $this->loyverseAnswers([
            ['id' => 'lv-001', 'name' => 'Arevalo'],
            ['id' => 'lv-777', 'name' => 'Arevalo'],
        ]);

        $report = app(StoreSync::class)->run();

        $this->assertSame(['Arevalo'], $report['created']);
        $this->assertSame('arevalo-2', Store::query()->where('loyverse_store_id', 'lv-777')->sole()->id);
    }

    public function test_the_command_reports_in_words(): void
    {
        $this->loyverseAnswers([['id' => 'lv-001', 'name' => 'Arevalo']]);

        $this->artisan('twz:sync-stores')
            ->expectsOutputToContain('linked   Arevalo <- Arevalo')
            ->expectsOutputToContain('has no Loyverse store')
            ->assertSuccessful();
    }

    public function test_a_missing_token_fails_in_words_not_a_stack_trace(): void
    {
        config(['loyverse.token' => '']);

        $this->artisan('twz:sync-stores')
            ->expectsOutputToContain('No Loyverse token is configured.')
            ->assertFailed();
    }
}
