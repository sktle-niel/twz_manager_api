<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * The browser-run installer. It must not exist without its key, must finish
 * a fresh database in one visit, and must only report on the second.
 */
class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_configured_key_the_route_plays_dead(): void
    {
        config(['twz.setup_key' => '']);

        $this->getJson('/setup/anything')->assertStatus(404);
    }

    public function test_a_wrong_key_plays_dead_too(): void
    {
        config(['twz.setup_key' => 'right-key']);

        $this->getJson('/setup/wrong-key')->assertStatus(404);
    }

    public function test_one_visit_seeds_and_sets_the_start_day(): void
    {
        config(['twz.setup_key' => 'right-key']);
        $this->assertSame(0, User::query()->count());

        $this->getJson('/setup/right-key?start=2026-08-13')
            ->assertOk()
            ->assertJsonPath('start_day', '2026-08-13');

        $this->assertGreaterThan(0, User::query()->count());
        $this->assertSame('2026-08-13', Setting::read('audit_start_day'));

        // The second visit reports instead of reseeding
        $before = User::query()->count();
        $this->getJson('/setup/right-key')
            ->assertOk()
            ->assertJsonPath('seed', 'skipped: accounts already exist');
        $this->assertSame($before, User::query()->count());
    }
}
