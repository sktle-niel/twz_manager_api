<?php

namespace Tests\Feature\Api;

use App\Models\FailedSignIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * The attempts that did not work.
 *
 * The throttle already stops a guessing run after five tries — but it stopped
 * it silently, so nobody ever learned it happened. These rows are the owner's
 * only way to see somebody working through a branch account.
 *
 * The person knocking still learns nothing: one message for a wrong username
 * and a wrong password alike, and the `known` flag that distinguishes them is
 * visible only to the owner, afterwards.
 */
class FailedSignInTest extends TestCase
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

    public function test_a_wrong_password_on_a_real_account_is_recorded_as_known(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'hindi-ito',
        ])->assertStatus(401);

        $row = FailedSignIn::query()->sole();
        $this->assertSame('marvin.deocampo', $row->identifier);
        $this->assertTrue($row->known);
    }

    public function test_an_unknown_username_is_recorded_as_unknown(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'walang.ganito',
            'password' => 'anything',
        ])->assertStatus(401);

        $this->assertFalse(FailedSignIn::query()->sole()->known);
    }

    /* The response must not differ — only the record does */
    public function test_the_refusal_reads_the_same_either_way(): void
    {
        $real = $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo', 'password' => 'mali',
        ])->assertStatus(401);

        $fake = $this->postJson('/api/session', [
            'identifier' => 'wala.talaga', 'password' => 'mali',
        ])->assertStatus(401);

        $this->assertSame($real->json('message'), $fake->json('message'));
    }

    public function test_a_successful_sign_in_records_nothing(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo', 'password' => 'password',
        ])->assertOk();

        $this->assertSame(0, FailedSignIn::query()->count());
    }

    /* Never, under any circumstance */
    public function test_no_password_is_stored(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo', 'password' => 'sikreto-ko-ito',
        ])->assertStatus(401);

        $row = FailedSignIn::query()->sole()->getAttributes();
        foreach ($row as $value) {
            $this->assertStringNotContainsString('sikreto-ko-ito', (string) $value);
        }
    }

    public function test_only_the_owner_may_read_them(): void
    {
        $this->getJson('/api/security/failed-sign-ins')->assertStatus(401);

        $this->actingAs($this->manager())
            ->getJson('/api/security/failed-sign-ins')
            ->assertStatus(403);
    }

    public function test_the_owner_reads_them_newest_first(): void
    {
        FailedSignIn::query()->create([
            'identifier' => 'older', 'ip' => '1.1.1.1', 'device' => 'x',
            'platform' => 'y', 'kind' => 'phone', 'known' => false,
            'at' => now()->subHour(),
        ]);
        FailedSignIn::query()->create([
            'identifier' => 'newer', 'ip' => '1.1.1.1', 'device' => 'x',
            'platform' => 'y', 'kind' => 'phone', 'known' => true,
            'at' => now(),
        ]);

        $this->actingAs($this->owner())
            ->getJson('/api/security/failed-sign-ins')
            ->assertOk()
            ->assertJsonPath('0.identifier', 'newer')
            ->assertJsonPath('0.known', true)
            ->assertJsonPath('1.identifier', 'older');
    }
}
