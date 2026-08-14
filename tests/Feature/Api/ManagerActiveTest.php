<?php

namespace Tests\Feature\Api;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * Disabling a branch account — PATCH /api/managers/{id}/active. Turning one
 * off must revoke every door at once: the password stops working at the front,
 * live sessions end, remembered devices are forgotten, and the push mailboxes
 * go quiet. Turning it back on restores only the front door.
 */
class ManagerActiveTest extends TestCase
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

    public function test_only_the_owner_may_flip_the_switch(): void
    {
        $this->patchJson("/api/managers/{$this->manager()->id}/active", ['active' => false])
            ->assertStatus(401);

        $this->actingAs($this->manager())
            ->patchJson("/api/managers/{$this->manager()->id}/active", ['active' => false])
            ->assertStatus(403);
    }

    public function test_disabling_shows_in_the_returned_roster(): void
    {
        $target = $this->manager();

        $response = $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$target->id}/active", ['active' => false])
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', (string) $target->id);
        $this->assertFalse($row['active']);
        // The seat stays theirs — disabling is not unassigning
        $this->assertSame('arevalo', $row['storeId']);
    }

    public function test_a_disabled_manager_cannot_sign_in_until_re_enabled(): void
    {
        $target = $this->manager();

        $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$target->id}/active", ['active' => false])
            ->assertOk();

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo', 'password' => 'password',
        ])->assertStatus(403);

        $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$target->id}/active", ['active' => true])
            ->assertOk();

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo', 'password' => 'password',
        ])->assertOk();
    }

    public function test_disabling_drops_sessions_devices_and_mailboxes(): void
    {
        $target = $this->manager();
        $before = $target->remember_token;

        DB::table('sessions')->insert([
            'id' => 'sess-of-marvin', 'user_id' => $target->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'test',
            'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->getTimestamp(),
        ]);
        PushSubscription::query()->create([
            'user_id' => $target->id, 'endpoint' => 'https://push.example/marvin',
            'p256dh' => 'key', 'auth' => 'auth',
        ]);

        $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$target->id}/active", ['active' => false])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['id' => 'sess-of-marvin']);
        $this->assertDatabaseMissing('push_subscriptions', ['user_id' => $target->id]);
        $this->assertNotSame($before, $target->fresh()->remember_token);
    }

    public function test_the_owner_and_ghosts_are_out_of_reach(): void
    {
        $this->actingAs($this->owner())
            ->patchJson("/api/managers/{$this->owner()->id}/active", ['active' => false])
            ->assertStatus(404);

        $this->actingAs($this->owner())
            ->patchJson('/api/managers/999999/active', ['active' => false])
            ->assertStatus(404);
    }
}
