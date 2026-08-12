<?php

namespace Tests\Feature\Api;

use App\Models\Receipt;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The sales filter: which items never count toward gross and profit, and
 * the catalog search that picks them. Owner-only; a changed list clears the
 * stored receipts so the next sync recomputes history with the new filter.
 */
class SalesFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['loyverse.token' => 'tok-test']);
    }

    private function owner(): User
    {
        return User::query()->where('role', 'owner')->firstOrFail();
    }

    public function test_the_seeded_list_reads_back_with_names(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/settings/sales-filter')
            ->assertOk()
            ->assertJsonCount(24)
            ->assertJsonPath('0.sku', '11898')
            ->assertJsonPath('0.name', 'BATTERY CHARGE');
    }

    public function test_only_the_owner_may_touch_the_filter(): void
    {
        $manager = User::query()->where('username', 'marvin.deocampo')->firstOrFail();

        $this->actingAs($manager)->getJson('/api/settings/sales-filter')->assertStatus(403);
        $this->actingAs($manager)
            ->putJson('/api/settings/sales-filter', ['items' => []])
            ->assertStatus(403);
        $this->actingAs($manager)
            ->getJson('/api/catalog/search?q=labor')
            ->assertStatus(403);
    }

    public function test_changing_the_list_clears_receipts_for_the_re_pull(): void
    {
        Receipt::query()->create([
            'receipt_number' => 'r-1', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-01', 'hour' => 10, 'receipt_date' => '2026-08-01 02:00:00',
            'gross' => 5000.0, 'cost' => 2000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
        Setting::write('loyverse_receipts_watermark', '2026-08-10T00:00:00Z');

        $this->actingAs($this->owner())
            ->putJson('/api/settings/sales-filter', [
                'items' => [['sku' => '19143', 'name' => 'FWZ LABOR']],
            ])
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.sku', '19143');

        // The aggregates the old filter shaped are gone; the sync will rebuild
        $this->assertSame(0, Receipt::query()->count());
        $this->assertNull(Setting::read('loyverse_receipts_watermark'));
    }

    public function test_saving_the_same_skus_keeps_the_receipts(): void
    {
        Receipt::query()->create([
            'receipt_number' => 'r-1', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-01', 'hour' => 10, 'receipt_date' => '2026-08-01 02:00:00',
            'gross' => 5000.0, 'cost' => 2000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);

        $current = $this->actingAs($this->owner())
            ->getJson('/api/settings/sales-filter')
            ->json();

        $this->actingAs($this->owner())
            ->putJson('/api/settings/sales-filter', ['items' => $current])
            ->assertOk();

        $this->assertSame(1, Receipt::query()->count());
    }

    public function test_the_search_says_so_while_the_catalog_is_unwalked(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/catalog/search?q=labor')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The catalog is still being fetched from the POS. Try again in a minute.');
    }

    public function test_the_catalog_search_matches_names_and_skus(): void
    {
        Http::fake([
            'api.loyverse.com/v1.0/items*' => Http::response(['items' => [
                [
                    'item_name' => 'FWZ LABOR',
                    'variants' => [['sku' => '19143']],
                ],
                [
                    'item_name' => 'BRAKE PAD NMAX',
                    'variants' => [['sku' => '20077']],
                ],
                [
                    'item_name' => 'ENGINE OIL',
                    'variants' => [
                        ['sku' => '30001', 'option1_value' => '1L'],
                        ['sku' => '30002', 'option1_value' => '4L'],
                    ],
                ],
            ]]),
        ]);
        // The scheduler's job, done here by hand: walk the catalog into cache
        $this->artisan('twz:sync-catalog')->assertSuccessful();

        $this->actingAs($this->owner())
            ->getJson('/api/catalog/search?q=labor')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.sku', '19143');

        // By SKU, and a variant carries its option in the name
        $this->actingAs($this->owner())
            ->getJson('/api/catalog/search?q=30002')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'ENGINE OIL — 4L');

        // One walked copy serves every search — a single upstream request
        Http::assertSentCount(1);
    }
}
