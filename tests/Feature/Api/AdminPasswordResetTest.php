<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * The only way a forgotten password gets fixed now: the owner sets a new one.
 * Two locks — signed in as the owner, and the PIN — so neither alone is
 * enough to take over a branch account.
 */
class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '8017';

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

    private function resetUrl(?User $target = null): string
    {
        return '/api/managers/'.($target ?? $this->manager())->id.'/password';
    }

    public function test_the_shipped_pin_is_8017(): void
    {
        $this->assertSame(self::PIN, config('twz.reset_pin'));
    }

    public function test_a_stranger_is_refused(): void
    {
        $this->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertStatus(401);
    }

    public function test_a_manager_cannot_reset_anyones_password(): void
    {
        $this->actingAs($this->manager())
            ->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertStatus(403);
    }

    public function test_the_owner_with_the_pin_sets_a_new_password(): void
    {
        $this->actingAs($this->owner())
            ->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertNoContent();

        // The old password is gone and the new one works
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'bagong-password',
        ])->assertOk();
    }

    public function test_a_wrong_pin_changes_nothing(): void
    {
        $this->actingAs($this->owner())
            ->putJson($this->resetUrl(), ['pin' => '0000', 'password' => 'bagong-password'])
            ->assertStatus(422)
            ->assertJsonPath('fields.pin', 'That is not the PIN.');

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_the_pin_stops_answering_after_five_wrong_tries(): void
    {
        $this->actingAs($this->owner());

        for ($i = 0; $i < 5; $i++) {
            $this->putJson($this->resetUrl(), ['pin' => '0000', 'password' => 'bagong-password'])
                ->assertStatus(422);
        }

        // The sixth is refused even with the RIGHT pin
        $this->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    public function test_a_correct_pin_clears_the_failure_count(): void
    {
        $this->actingAs($this->owner());

        for ($i = 0; $i < 4; $i++) {
            $this->putJson($this->resetUrl(), ['pin' => '0000', 'password' => 'x'.$i.'-password'])
                ->assertStatus(422);
        }

        $this->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertNoContent();

        // Slate clean: another wrong pin is a 422, not a lockout
        $this->putJson($this->resetUrl(), ['pin' => '0000', 'password' => 'iba-pang-password'])
            ->assertStatus(422);
    }

    public function test_the_owner_cannot_reset_their_own_password_here(): void
    {
        $this->actingAs($this->owner())
            ->putJson($this->resetUrl($this->owner()), [
                'pin' => self::PIN,
                'password' => 'bagong-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use Account to change your own password.');
    }

    public function test_an_account_that_is_gone_answers_404(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/managers/99999/password', [
                'pin' => self::PIN,
                'password' => 'bagong-password',
            ])
            ->assertStatus(404)
            ->assertJsonStructure(['message']);
    }

    public function test_a_short_password_is_refused_in_the_contract_shape(): void
    {
        $this->actingAs($this->owner())
            ->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check the highlighted fields.')
            ->assertJsonStructure(['fields' => ['password']]);
    }

    public function test_resetting_signs_out_remembered_devices(): void
    {
        $before = $this->manager()->remember_token;

        $this->actingAs($this->owner())
            ->putJson($this->resetUrl(), ['pin' => self::PIN, 'password' => 'bagong-password'])
            ->assertNoContent();

        $this->assertNotSame($before, $this->manager()->fresh()->remember_token);
    }
}
