<?php

namespace Tests\Feature\Api;

use App\Models\Deposit;
use App\Models\DepositDay;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\User;
use App\Support\AuditLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * The endpoints the status card and the audit pages run on: audits, pending
 * deposits, expenses, categories, sign-ins, and the reconciliation window.
 */
class AuditSpineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twowheelszone')->firstOrFail();
    }

    private function sale(string $number, string $day, float $gross, float $cost = 0): void
    {
        Receipt::query()->create([
            'receipt_number' => $number,
            'store_id' => 'arevalo',
            'type' => 'SALE',
            'day' => $day,
            'hour' => 10,
            'receipt_date' => "{$day} 02:00:00",
            'gross' => $gross,
            'cost' => $cost,
            'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
    }

    public function test_an_audit_day_composes_sales_expenses_and_status(): void
    {
        $this->sale('r-1', '2026-08-01', 5000.0);
        Expense::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Meals',
            'note' => 'lunch', 'amount' => 300.0, 'at' => now(),
        ]);

        $this->actingAs($this->manager())
            ->getJson('/api/audits?storeIds=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertOk()
            ->assertExactJson([[
                'storeId' => 'arevalo',
                'day' => '2026-08-01',
                'gross' => 5000.0,
                'profit' => 5000.0,
                'expenses' => 300.0,
                'advances' => 0.0,
                // profit - expenses - advances, the house rule (cost is zero here)
                'expected' => 4700.0,
                'deposited' => null,
                'online' => null,
                'depositCovers' => null,
                'depositExpected' => null,
                'reference' => null,
                'slipUrl' => null,
                'status' => 'pending',
            ]]);
    }

    public function test_today_is_open_not_pending(): void
    {
        $today = CarbonImmutable::now('Asia/Manila')->format('Y-m-d');
        $this->sale('r-1', $today, 1000.0);

        $this->actingAs($this->manager())
            ->getJson("/api/audits?storeIds=arevalo&from={$today}&to={$today}")
            ->assertJsonPath('0.status', 'open');
    }

    public function test_a_covered_day_reads_matched_with_its_deposit(): void
    {
        $this->sale('r-1', '2026-08-01', 5000.0);
        $deposit = Deposit::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-02', 'amount' => 5000.0,
            'reference' => 'BDO-123', 'slip_path' => 'receipts/slips/x.jpg', 'matched' => true,
        ]);
        DepositDay::query()->create(['deposit_id' => $deposit->id, 'store_id' => 'arevalo', 'day' => '2026-08-01']);

        $this->actingAs($this->manager())
            ->getJson('/api/audits?storeIds=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertJsonPath('0.status', 'matched')
            ->assertJsonPath('0.deposited', 5000)
            ->assertJsonPath('0.reference', 'BDO-123');
    }

    public function test_days_before_the_ledger_start_are_settled_history(): void
    {
        Setting::write(AuditLedger::START_KEY, '2026-08-02');
        $this->sale('r-1', '2026-08-01', 9999.0); // deposited in the world before the app
        $this->sale('r-2', '2026-08-02', 1000.0);

        // No audit row, and no place in anybody's backlog
        $this->actingAs($this->manager())
            ->getJson('/api/audits?storeIds=arevalo&from=2026-08-01&to=2026-08-02')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.day', '2026-08-02');

        $pending = $this->actingAs($this->manager())
            ->getJson('/api/deposits/pending?storeId=arevalo')
            ->assertOk();
        $this->assertSame(['2026-08-02'], array_column($pending->json(), 'day'));

        // The sales charts still reach the settled history
        $this->actingAs($this->manager())
            ->getJson('/api/sales/daily?storeIds=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertJsonPath('0.gross', 9999);
    }

    public function test_pending_lists_uncovered_past_days_oldest_first(): void
    {
        $today = CarbonImmutable::now('Asia/Manila')->format('Y-m-d');
        $this->sale('r-1', '2026-08-02', 2000.0);
        $this->sale('r-2', '2026-08-01', 1000.0);
        $this->sale('r-3', $today, 900.0); // today is open, not pending

        $response = $this->actingAs($this->manager())
            ->getJson('/api/deposits/pending?storeId=arevalo')
            ->assertOk();

        $days = array_column($response->json(), 'day');
        $this->assertSame(['2026-08-01', '2026-08-02'], $days);
    }

    public function test_the_manager_cannot_read_another_branch(): void
    {
        foreach ([
            '/api/audits?storeIds=molo&from=2026-08-01&to=2026-08-01',
            '/api/deposits/pending?storeId=molo',
            '/api/expenses?storeId=molo&from=2026-08-01&to=2026-08-01',
            '/api/deposits?storeId=molo&from=2026-08-01&to=2026-08-01',
        ] as $url) {
            $this->actingAs($this->manager())->getJson($url)->assertStatus(403);
        }
    }

    public function test_expenses_round_trip_with_photos(): void
    {
        Storage::fake('local');

        $created = $this->actingAs($this->manager())->post('/api/expenses', [
            'payload' => json_encode(['items' => [
                ['storeId' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Wifi bill', 'note' => 'PLDT', 'amount' => 1699.0],
            ]]),
            'receipts' => [0 => [UploadedFile::fake()->image('r1.jpg'), UploadedFile::fake()->image('r2.jpg')]],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertCount(2, $created->json()[0]['receiptUrls']);
        $this->assertSame(1699.0, (float) $created->json()[0]['amount']);

        // The photo is served from the receipts tree, and only from there
        $url = $created->json()[0]['receiptUrls'][0];
        $this->actingAs($this->manager())->get($url)->assertOk();
        $this->actingAs($this->manager())->get('/api/files/secrets/anything')->assertNotFound();

        // Listed back
        $this->actingAs($this->manager())
            ->getJson('/api/expenses?storeId=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertJsonCount(1)
            ->assertJsonPath('0.category', 'Wifi bill');
    }

    public function test_a_multipart_update_travels_as_post_with_method_patch(): void
    {
        Storage::fake('local');
        $expense = Expense::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Meals',
            'note' => 'lunch', 'amount' => 300.0, 'at' => now(),
        ]);

        $this->actingAs($this->manager())->post("/api/expenses/{$expense->id}", [
            '_method' => 'PATCH',
            'payload' => json_encode(['amount' => 350.0, 'keepReceipts' => []]),
            'receipts' => [UploadedFile::fake()->image('new.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('amount', 350)
            ->assertJsonCount(1, 'receiptUrls');
    }

    public function test_a_deposited_day_refuses_expense_writes(): void
    {
        $deposit = Deposit::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-02', 'amount' => 1.0,
            'reference' => 'x', 'slip_path' => 'receipts/slips/x.jpg', 'matched' => true,
        ]);
        DepositDay::query()->create(['deposit_id' => $deposit->id, 'store_id' => 'arevalo', 'day' => '2026-08-01']);

        $this->actingAs($this->manager())->post('/api/expenses', [
            'payload' => json_encode(['items' => [
                ['storeId' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Meals', 'note' => 'x', 'amount' => 10.0],
            ]]),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', '2026-08-01 is already covered by a deposit, so its figures are final.');
    }

    public function test_a_dead_category_is_refused_in_words(): void
    {
        $this->actingAs($this->manager())->post('/api/expenses', [
            'payload' => json_encode(['items' => [
                ['storeId' => 'arevalo', 'day' => '2026-08-01', 'category' => 'Ghost', 'note' => 'x', 'amount' => 10.0],
            ]]),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ghost is not a category anymore.');
    }

    public function test_categories_list_and_owner_only_replace(): void
    {
        $this->actingAs($this->manager())
            ->getJson('/api/expense-categories')
            ->assertOk()
            ->assertJsonPath('0.name', 'Meals')
            ->assertJsonPath('0.receiptExempt', true);

        $next = [
            ['id' => 'meals', 'name' => 'Meals', 'receiptExempt' => true],
            ['id' => 'parts', 'name' => 'Spare parts', 'receiptExempt' => false],
        ];

        $this->actingAs($this->manager())
            ->putJson('/api/expense-categories', $next)
            ->assertStatus(403);

        $this->actingAs($this->owner())
            ->putJson('/api/expense-categories', $next)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('1.name', 'Spare parts');
    }

    public function test_a_sign_in_is_recorded_and_this_device_is_a_fact(): void
    {
        $response = $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ], ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/126 Mobile Safari'])->assertOk();

        /* A browser carries the session cookie back; the test harness does
           not, so it is carried by hand — `current` compares session ids */
        $cookieName = config('session.cookie');
        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $cookieName);

        $id = $this->manager()->id;
        /* get(), not getJson(): the harness only attaches cookies on
           non-json request builders */
        $this->withUnencryptedCookie($cookieName, $sessionCookie->getValue())
            ->get("/api/accounts/{$id}/sign-ins", ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('0.device', 'Chrome on Android')
            ->assertJsonPath('0.kind', 'phone')
            ->assertJsonPath('0.current', true);

        // Another manager's log is not theirs to read; the owner reads anyone's
        $joel = User::query()->where('username', 'joel.sarabia')->firstOrFail();
        $this->getJson("/api/accounts/{$joel->id}/sign-ins")->assertStatus(403);
        $this->actingAs($this->owner())->getJson("/api/accounts/{$id}/sign-ins")->assertOk();
    }

    public function test_the_reconciliation_window_reads_for_both_and_writes_for_one(): void
    {
        $this->actingAs($this->manager())
            ->getJson('/api/settings/reconciliation')
            ->assertOk()
            ->assertExactJson(['batchWindowDays' => 3]);

        $this->actingAs($this->manager())
            ->patchJson('/api/settings/reconciliation', ['batchWindowDays' => 5])
            ->assertStatus(403);

        $this->actingAs($this->owner())
            ->patchJson('/api/settings/reconciliation', ['batchWindowDays' => 5])
            ->assertNoContent();

        $this->actingAs($this->manager())
            ->getJson('/api/settings/reconciliation')
            ->assertExactJson(['batchWindowDays' => 5]);
    }
}
