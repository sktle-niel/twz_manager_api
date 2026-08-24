<?php

namespace Tests\Feature\Api;

use App\Models\Deposit;
use App\Models\Expense;
use App\Models\ExpensePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * GET /api/files/{path} — the door every stored photo comes through.
 *
 * Being signed in used to be the whole test, which meant any account could
 * pull any branch's deposit slips and expense receipts given the URL. These
 * photos are the evidence behind the money, so the door now asks which branch
 * owns the file and applies the same scope every JSON read uses.
 *
 * Denials answer 404, not 403: whether a photo exists is itself a fact about
 * another branch.
 */
class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $arevaloSlip;

    private string $moloReceipt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');

        /* A deposit slip belonging to Arevalo */
        $this->arevaloSlip = 'receipts/slips/arevalo/slip.jpg';
        Deposit::query()->create([
            'store_id' => 'arevalo', 'day' => '2026-08-02', 'amount' => 100.0,
            'online' => 0.0, 'slip_path' => $this->arevaloSlip, 'matched' => true,
        ]);
        Storage::put($this->arevaloSlip, 'slip bytes');

        /* An expense receipt belonging to Molo */
        $expense = Expense::query()->create([
            'store_id' => 'molo', 'day' => '2026-08-02', 'category' => 'Meals',
            'note' => 'lunch', 'amount' => 100.0, 'at' => now(),
        ]);
        $this->moloReceipt = "receipts/expenses/{$expense->id}/receipt.jpg";
        ExpensePhoto::query()->create(['expense_id' => $expense->id, 'path' => $this->moloReceipt]);
        Storage::put($this->moloReceipt, 'receipt bytes');
    }

    private function manager(string $username): User
    {
        return User::query()->where('username', $username)->firstOrFail();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twowheelszone')->firstOrFail();
    }

    public function test_a_stranger_gets_nothing(): void
    {
        $this->getJson('/api/files/'.$this->arevaloSlip)->assertStatus(401);
    }

    public function test_a_manager_reads_their_own_branch_slip(): void
    {
        $this->actingAs($this->manager('marvin.deocampo'))
            ->get('/api/files/'.$this->arevaloSlip)
            ->assertOk();
    }

    /* The whole point of the change */
    public function test_a_manager_cannot_read_another_branch_slip(): void
    {
        $this->actingAs($this->manager('joel.sarabia'))
            ->get('/api/files/'.$this->arevaloSlip)
            ->assertNotFound();
    }

    public function test_a_manager_cannot_read_another_branch_receipt(): void
    {
        $this->actingAs($this->manager('marvin.deocampo'))
            ->get('/api/files/'.$this->moloReceipt)
            ->assertNotFound();
    }

    public function test_the_owner_reads_every_branch(): void
    {
        $this->actingAs($this->owner())
            ->get('/api/files/'.$this->arevaloSlip)
            ->assertOk();

        $this->actingAs($this->owner())
            ->get('/api/files/'.$this->moloReceipt)
            ->assertOk();
    }

    /* Colleagues' faces stay open to anyone signed in — there is no branch to
       scope them to, and gating them would break the manager list */
    public function test_an_avatar_is_readable_by_any_account(): void
    {
        Storage::put('avatars/9/face.jpg', 'face bytes');

        $this->actingAs($this->manager('joel.sarabia'))
            ->get('/api/files/avatars/9/face.jpg')
            ->assertOk();
    }

    /* A path nothing in the database claims is not servable, even to the
       owner: an orphan file on disk has no branch and no business being read */
    public function test_an_unclaimed_receipts_path_is_refused(): void
    {
        Storage::put('receipts/slips/arevalo/stray.jpg', 'orphan');

        $this->actingAs($this->owner())
            ->get('/api/files/receipts/slips/arevalo/stray.jpg')
            ->assertNotFound();
    }

    public function test_a_traversing_path_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->get('/api/files/receipts/../.env')
            ->assertNotFound();
    }

    public function test_a_served_photo_carries_its_own_locked_down_policy(): void
    {
        $this->actingAs($this->manager('marvin.deocampo'))
            ->get('/api/files/'.$this->arevaloSlip)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            /* Stricter than the app-wide policy, and the global middleware
               must never widen it back out */
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    }
}
