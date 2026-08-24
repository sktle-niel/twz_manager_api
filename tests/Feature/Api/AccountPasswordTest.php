<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * An account changing its own password. The current password is the proof —
 * a session alone must never be enough to re-key an account.
 */
class AccountPasswordTest extends TestCase
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

    public function test_a_stranger_cannot_change_a_password(): void
    {
        $this->putJson('/api/account/password', ['current' => 'password', 'next' => 'bagong-password'])
            ->assertStatus(401);
    }

    public function test_the_wrong_current_password_changes_nothing(): void
    {
        $this->actingAs($this->manager())
            ->putJson('/api/account/password', ['current' => 'hindi-ito', 'next' => 'bagong-password'])
            ->assertStatus(422)
            ->assertJsonPath('fields.current', 'That is not your current password.');

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_the_right_current_password_replaces_it(): void
    {
        $this->actingAs($this->manager())
            ->putJson('/api/account/password', ['current' => 'password', 'next' => 'bagong-password'])
            ->assertNoContent();

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'bagong-password',
        ])->assertOk();
    }

    public function test_a_short_new_password_is_refused_in_the_contract_shape(): void
    {
        $this->actingAs($this->manager())
            ->putJson('/api/account/password', ['current' => 'password', 'next' => 'short'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check the highlighted fields.')
            ->assertJsonStructure(['fields' => ['next']]);
    }

    public function test_changing_signs_out_remembered_devices(): void
    {
        $before = $this->manager()->remember_token;

        $this->actingAs($this->manager())
            ->putJson('/api/account/password', ['current' => 'password', 'next' => 'bagong-password'])
            ->assertNoContent();

        $this->assertNotSame($before, $this->manager()->fresh()->remember_token);
    }

    public function test_the_owner_uses_the_same_door(): void
    {
        $owner = User::query()->where('username', 'twowheelszone')->firstOrFail();

        $this->actingAs($owner)
            ->putJson('/api/account/password', ['current' => 'password', 'next' => 'bagong-password'])
            ->assertNoContent();

        $this->postJson('/api/session', [
            'identifier' => 'twowheelszone',
            'password' => 'bagong-password',
        ])->assertOk();
    }

    /*
     * Changing a password is what a person does after they think somebody
     * else has it. Rotating remember_token alone only drops the remembered
     * cookies — a device already signed in keeps a live session, and that is
     * exactly the device being changed away from.
     */
    public function test_changing_a_password_ends_the_other_live_sessions(): void
    {
        $manager = $this->manager();
        $other = User::query()->where('username', 'joel.sarabia')->firstOrFail();

        DB::table('sessions')->insert([
            ['id' => 'another-device', 'user_id' => $manager->id,
             'ip_address' => '127.0.0.1', 'user_agent' => 'the other phone',
             'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp()],
            ['id' => 'a-different-account', 'user_id' => $other->id,
             'ip_address' => '127.0.0.1', 'user_agent' => 'untouched',
             'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp()],
        ]);

        $this->actingAs($manager)
            ->putJson('/api/account/password', ['current' => 'password', 'next' => 'bagong-password'])
            ->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'another-device']);
        /* Only this account's doors close */
        $this->assertDatabaseHas('sessions', ['id' => 'a-different-account']);
    }
}
