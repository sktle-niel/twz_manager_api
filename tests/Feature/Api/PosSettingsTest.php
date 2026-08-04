<?php

namespace Tests\Feature\Api;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PosSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twz.owner')->firstOrFail();
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    public function test_pos_settings_are_owner_only(): void
    {
        $this->getJson('/api/settings/pos')->assertStatus(401);

        $this->actingAs($this->manager(), 'web')
            ->getJson('/api/settings/pos')
            ->assertStatus(403)
            ->assertJsonPath('message', 'You do not have access to that.');
    }

    public function test_without_a_token_the_connection_reads_disconnected(): void
    {
        config(['loyverse.token' => '']);

        $this->actingAs($this->owner(), 'web')
            ->getJson('/api/settings/pos')
            ->assertOk()
            ->assertExactJson(['connected' => false, 'storesLinked' => 0, 'tokenHint' => '']);
    }

    public function test_a_valid_token_reads_connected_with_its_hint(): void
    {
        config(['loyverse.token' => 'tok-abcd1234']);
        Http::fake(['api.loyverse.com/*' => Http::response(['id' => 'merchant-1'])]);
        Store::query()->whereKey('arevalo')->update(['loyverse_store_id' => 'ly-1']);

        $this->actingAs($this->owner(), 'web')
            ->getJson('/api/settings/pos')
            ->assertOk()
            ->assertExactJson(['connected' => true, 'storesLinked' => 1, 'tokenHint' => '1234']);

        // The validation is cached — a second read must not spend budget
        $this->getJson('/api/settings/pos')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_reconnect_reports_a_rejected_token_in_words(): void
    {
        config(['loyverse.token' => 'tok-deleted']);
        Http::fake(['api.loyverse.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->actingAs($this->owner(), 'web')
            ->postJson('/api/settings/pos/reconnect')
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Loyverse rejected the token. It may have been deleted — create a new one and update the server.',
            );

        // And the status now reads disconnected without another probe
        $this->getJson('/api/settings/pos')->assertJsonPath('connected', false);
        Http::assertSentCount(1);
    }

    public function test_reconnect_without_a_token_says_what_to_do(): void
    {
        config(['loyverse.token' => '']);

        $this->actingAs($this->owner(), 'web')
            ->postJson('/api/settings/pos/reconnect')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_reconnect_succeeds_against_a_live_token(): void
    {
        config(['loyverse.token' => 'tok-good']);
        Http::fake(['api.loyverse.com/*' => Http::response(['id' => 'merchant-1'])]);

        $this->actingAs($this->owner(), 'web')
            ->postJson('/api/settings/pos/reconnect')
            ->assertNoContent();
    }
}
