<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetPinTest extends TestCase
{
    use RefreshDatabase;

    private const PIN = '8017';

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

    public function test_a_manager_cannot_see_or_change_the_pin(): void
    {
        $this->actingAs($this->manager())->getJson('/api/settings/reset-pin')->assertStatus(403);
        $this->actingAs($this->manager())
            ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => '1234'])
            ->assertStatus(403);
    }

    public function test_a_fresh_install_says_the_pin_is_still_the_default(): void
    {
        $this->actingAs($this->owner())
            ->getJson('/api/settings/reset-pin')
            ->assertOk()
            ->assertJsonPath('isDefault', true)
            ->assertJsonPath('length', 4)
            ->assertJsonPath('changedAt', null);
    }

    public function test_the_pin_itself_is_never_sent_back(): void
    {
        $body = $this->actingAs($this->owner())->getJson('/api/settings/reset-pin')->json();

        $this->assertStringNotContainsString(self::PIN, json_encode($body));
    }

    public function test_changing_the_pin_retires_the_old_one(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => '4921'])
            ->assertNoContent();

        // The new PIN opens the reset door
        $this->actingAs($this->owner())
            ->putJson('/api/managers/'.$this->manager()->id.'/password', [
                'pin' => '4921',
                'password' => 'bagong-password',
            ])
            ->assertNoContent();

        // And the shipped one no longer does
        $this->actingAs($this->owner())
            ->putJson('/api/managers/'.$this->manager()->id.'/password', [
                'pin' => self::PIN,
                'password' => 'iba-pang-password',
            ])
            ->assertStatus(422);
    }

    public function test_only_the_hash_is_stored(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => '4921'])
            ->assertNoContent();

        $stored = DB::table('settings')->where('key', 'admin_reset_pin_hash')->value('value');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('4921', $stored);
    }

    public function test_a_changed_pin_stops_reporting_as_default(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => '4921'])
            ->assertNoContent();

        $this->actingAs($this->owner())
            ->getJson('/api/settings/reset-pin')
            ->assertOk()
            ->assertJsonPath('isDefault', false);
    }

    public function test_changing_it_back_to_the_shipped_value_is_still_a_default(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => '4921'])
            ->assertNoContent();
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => '4921', 'newPin' => self::PIN])
            ->assertNoContent();

        // A documented default is not a secret, whichever route it got there by
        $this->actingAs($this->owner())
            ->getJson('/api/settings/reset-pin')
            ->assertOk()
            ->assertJsonPath('isDefault', true);
    }

    public function test_the_wrong_current_pin_changes_nothing(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/settings/reset-pin', ['currentPin' => '0000', 'newPin' => '4921'])
            ->assertStatus(422)
            ->assertJsonPath('fields.currentPin', 'That is not the current PIN.');

        $this->actingAs($this->owner())
            ->getJson('/api/settings/reset-pin')
            ->assertJsonPath('isDefault', true);
    }

    public function test_a_new_pin_must_be_four_digits(): void
    {
        foreach (['123', '12345', 'abcd', ''] as $bad) {
            $this->actingAs($this->owner())
                ->putJson('/api/settings/reset-pin', ['currentPin' => self::PIN, 'newPin' => $bad])
                ->assertStatus(422)
                ->assertJsonStructure(['fields' => ['newPin']]);
        }
    }
}
