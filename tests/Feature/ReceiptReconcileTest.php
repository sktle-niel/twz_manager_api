<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Store;
use App\Services\Loyverse\ReceiptSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
 * The nightly reconcile: `twz:sync-sales --days=7` re-pulls the trailing week
 * wholesale and upserts it, but NEVER writes the watermark — its high-water
 * mark can sit behind the incremental run's, and writing it would regress
 * the mark and re-walk history on the next tick.
 */
class ReceiptReconcileTest extends TestCase
{
    use RefreshDatabase;

    private const WATERMARK = 'loyverse_receipts_watermark';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
        config(['loyverse.token' => 'tok-test']);
        Store::query()->whereKey('arevalo')->update(['loyverse_store_id' => 'lv-arevalo']);
    }

    public function test_a_reconcile_upserts_but_never_moves_the_watermark(): void
    {
        $mark = CarbonImmutable::now('UTC')->subHour()->toIso8601ZuluString();
        Setting::write(self::WATERMARK, $mark);

        $edited = CarbonImmutable::now('UTC')->subDays(3);
        Http::fake([
            'api.loyverse.com/*' => Http::response([
                'receipts' => [[
                    'receipt_number' => 'lv-r-77',
                    'store_id' => 'lv-arevalo',
                    'receipt_type' => 'SALE',
                    'receipt_date' => $edited->toIso8601ZuluString(),
                    'updated_at' => $edited->toIso8601ZuluString(),
                    'total_money' => 1234.5,
                    'line_items' => [['cost_total' => 400.0, 'quantity' => 1]],
                ]],
                'cursor' => null,
            ]),
        ]);

        $report = app(ReceiptSync::class)->run(10, 7);

        $this->assertTrue($report['done']);
        $this->assertSame(1, $report['upserted']);
        $this->assertDatabaseHas('receipts', ['receipt_number' => 'lv-r-77', 'store_id' => 'arevalo']);
        // The mark still reads exactly what the incremental run left there
        $this->assertSame($mark, Setting::read(self::WATERMARK));

        // And the walk asked for the trailing week, not the watermark
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked = CarbonImmutable::parse($query['updated_at_min']);

            return $asked->lessThan(CarbonImmutable::now('UTC')->subDays(6));
        });
    }

    public function test_an_incremental_run_still_advances_the_watermark(): void
    {
        $mark = CarbonImmutable::now('UTC')->subHour();
        Setting::write(self::WATERMARK, $mark->toIso8601ZuluString());

        $fresh = CarbonImmutable::now('UTC')->subMinutes(5);
        Http::fake([
            'api.loyverse.com/*' => Http::response([
                'receipts' => [[
                    'receipt_number' => 'lv-r-78',
                    'store_id' => 'lv-arevalo',
                    'receipt_type' => 'SALE',
                    'receipt_date' => $fresh->toIso8601ZuluString(),
                    'updated_at' => $fresh->toIso8601ZuluString(),
                    'total_money' => 500.0,
                    'line_items' => [],
                ]],
                'cursor' => null,
            ]),
        ]);

        app(ReceiptSync::class)->run(10);

        $this->assertSame(
            $fresh->toIso8601ZuluString(),
            Setting::read(self::WATERMARK),
        );
    }
}
