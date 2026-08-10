<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagersTest extends TestCase
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

    public function test_the_owner_lists_every_manager_and_a_manager_lists_nothing(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/managers')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.username', 'joel.sarabia')
            ->assertJsonMissingPath('0.email');

        $this->actingAs($this->manager())->getJson('/api/managers')->assertStatus(403);
    }

    public function test_issuing_an_account_creates_a_working_sign_in(): void
    {
        // The dev fixtures hold every branch, so free one up first
        User::query()->where('username', 'testaccount')->delete();

        $this->actingAs($this->owner())
            ->postJson('/api/managers', [
                'name' => 'Ana Reyes',
                'username' => 'Ana.Reyes',
                'storeId' => 'lapaz',
                'password' => 'unang-password',
            ])
            ->assertOk()
            ->assertJsonPath('username', 'ana.reyes')
            ->assertJsonPath('storeId', 'lapaz')
            ->assertJsonPath('active', true);

        $this->postJson('/api/session', [
            'identifier' => 'ANA.REYES',
            'password' => 'unang-password',
        ])->assertOk();
    }

    public function test_a_taken_username_and_a_taken_branch_are_named_field_errors(): void
    {
        $this->actingAs($this->owner())
            ->postJson('/api/managers', [
                'name' => 'X', 'username' => 'MARVIN.DEOCAMPO', 'storeId' => 'lapaz', 'password' => 'x-password-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('fields.username', 'That username is taken.');

        $this->actingAs($this->owner())
            ->postJson('/api/managers', [
                'name' => 'X', 'username' => 'bagong.tao', 'storeId' => 'arevalo', 'password' => 'x-password-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('fields.storeId', 'That branch already has a manager.');

        $this->actingAs($this->owner())
            ->postJson('/api/managers', [
                'name' => 'X', 'username' => 'may bantas!', 'storeId' => 'lapaz', 'password' => 'x-password-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('fields.username', 'Letters, numbers, dots, dashes and underscores only.');
    }

    public function test_assigning_an_occupied_branch_swaps_the_two(): void
    {
        $marvin = $this->manager(); // arevalo
        $joel = User::query()->where('username', 'joel.sarabia')->firstOrFail(); // molo

        $response = $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$marvin->id}/branch", ['storeId' => 'molo'])
            ->assertOk();

        $this->assertSame('molo', $marvin->fresh()->store_id);
        $this->assertSame('arevalo', $joel->fresh()->store_id);
        // The full list comes back, so the page never re-queries after a move
        $this->assertCount(4, $response->json());
    }

    public function test_assigning_a_free_branch_is_a_plain_move(): void
    {
        User::query()->where('username', 'testaccount')->delete(); // frees lapaz
        $marvin = $this->manager();

        $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$marvin->id}/branch", ['storeId' => 'lapaz'])
            ->assertOk();

        $this->assertSame('lapaz', $marvin->fresh()->store_id);
    }

    public function test_a_manager_cannot_issue_or_move_accounts(): void
    {
        $this->actingAs($this->manager())
            ->postJson('/api/managers', [
                'name' => 'X', 'username' => 'sneaky', 'storeId' => 'lapaz', 'password' => 'x-password-1',
            ])
            ->assertStatus(403);

        $this->actingAs($this->manager())
            ->patchJson("/api/managers/{$this->manager()->id}/branch", ['storeId' => 'lapaz'])
            ->assertStatus(403);
    }
}
