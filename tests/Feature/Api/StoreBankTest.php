<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Which bank a branch deposits to. Every branch reads as BDO until the owner
 * says otherwise; the manager's slip check hangs on this, so the value is
 * held to the two banks the app knows and the whole list comes back so the
 * settings page can adopt it in one step.
 */
class StoreBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twowheelszone')->firstOrFail();
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    public function test_every_branch_banks_at_bdo_until_told_otherwise(): void
    {
        $this->actingAs($this->manager())
            ->getJson('/api/stores')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.id', 'arevalo')
            ->assertJsonPath('0.bank', 'bdo')
            ->assertJsonPath('3.bank', 'bdo');
    }

    public function test_the_owner_moves_a_branch_to_bpi_and_gets_the_whole_list_back(): void
    {
        $this->actingAs($this->owner())
            ->patchJson('/api/stores/jaro', ['bank' => 'bpi'])
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('1.id', 'jaro')
            ->assertJsonPath('1.bank', 'bpi')
            ->assertJsonPath('0.bank', 'bdo');

        $this->assertSame('bpi', Store::query()->findOrFail('jaro')->bank);

        // What the manager side reads next — the slip check runs on this
        $this->actingAs($this->manager())
            ->getJson('/api/stores')
            ->assertOk()
            ->assertJsonPath('1.bank', 'bpi');

        // And back again
        $this->actingAs($this->owner())
            ->patchJson('/api/stores/jaro', ['bank' => 'bdo'])
            ->assertOk()
            ->assertJsonPath('1.bank', 'bdo');
    }

    public function test_an_unknown_bank_is_a_field_error_and_a_gone_branch_is_404(): void
    {
        $this->actingAs($this->owner())
            ->patchJson('/api/stores/jaro', ['bank' => 'metrobank'])
            ->assertStatus(422)
            ->assertJsonPath('fields.bank', 'Pick BDO or BPI.');

        $this->actingAs($this->owner())
            ->patchJson('/api/stores/jaro', [])
            ->assertStatus(422)
            ->assertJsonPath('fields.bank', 'Pick BDO or BPI.');

        $this->actingAs($this->owner())
            ->patchJson('/api/stores/mandurriao', ['bank' => 'bpi'])
            ->assertStatus(404);

        $this->assertSame('bdo', Store::query()->findOrFail('jaro')->bank);
    }

    public function test_a_manager_cannot_change_a_branch_bank(): void
    {
        $this->actingAs($this->manager())
            ->patchJson('/api/stores/arevalo', ['bank' => 'bpi'])
            ->assertStatus(403);

        $this->assertSame('bdo', Store::query()->findOrFail('arevalo')->bank);
    }

    /* Its own method: actingAs() sticks for the rest of a test, so an
       anonymous request has to start from a fresh one */
    public function test_nobody_signed_out_can_change_a_branch_bank(): void
    {
        $this->patchJson('/api/stores/arevalo', ['bank' => 'bpi'])->assertStatus(401);

        $this->assertSame('bdo', Store::query()->findOrFail('arevalo')->bank);
    }
}
