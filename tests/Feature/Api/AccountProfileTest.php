<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * An account editing its own profile — PATCH /api/account per docs/API.md.
 * JSON for the fields, multipart when a photo rides along, the refreshed
 * Session as the answer either way.
 */
class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    private function manager(): User
    {
        return User::query()->where('username', 'marvin.deocampo')->firstOrFail();
    }

    private function owner(): User
    {
        return User::query()->where('username', 'twowheelszone')->firstOrFail();
    }

    public function test_a_stranger_cannot_edit_a_profile(): void
    {
        $this->patchJson('/api/account', [
            'name' => 'Someone', 'username' => 'someone', 'avatarKind' => 'girl',
        ])->assertStatus(401);
    }

    public function test_a_manager_edits_name_username_and_avatar(): void
    {
        $this->actingAs($this->manager())
            ->patchJson('/api/account', [
                'name' => 'Marvin D.', 'username' => 'Marvin.D', 'avatarKind' => 'boy',
            ])
            ->assertOk()
            ->assertJsonPath('manager.name', 'Marvin D.')
            ->assertJsonPath('manager.username', 'marvin.d')
            ->assertJsonPath('manager.avatarKind', 'boy')
            ->assertJsonPath('owner', null);

        // The new username signs in; the old one is gone
        $this->postJson('/api/session', [
            'identifier' => 'marvin.d', 'password' => 'password',
        ])->assertOk();
    }

    public function test_a_taken_username_is_refused_but_keeping_your_own_is_fine(): void
    {
        $this->actingAs($this->manager())
            ->patchJson('/api/account', [
                'name' => 'Marvin', 'username' => 'Joel.Sarabia', 'avatarKind' => 'girl',
            ])
            ->assertStatus(422)
            ->assertJsonPath('fields.username', 'That username is taken.');

        $this->actingAs($this->manager())
            ->patchJson('/api/account', [
                'name' => 'Marvin', 'username' => 'marvin.deocampo', 'avatarKind' => 'girl',
            ])
            ->assertOk();
    }

    public function test_a_photo_uploads_and_serves_behind_the_session(): void
    {
        $response = $this->actingAs($this->manager())->post('/api/account', [
            '_method' => 'PATCH',
            'payload' => json_encode([
                'name' => 'Marvin', 'username' => 'marvin.deocampo', 'avatarKind' => 'girl',
            ]),
            'photo' => [UploadedFile::fake()->image('face.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $url = $response->json('manager.photoUrl');
        $this->assertStringStartsWith('/api/files/avatars/', $url);

        $this->actingAs($this->manager())->get($url)->assertOk();
        /* Signed out, the same URL is a locked door — the guard instance
           remembers the acting user, so it must be forgotten too */
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertStatus(401);
    }

    public function test_a_new_photo_replaces_the_old_one_on_disk(): void
    {
        $first = $this->actingAs($this->manager())->post('/api/account', [
            '_method' => 'PATCH',
            'payload' => json_encode([
                'name' => 'Marvin', 'username' => 'marvin.deocampo', 'avatarKind' => 'girl',
            ]),
            'photo' => [UploadedFile::fake()->image('one.jpg')],
        ], ['Accept' => 'application/json'])->json('manager.photoUrl');

        $this->actingAs($this->manager())->post('/api/account', [
            '_method' => 'PATCH',
            'payload' => json_encode([
                'name' => 'Marvin', 'username' => 'marvin.deocampo', 'avatarKind' => 'girl',
            ]),
            'photo' => [UploadedFile::fake()->image('two.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        Storage::assertMissing(substr($first, strlen('/api/files/')));
    }

    public function test_remove_photo_clears_it(): void
    {
        $this->actingAs($this->manager())->post('/api/account', [
            '_method' => 'PATCH',
            'payload' => json_encode([
                'name' => 'Marvin', 'username' => 'marvin.deocampo', 'avatarKind' => 'girl',
            ]),
            'photo' => [UploadedFile::fake()->image('face.jpg')],
        ], ['Accept' => 'application/json'])->assertOk();

        $stored = $this->manager()->fresh()->photo_path;

        $this->actingAs($this->manager())
            ->patchJson('/api/account', [
                'name' => 'Marvin', 'username' => 'marvin.deocampo',
                'avatarKind' => 'girl', 'removePhoto' => true,
            ])
            ->assertOk()
            ->assertJsonPath('manager.photoUrl', null);

        Storage::assertMissing($stored);
    }

    public function test_the_owner_uses_the_same_door(): void
    {
        $this->actingAs($this->owner())
            ->patchJson('/api/account', [
                'name' => 'The Owner', 'username' => 'twowheelszone', 'avatarKind' => 'boy',
            ])
            ->assertOk()
            ->assertJsonPath('owner.name', 'The Owner')
            ->assertJsonPath('owner.avatarKind', 'boy')
            ->assertJsonPath('manager', null);
    }

    public function test_bad_fields_come_back_in_the_contract_shape(): void
    {
        $this->actingAs($this->manager())
            ->patchJson('/api/account', [
                'name' => '', 'username' => 'has spaces!', 'avatarKind' => 'girl',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'fields' => ['name', 'username']]);
    }
}
