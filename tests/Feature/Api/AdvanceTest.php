<?php

namespace Tests\Feature\Api;

use App\Models\Advance;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * Cash advances: drawer money an employee drew against their pay. They net
 * out of the day's expected deposit like spend, live apart from expenses,
 * and freeze with the day once a deposit covers it.
 */
class AdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 2026-08-01, arevalo: net sales 5000, cost 2000 — profit 3000
        Receipt::query()->create([
            'receipt_number' => 'r-1', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => '2026-08-01', 'hour' => 10, 'receipt_date' => '2026-08-01 02:00:00',
            'gross' => 5000.0, 'cost' => 2000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    private function draw(float $amount = 500.0): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/advances', [
                'storeId' => 'arevalo',
                'day' => '2026-08-01',
                'employee' => 'Juan Dela Cruz',
                'amount' => $amount,
            ])
            ->assertOk()
            ->assertJsonPath('employee', 'Juan Dela Cruz');
    }

    public function test_an_advance_nets_out_of_the_expected_deposit(): void
    {
        $this->draw(500.0);

        // expected = 5000 net sales - 0 expenses - 500 advanced
        $this->actingAs($this->manager())
            ->getJson('/api/audits?storeIds=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertJsonPath('0.advances', 500)
            ->assertJsonPath('0.expected', 4500);
    }

    public function test_a_deposit_of_the_netted_amount_matches(): void
    {
        Storage::fake('local');
        $this->draw(500.0);

        $this->actingAs($this->manager())->post('/api/deposits', [
            'payload' => json_encode([
                'storeId' => 'arevalo',
                'day' => '2026-08-02',
                'amount' => 4500.0,
                'reference' => 'BDO-9021',
                'covers' => ['2026-08-01'],
            ]),
            'slip' => [UploadedFile::fake()->image('slip.jpg')],
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('matched', true);
    }

    public function test_a_covered_day_refuses_new_and_edited_advances(): void
    {
        Storage::fake('local');
        $this->draw(500.0);
        $advance = Advance::query()->sole();

        $this->actingAs($this->manager())->post('/api/deposits', [
            'payload' => json_encode([
                'storeId' => 'arevalo', 'day' => '2026-08-02', 'amount' => 4500.0,
                'reference' => 'BDO-9021', 'covers' => ['2026-08-01'],
            ]),
            'slip' => [UploadedFile::fake()->image('slip.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        // The day is reconciled now: nothing may move its figures
        $this->actingAs($this->manager())
            ->postJson('/api/advances', [
                'storeId' => 'arevalo', 'day' => '2026-08-01',
                'employee' => 'Maria', 'amount' => 200.0,
            ])
            ->assertStatus(422);
        $this->actingAs($this->manager())
            ->patchJson("/api/advances/{$advance->id}", ['amount' => 900.0])
            ->assertStatus(422);
        $this->actingAs($this->manager())
            ->deleteJson("/api/advances/{$advance->id}")
            ->assertStatus(422);
    }

    public function test_a_manager_cannot_reach_another_branches_advances(): void
    {
        $this->draw(500.0);
        $advance = Advance::query()->sole();
        $other = User::query()->where('username', 'joel.sarabia')->firstOrFail();

        $this->actingAs($other)
            ->postJson('/api/advances', [
                'storeId' => 'arevalo', 'day' => '2026-08-01',
                'employee' => 'Pedro', 'amount' => 100.0,
            ])
            ->assertStatus(403);
        $this->actingAs($other)
            ->deleteJson("/api/advances/{$advance->id}")
            ->assertStatus(403);
        $this->actingAs($other)
            ->getJson('/api/advances?storeId=arevalo&from=2026-08-01&to=2026-08-01')
            ->assertStatus(403);
    }
}
