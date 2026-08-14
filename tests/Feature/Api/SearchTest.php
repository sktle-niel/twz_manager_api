<?php

namespace Tests\Feature\Api;

use App\Models\Deposit;
use App\Models\DepositDay;
use App\Models\Expense;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * The one search door — GET /api/search. Tokens must all land somewhere in a
 * record's haystack; a recognised date narrows by the record's own day; the
 * branch scope is the caller's request, never their authority.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private string $today;

    private string $yesterday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $now = CarbonImmutable::now('Asia/Manila');
        $this->today = $now->format('Y-m-d');
        $this->yesterday = $now->subDay()->format('Y-m-d');

        Receipt::query()->create([
            'receipt_number' => 'r-1', 'store_id' => 'arevalo', 'type' => 'SALE',
            'day' => $this->yesterday, 'hour' => 10,
            'receipt_date' => "{$this->yesterday} 02:00:00",
            'gross' => 5000.0, 'cost' => 2000.0, 'cancelled' => false,
            'loyverse_updated_at' => now(),
        ]);
        Expense::query()->create([
            'store_id' => 'arevalo', 'day' => $this->yesterday,
            'category' => 'Meals', 'note' => 'Tapsilog for the crew',
            'amount' => 480.0, 'at' => now(),
        ]);
        Expense::query()->create([
            'store_id' => 'molo', 'day' => $this->yesterday,
            'category' => 'Utilities', 'note' => 'Wifi bill', 'amount' => 1500.0, 'at' => now(),
        ]);

        $deposit = Deposit::query()->create([
            'store_id' => 'arevalo', 'day' => $this->today,
            'amount' => 2520.0, 'online' => 0.0, 'expected' => 2520.0,
            'reference' => '712063', 'slip_path' => 'receipts/deposits/x/slip.jpg',
            'slip_sha' => str_repeat('a', 64), 'matched' => true,
        ]);
        DepositDay::query()->create([
            'store_id' => 'arevalo', 'day' => $this->yesterday, 'deposit_id' => $deposit->id,
        ]);
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twowheelszone')->firstOrFail();
    }

    private function search(User $as, string $q, array $storeIds): TestResponse
    {
        $query = implode('&', [
            'q='.urlencode($q),
            ...array_map(fn (string $id) => 'storeIds='.urlencode($id), $storeIds),
        ]);

        return $this->actingAs($as)->getJson("/api/search?{$query}");
    }

    public function test_a_stranger_is_refused(): void
    {
        $this->getJson('/api/search?q=meals&storeIds=arevalo')->assertStatus(401);
    }

    public function test_a_manager_may_only_search_their_own_branch(): void
    {
        $this->search($this->manager(), 'wifi', ['molo'])->assertStatus(403);
        $this->search($this->manager(), 'wifi', ['arevalo', 'molo'])->assertStatus(403);
    }

    public function test_a_short_query_is_refused_in_the_contract_shape(): void
    {
        $this->search($this->manager(), 'x', ['arevalo'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'fields' => ['q']]);
    }

    public function test_tokens_find_an_expense_by_note(): void
    {
        $response = $this->search($this->manager(), 'tapsilog', ['arevalo'])->assertOk();

        $this->assertSame(1, $response->json('expenses.total'));
        $this->assertSame('Tapsilog for the crew', $response->json('expenses.items.0.note'));
        // A manager gets no accounts or branches, ever
        $this->assertSame(0, $response->json('managers.total'));
        $this->assertSame(0, $response->json('branches.total'));
    }

    public function test_every_token_must_land(): void
    {
        $this->assertSame(
            0,
            $this->search($this->manager(), 'tapsilog molo', ['arevalo'])
                ->assertOk()
                ->json('expenses.total'),
        );
    }

    public function test_an_amount_and_a_reference_are_ordinary_tokens(): void
    {
        $response = $this->search($this->manager(), '712063', ['arevalo'])->assertOk();
        $this->assertSame(1, $response->json('deposits.total'));
        $this->assertSame('712063', $response->json('deposits.items.0.reference'));

        $this->assertSame(
            1,
            $this->search($this->manager(), '480', ['arevalo'])->assertOk()->json('expenses.total'),
        );
    }

    public function test_a_dated_query_narrows_to_that_day(): void
    {
        $d = CarbonImmutable::createFromFormat('Y-m-d', $this->yesterday);
        $worded = strtolower($d->format('F')).' '.$d->format('j');

        $response = $this->search($this->owner(), $worded, ['arevalo', 'molo', 'jaro', 'lapaz'])
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, $response->json('days.total'));
        foreach ($response->json('days.items') as $row) {
            $this->assertSame($this->yesterday, $row['day']);
        }
        // Accounts and branches carry no day, so a dated query skips them
        $this->assertSame(0, $response->json('managers.total'));
        $this->assertSame(0, $response->json('branches.total'));
    }

    public function test_the_owner_finds_accounts_and_branches(): void
    {
        $all = ['arevalo', 'molo', 'jaro', 'lapaz'];

        $response = $this->search($this->owner(), 'marvin', $all)->assertOk();
        $this->assertSame(1, $response->json('managers.total'));
        $this->assertSame('marvin.deocampo', $response->json('managers.items.0.username'));

        $response = $this->search($this->owner(), 'arevalo', $all)->assertOk();
        $this->assertSame(1, $response->json('branches.total'));
    }

    public function test_groups_cap_at_six_but_count_everything(): void
    {
        foreach (range(1, 9) as $i) {
            Expense::query()->create([
                'store_id' => 'arevalo', 'day' => $this->yesterday,
                'category' => 'Meals', 'note' => "Merienda run {$i}",
                'amount' => 100.0 + $i, 'at' => now(),
            ]);
        }

        $response = $this->search($this->manager(), 'merienda', ['arevalo'])->assertOk();
        $this->assertCount(6, $response->json('expenses.items'));
        $this->assertSame(9, $response->json('expenses.total'));
    }
}
