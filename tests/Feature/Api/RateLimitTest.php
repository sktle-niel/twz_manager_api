<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * A limiter that is defined but never applied is the easiest thing in the
 * world to ship by accident: the code reads correct, and nothing fails. The
 * api middleware group only consults it because bootstrap/app.php opts in
 * with throttleApi(). These tests fail the moment that line goes missing.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_every_api_response_carries_the_limiter_headers(): void
    {
        $this->getJson('/api/session')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', 120);
    }

    public function test_the_group_refuses_the_hundred_and_twenty_first_request(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/session')->assertOk();
        }

        $this->getJson('/api/session')
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many requests just now. Try again in a moment.');
    }

    public function test_one_account_cannot_spend_anothers_allowance(): void
    {
        $marvin = User::query()->where('username', 'marvin.deocampo')->first();
        $joel = User::query()->where('username', 'joel.sarabia')->first();

        $this->actingAs($marvin);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/session')->assertOk();
        }
        $this->getJson('/api/session')->assertStatus(429);

        // Joel is behind the same branch router, and is not collateral damage
        $this->actingAs($joel);
        $this->getJson('/api/session')->assertOk();
    }
}
