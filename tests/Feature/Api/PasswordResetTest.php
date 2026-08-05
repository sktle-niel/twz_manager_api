<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_an_unknown_identifier_answers_204_and_leaves_no_trace(): void
    {
        $this->postJson('/api/password-resets', ['identifier' => 'who.is.this'])
            ->assertNoContent();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Notification::assertNothingSent();
    }

    public function test_a_username_earns_a_link_and_a_stored_token(): void
    {
        $this->postJson('/api/password-resets', ['identifier' => 'MARVIN.DEOCAMPO'])
            ->assertNoContent();

        Notification::assertSentTo(
            User::query()->where('username', 'marvin.deocampo')->first(),
            ResetPassword::class,
        );

        // The row is the token: hashed, one per account
        $row = DB::table('password_reset_tokens')->where('email', 'marvin.deocampo@gmail.com')->first();
        $this->assertNotNull($row);
        $this->assertNotEmpty($row->token);
    }

    public function test_a_gmail_address_works_as_the_identifier_too(): void
    {
        $this->postJson('/api/password-resets', ['identifier' => 'owner@gmail.com'])
            ->assertNoContent();

        Notification::assertSentTo(
            User::query()->where('username', 'twz.owner')->first(),
            ResetPassword::class,
        );
    }

    public function test_a_disabled_account_gets_no_way_back_in(): void
    {
        User::query()->where('username', 'marvin.deocampo')->update(['active' => false]);

        $this->postJson('/api/password-resets', ['identifier' => 'marvin.deocampo'])
            ->assertNoContent();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Notification::assertNothingSent();
    }

    public function test_the_link_points_at_the_frontend_not_at_this_api(): void
    {
        $this->postJson('/api/password-resets', ['identifier' => 'marvin.deocampo']);

        $user = User::query()->where('username', 'marvin.deocampo')->first();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith(config('app.frontend_url').'/reset-password?', $url);
            $this->assertStringContainsString('token='.$notification->token, $url);
            $this->assertStringContainsString('email='.urlencode($user->email), $url);

            return true;
        });
    }

    public function test_redeeming_a_link_replaces_the_password(): void
    {
        $token = $this->requestTokenFor('marvin.deocampo');

        $this->postJson('/api/password-resets/redeem', [
            'token' => $token,
            'email' => 'marvin.deocampo@gmail.com',
            'password' => 'bagong-password',
        ])->assertNoContent();

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertStatus(401);

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'bagong-password',
        ])->assertOk();
    }

    public function test_a_link_can_only_be_spent_once(): void
    {
        $token = $this->requestTokenFor('marvin.deocampo');
        $body = [
            'token' => $token,
            'email' => 'marvin.deocampo@gmail.com',
            'password' => 'bagong-password',
        ];

        $this->postJson('/api/password-resets/redeem', $body)->assertNoContent();

        $this->postJson('/api/password-resets/redeem', $body)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This reset link has expired. Ask for a new one.');
    }

    public function test_a_made_up_token_is_refused(): void
    {
        $this->postJson('/api/password-resets/redeem', [
            'token' => 'not-a-real-token',
            'email' => 'marvin.deocampo@gmail.com',
            'password' => 'bagong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This reset link has expired. Ask for a new one.');
    }

    public function test_an_account_disabled_after_the_link_was_sent_cannot_redeem(): void
    {
        $token = $this->requestTokenFor('marvin.deocampo');
        User::query()->where('username', 'marvin.deocampo')->update(['active' => false]);

        $this->postJson('/api/password-resets/redeem', [
            'token' => $token,
            'email' => 'marvin.deocampo@gmail.com',
            'password' => 'bagong-password',
        ])->assertStatus(422);
    }

    public function test_a_short_password_is_refused_in_the_contract_shape(): void
    {
        $token = $this->requestTokenFor('marvin.deocampo');

        $this->postJson('/api/password-resets/redeem', [
            'token' => $token,
            'email' => 'marvin.deocampo@gmail.com',
            'password' => 'short',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check the highlighted fields.')
            ->assertJsonStructure(['fields' => ['password']]);
    }

    public function test_asking_for_links_is_throttled_before_it_becomes_a_mail_cannon(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/password-resets', ['identifier' => 'marvin.deocampo'])
                ->assertNoContent();
        }

        $this->postJson('/api/password-resets', ['identifier' => 'marvin.deocampo'])
            ->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    /** Asks for a link the way the frontend would, and reads the token back out of the mail. */
    private function requestTokenFor(string $username): string
    {
        $this->postJson('/api/password-resets', ['identifier' => $username])->assertNoContent();

        $token = null;
        Notification::assertSentTo(
            User::query()->where('username', $username)->first(),
            ResetPassword::class,
            function (ResetPassword $notification) use (&$token) {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }
}
