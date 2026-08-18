<?php

namespace Tests\Feature\Api;

use App\Models\Deposit;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Recording a deposit. The expected figure follows the house rule — net sales
 * minus expenses — and a mismatch can never be recorded without its reason.
 */
class DepositRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');

        // 2026-08-01, arevalo: takings 5000, cost 2000, spend 300
        Receipt::query()->create([
            'receipt_number' => 'r-1', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-01', 'hour' => 10, 'receipt_date' => '2026-08-01 02:00:00',
            'gross' => 5000.0, 'cost' => 2000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
        Expense::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Meals',
            'note' => 'lunch', 'amount' => 300.0, 'at' => now(),
        ]);
        // expected = 5000 - 300 = 4700
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = [], ?UploadedFile $slip = null): TestResponse
    {
        $payload = [
            'storeId' => 'arevalo',
            'day' => '2026-08-02',
            'amount' => 4700.0,
            'reference' => 'BDO-4417',
            'covers' => ['2026-08-01'],
            ...$overrides,
        ];

        return $this->actingAs($this->manager())->post('/api/deposits', [
            'payload' => json_encode($payload),
            'slip' => [$slip ?? UploadedFile::fake()->image('slip.jpg')],
        ], ['Accept' => 'application/json']);
    }

    public function test_a_matching_amount_records_and_seals_the_day(): void
    {
        $this->record()
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('amount', 4700)
            ->assertJsonPath('covers.0', '2026-08-01');

        // The day left the backlog and its audit row reads matched
        $this->actingAs($this->manager())
            ->getJson('/api/deposits/pending?storeId=arevalo')
            ->assertExactJson([]);
        $this->actingAs($this->manager())
            ->getJson('/api/audits?storeIds=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertJsonPath('0.status', 'matched')
            ->assertJsonPath('0.deposited', 4700);
    }

    public function test_a_mismatch_without_a_reason_is_refused(): void
    {
        $this->record(['amount' => 4500.0])
            ->assertStatus(422)
            ->assertJsonStructure(['fields' => ['reason']]);

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_a_mismatch_with_its_reason_records_as_a_discrepancy(): void
    {
        $this->record([
            'amount' => 4500.0,
            'discrepancyReason' => 'Paid the parts courier in cash, receipt attached.',
        ])
            ->assertOk()
            ->assertJsonPath('matched', false);

        $deposit = Deposit::query()->sole();
        $this->assertFalse($deposit->matched);
        $this->assertSame('Paid the parts courier in cash, receipt attached.', $deposit->discrepancy_reason);
    }

    public function test_a_deposit_cannot_cover_more_days_than_the_batching_window(): void
    {
        // A second waiting day: takings 3000, cost 1000 — expected 2000
        Receipt::query()->create([
            'receipt_number' => 'r-2', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-02', 'hour' => 11, 'receipt_date' => '2026-08-02 03:00:00',
            'gross' => 3000.0, 'cost' => 1000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);

        Setting::write('batch_window_days', '1');
        $this->record([
            'day' => '2026-08-03',
            'amount' => 7700.0,
            'covers' => ['2026-08-01', '2026-08-02'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'One deposit may cover at most 1 day.')
            ->assertJsonStructure(['fields' => ['days']]);
        $this->assertSame(0, Deposit::query()->count());

        // Widened, the same two-day deposit goes through and matches at the
        // combined expected amount for both covered days.
        Setting::write('batch_window_days', '3');
        $this->record([
            'day' => '2026-08-03',
            'amount' => 7700.0,
            'covers' => ['2026-08-01', '2026-08-02'],
        ])
            ->assertOk()
            ->assertJsonPath('matched', true);
    }

    public function test_online_payments_count_toward_the_match(): void
    {
        // 2200 cash on the slip plus 500 that came in through GCash answers
        // the 4700 expected — an online sale is not a shortfall
        $this->record(['amount' => 2200.0, 'online' => 2500.0])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('amount', 2200)
            ->assertJsonPath('online', 2500);

        $this->assertTrue(Deposit::query()->sole()->matched);
    }

    public function test_cash_plus_online_short_of_expected_still_needs_its_reason(): void
    {
        // 2200 + 2000 = 4200 against 4700 — declaring online money does not
        // waive the explanation for what is still missing
        $this->record(['amount' => 2200.0, 'online' => 2000.0])
            ->assertStatus(422)
            ->assertJsonStructure(['fields' => ['reason']]);

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_the_same_slip_photo_cannot_cover_two_deposits(): void
    {
        $slip = UploadedFile::fake()->image('slip.jpg');
        $this->record([], $slip)->assertOk();

        // A second day to deposit, but the same photo
        Receipt::query()->create([
            'receipt_number' => 'r-2', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-02', 'hour' => 10, 'receipt_date' => '2026-08-02 02:00:00',
            'gross' => 1000.0, 'cost' => 0.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
        $again = UploadedFile::fake()->createWithContent('slip2.jpg', $slip->getContent());

        $this->record(['covers' => ['2026-08-02'], 'amount' => 1000.0], $again)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This slip photo already covers a deposit.');
    }

    public function test_a_day_not_waiting_cannot_be_covered(): void
    {
        $this->record(['covers' => ['2026-07-15']])
            ->assertStatus(422)
            ->assertJsonPath('message', '2026-07-15 is not waiting for a deposit. It is still open, or already covered.');
    }

    public function test_a_manager_cannot_deposit_for_another_branch(): void
    {
        $this->record(['storeId' => 'molo'])->assertStatus(403);
    }

    public function test_the_slip_photo_is_required_in_words(): void
    {
        $this->actingAs($this->manager())->post('/api/deposits', [
            'payload' => json_encode([
                'storeId' => 'arevalo', 'day' => '2026-08-02', 'amount' => 4700.0,
                'reference' => 'BDO-4417', 'covers' => ['2026-08-01'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('fields.slip', 'Attach the deposit slip photo.');
    }
}
