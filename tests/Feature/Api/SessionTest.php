<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_anonymous_session_answers_nulls_not_an_error(): void
    {
        $this->getJson('/api/session')
            ->assertOk()
            ->assertExactJson(['manager' => null, 'owner' => null]);
    }

    public function test_manager_signs_in_with_username_case_insensitively(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'MARVIN.DEOCAMPO',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('manager.username', 'marvin.deocampo')
            ->assertJsonPath('manager.storeId', 'arevalo')
            ->assertJsonPath('owner', null);
    }

    public function test_owner_signs_in_by_username(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'twowheelszone',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('owner.username', 'twowheelszone')
            ->assertJsonPath('manager', null);
    }

    public function test_an_email_address_is_not_a_way_in(): void
    {
        // Accounts have no email at all now — an address is just a wrong username
        $this->postJson('/api/session', [
            'identifier' => 'owner@gmail.com',
            'password' => 'password',
        ])->assertStatus(401);
    }

    public function test_the_session_payload_carries_no_email(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonMissingPath('manager.email');
    }

    public function test_wrong_credentials_get_one_message_for_both_cases(): void
    {
        $wrongPassword = $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'not-the-password',
        ]);
        $unknownUser = $this->postJson('/api/session', [
            'identifier' => 'who.is.this',
            'password' => 'password',
        ]);

        // Same message and status either way — the form must not leak accounts
        $wrongPassword->assertStatus(401)->assertJsonPath('message', 'That username and password do not match.');
        $unknownUser->assertStatus(401)->assertJsonPath('message', 'That username and password do not match.');
    }

    public function test_validation_errors_use_the_contract_shape(): void
    {
        $this->postJson('/api/session', ['identifier' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Check the highlighted fields.')
            ->assertJsonStructure(['fields' => ['password']]);
    }

    public function test_login_throttles_after_five_failures(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/session', [
                'identifier' => 'marvin.deocampo',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        // The sixth attempt is refused even with the RIGHT password
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertStatus(429);

        // A different identifier from the same place is not collateral damage
        $this->postJson('/api/session', [
            'identifier' => 'joel.sarabia',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_successful_sign_in_clears_the_failure_count(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/session', [
                'identifier' => 'marvin.deocampo',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertOk();

        // The slate is clean again — four more failures fit before a pause
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_sign_out_ends_the_session(): void
    {
        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertOk();

        $this->deleteJson('/api/session')->assertNoContent();
        $this->getJson('/api/session')->assertExactJson(['manager' => null, 'owner' => null]);
    }

    public function test_stores_requires_a_session_and_answers_the_contract_shape(): void
    {
        $this->getJson('/api/stores')->assertStatus(401)->assertJsonStructure(['message']);

        $this->postJson('/api/session', [
            'identifier' => 'marvin.deocampo',
            'password' => 'password',
        ])->assertOk();

        $this->getJson('/api/stores')
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonFragment(['id' => 'arevalo', 'name' => 'Arevalo']);
    }
}
