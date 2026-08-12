<?php

namespace Tests\Feature\Api;

use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\Loyverse\ReceiptSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['loyverse.token' => 'tok-test']);
        Store::query()->find('arevalo')->forceFill(['loyverse_store_id' => 'lv-arevalo'])->save();
        Store::query()->find('molo')->forceFill(['loyverse_store_id' => 'lv-molo'])->save();
    }

    /** @param list<array<string, mixed>> $receipts */
    private function loyverseAnswers(array $receipts): void
    {
        Http::fake(['api.loyverse.com/v1.0/receipts*' => Http::response(['receipts' => $receipts])]);
    }

    /** @param array<string, mixed> $overrides */
    private function receipt(array $overrides): array
    {
        return [
            'receipt_number' => '1-0001',
            'receipt_type' => 'SALE',
            'store_id' => 'lv-arevalo',
            'receipt_date' => '2026-08-05T03:00:00.000Z',
            'updated_at' => '2026-08-05T03:00:00.000Z',
            'cancelled_at' => null,
            'total_money' => 1000.0,
            'line_items' => [['quantity' => 2, 'cost' => 300.0, 'cost_total' => 600.0]],
            ...$overrides,
        ];
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    public function test_service_and_labor_lines_stay_out_of_the_sales_figures(): void
    {
        // ₱1,500 receipt: ₱1,000 of parts and a ₱500 labor charge. Labor is
        // not the drawer's money — gross must read 1000, profit 400.
        Setting::write('sales_excluded_skus', '19143,11083');
        $this->loyverseAnswers([
            $this->receipt([
                'total_money' => 1500.0,
                'line_items' => [
                    ['sku' => '20001', 'quantity' => 2, 'cost' => 300.0, 'cost_total' => 600.0, 'total_money' => 1000.0],
                    ['sku' => '19143', 'quantity' => 1, 'cost' => 0.0, 'cost_total' => 0.0, 'total_money' => 500.0],
                ],
            ]),
        ]);
        app(ReceiptSync::class)->run(10);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertOk()
            ->assertJsonPath('0.gross', 1000)
            ->assertJsonPath('0.profit', 400);
    }

    public function test_a_sale_lands_on_the_branch_local_day_with_gross_and_profit(): void
    {
        /* 17:30 UTC is 01:30 the NEXT day in Manila — the audit day must be
           the branch's, or every late-evening sale lands a day early */
        $this->loyverseAnswers([
            $this->receipt([
                'receipt_number' => '1-0001',
                'receipt_date' => '2026-08-04T17:30:00.000Z',
                'updated_at' => '2026-08-04T17:31:00.000Z',
            ]),
        ]);
        app(ReceiptSync::class)->run(10);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertOk()
            ->assertExactJson([[
                'storeId' => 'arevalo',
                'day' => '2026-08-05',
                'gross' => 1000.0,
                'profit' => 400.0,
                'expenses' => 0.0,
                // House rule: expected follows profit, never the takings
                'expected' => 400.0,
            ]]);

        // And nothing on the UTC day
        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-04&to=2026-08-04')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_a_refund_subtracts_on_the_day_the_money_left(): void
    {
        $this->loyverseAnswers([
            $this->receipt(['receipt_number' => '1-0001', 'total_money' => 1000.0]),
            $this->receipt([
                'receipt_number' => '1-0002',
                'receipt_type' => 'REFUND',
                'total_money' => 250.0,
                'line_items' => [['quantity' => 1, 'cost' => 150.0, 'cost_total' => 150.0]],
            ]),
        ]);
        app(ReceiptSync::class)->run(10);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertOk()
            // 1000 - 250 taken; profit (1000-600) - (250-150) = 300
            ->assertJsonPath('0.gross', 750)
            ->assertJsonPath('0.profit', 300);
    }

    public function test_a_cancelled_receipt_never_counts(): void
    {
        $this->loyverseAnswers([
            $this->receipt(['receipt_number' => '1-0001']),
            $this->receipt([
                'receipt_number' => '1-0002',
                'total_money' => 5000.0,
                'cancelled_at' => '2026-08-05T04:00:00.000Z',
            ]),
        ]);
        app(ReceiptSync::class)->run(10);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertJsonPath('0.gross', 1000);
    }

    public function test_repeated_bare_storeids_keys_all_arrive(): void
    {
        // The contract's ?storeIds=a&storeIds=b — PHP alone would keep only b
        $this->loyverseAnswers([
            $this->receipt(['receipt_number' => '1-0001', 'store_id' => 'lv-arevalo']),
            $this->receipt(['receipt_number' => '2-0001', 'store_id' => 'lv-molo', 'total_money' => 700.0]),
        ]);
        app(ReceiptSync::class)->run(10);

        $owner = User::query()->where('username', 'twowheelszone')->firstOrFail();
        $response = $this->actingAs($owner)
            ->getJson('/api/sales/daily?storeIds=arevalo&storeIds=molo&from=2026-08-05&to=2026-08-05')
            ->assertOk();

        $this->assertCount(2, $response->json());
    }

    public function test_a_manager_reads_their_branch_and_nothing_else(): void
    {
        $this->loyverseAnswers([]);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=molo&from=2026-08-05&to=2026-08-05')
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to that.');

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&storeIds=molo&from=2026-08-05&to=2026-08-05')
            ->assertStatus(403);
    }

    public function test_hourly_sums_profit_by_branch_local_hour(): void
    {
        $cost = fn (float $value) => [['quantity' => 1, 'cost' => $value, 'cost_total' => $value]];
        $this->loyverseAnswers([
            // 01:15 UTC = 09:15 Manila
            $this->receipt(['receipt_number' => '1-0001', 'receipt_date' => '2026-08-05T01:15:00.000Z', 'total_money' => 300.0, 'line_items' => $cost(100.0)]),
            $this->receipt(['receipt_number' => '1-0002', 'receipt_date' => '2026-08-05T01:45:00.000Z', 'total_money' => 200.0, 'line_items' => $cost(100.0)]),
            // 06:00 UTC = 14:00 Manila
            $this->receipt(['receipt_number' => '1-0003', 'receipt_date' => '2026-08-05T06:00:00.000Z', 'total_money' => 450.0, 'line_items' => $cost(150.0)]),
        ]);
        app(ReceiptSync::class)->run(10);

        // Profit per hour, not takings: (300-100)+(200-100), then 450-150
        $this->actingAs($this->manager())
            ->getJson('/api/sales/hourly?storeIds=arevalo&day=2026-08-05')
            ->assertOk()
            ->assertExactJson([
                ['hour' => 9, 'amount' => 300.0],
                ['hour' => 14, 'amount' => 300.0],
            ]);
    }

    public function test_a_correction_overwrites_its_old_self(): void
    {
        // The same receipt arrives twice: sold, then cancelled — the whole
        // point of polling on updated_at
        Http::fake([
            'api.loyverse.com/v1.0/receipts*' => Http::sequence()
                ->push(['receipts' => [$this->receipt(['receipt_number' => '1-0001'])]])
                ->push(['receipts' => [$this->receipt([
                    'receipt_number' => '1-0001',
                    'cancelled_at' => '2026-08-05T05:00:00.000Z',
                    'updated_at' => '2026-08-05T05:00:00.000Z',
                ])]]),
        ]);
        app(ReceiptSync::class)->run(10);
        app(ReceiptSync::class)->run(10);

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertExactJson([]);
    }

    public function test_the_watermark_only_advances_when_the_walk_finishes(): void
    {
        Http::fake([
            'api.loyverse.com/v1.0/receipts*' => Http::sequence()
                ->push(['receipts' => [$this->receipt(['receipt_number' => '1-0001'])], 'cursor' => 'more'])
                ->push(['receipts' => [$this->receipt(['receipt_number' => '1-0002'])], 'cursor' => null]),
        ]);

        // Capped at one page: stopped mid-walk, the mark must not move
        $capped = app(ReceiptSync::class)->run(1);
        $this->assertFalse($capped['done']);
        $this->assertNull(Setting::read('loyverse_receipts_watermark'));

        // The next run reaches the end and the mark lands
        $finished = app(ReceiptSync::class)->run(10);
        $this->assertTrue($finished['done']);
        $this->assertNotNull(Setting::read('loyverse_receipts_watermark'));
    }

    public function test_loyverse_being_down_serves_the_local_copy(): void
    {
        // First pull succeeds; then Loyverse starts failing and the inline
        // refresh must degrade to the local copy, never to a 500 of our own
        Http::fake([
            'api.loyverse.com/v1.0/receipts*' => Http::sequence()
                ->push(['receipts' => [$this->receipt(['receipt_number' => '1-0001'])]])
                ->pushStatus(500),
        ]);
        app(ReceiptSync::class)->run(10);
        cache()->forget('loyverse:receipts-synced-at'); // force the inline path to try

        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-05&to=2026-08-05')
            ->assertOk()
            ->assertJsonPath('0.gross', 1000);
    }
}
