<?php

namespace Tests\Feature;

use App\Services\Loyverse\LoyverseAuthException;
use App\Services\Loyverse\LoyverseBudgetExhausted;
use App\Services\Loyverse\LoyverseClient;
use App\Services\Loyverse\LoyverseNotConfigured;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoyverseClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['loyverse.token' => 'tok-test', 'loyverse.budget' => 3]);
    }

    public function test_a_missing_token_never_produces_a_request(): void
    {
        config(['loyverse.token' => '']);
        Http::fake();

        $this->expectException(LoyverseNotConfigured::class);
        try {
            app(LoyverseClient::class)->merchant();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_budget_guard_refuses_before_the_request_leaves(): void
    {
        Http::fake(['api.loyverse.com/*' => Http::response(['ok' => true])]);
        $client = app(LoyverseClient::class);

        $client->merchant();
        $client->merchant();
        $client->merchant();

        // The window is spent — the fourth call must not reach Loyverse
        try {
            $client->merchant();
            $this->fail('Expected LoyverseBudgetExhausted');
        } catch (LoyverseBudgetExhausted $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
        }
        Http::assertSentCount(3);
    }

    public function test_a_429_from_loyverse_surfaces_the_shared_budget(): void
    {
        Http::fake([
            'api.loyverse.com/*' => Http::response(['error' => 'rate'], 429, ['Retry-After' => '42']),
        ]);

        try {
            app(LoyverseClient::class)->merchant();
            $this->fail('Expected LoyverseBudgetExhausted');
        } catch (LoyverseBudgetExhausted $e) {
            $this->assertSame(42, $e->retryAfterSeconds);
        }
    }

    public function test_an_upstream_429_starts_a_cooldown_the_next_call_respects(): void
    {
        Http::fake([
            'api.loyverse.com/*' => Http::response(['error' => 'rate'], 429, ['Retry-After' => '42']),
        ]);
        $client = app(LoyverseClient::class);

        try {
            $client->merchant();
        } catch (LoyverseBudgetExhausted) {
            // expected — the 429 itself
        }

        // The Retry-After is in force for everyone: no second request crosses
        try {
            $client->merchant();
            $this->fail('Expected LoyverseBudgetExhausted');
        } catch (LoyverseBudgetExhausted $e) {
            $this->assertGreaterThan(0, $e->retryAfterSeconds);
            $this->assertLessThanOrEqual(42, $e->retryAfterSeconds);
        }
        Http::assertSentCount(1);
    }

    public function test_a_rejected_token_is_its_own_failure(): void
    {
        Http::fake(['api.loyverse.com/*' => Http::response(['error' => 'no'], 401)]);

        $this->expectException(LoyverseAuthException::class);
        app(LoyverseClient::class)->merchant();
    }

    public function test_requests_carry_the_bearer_token_to_the_right_host(): void
    {
        Http::fake(['api.loyverse.com/*' => Http::response(['stores' => []])]);

        app(LoyverseClient::class)->stores();

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.loyverse.com/v1.0/stores')
                && $request->hasHeader('Authorization', 'Bearer tok-test');
        });
    }
}
