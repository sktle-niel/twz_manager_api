<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/*
 * The owner account comes from .env, but only as a starting point: the
 * password lands once, when the account is first created, and after that the
 * app owns it. These tests are what keeps a reseed from ever quietly undoing
 * a password the owner set for themselves.
 */
class SeededOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::query()->where('role', User::ROLE_OWNER)->sole();
    }

    public function test_a_fresh_database_seeds_the_owner_from_env(): void
    {
        $this->seed();

        $owner = $this->owner();
        $this->assertSame(config('twz.owner_username'), $owner->username);
        $this->assertTrue(Hash::check(config('twz.owner_password'), $owner->password));
    }

    public function test_reseeding_never_touches_a_changed_password(): void
    {
        $this->seed();
        $this->owner()->forceFill(['password' => 'pinalitan-na-ito'])->save();

        $this->seed();

        // The app owns the password now; OWNER_PASSWORD was only the first one
        $this->assertTrue(Hash::check('pinalitan-na-ito', $this->owner()->password));
        $this->assertFalse(Hash::check(config('twz.owner_password'), $this->owner()->password));
    }

    public function test_reseeding_keeps_one_owner_and_the_env_username(): void
    {
        $this->seed();
        $this->owner()->forceFill(['username' => 'drifted.name'])->save();

        $this->seed();

        // The username is configuration, so it follows .env back
        $this->assertSame(1, User::query()->where('role', User::ROLE_OWNER)->count());
        $this->assertSame(config('twz.owner_username'), $this->owner()->username);
    }

    public function test_testaccount_is_a_lapaz_manager_that_signs_in(): void
    {
        $this->seed();

        $this->postJson('/api/session', [
            'identifier' => 'testaccount',
            'password' => 'test1234',
        ])
            ->assertOk()
            ->assertJsonPath('manager.username', 'testaccount')
            ->assertJsonPath('manager.storeId', 'lapaz')
            ->assertJsonPath('owner', null);
    }
}
