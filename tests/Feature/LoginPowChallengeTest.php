<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPowChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pow_challenge_endpoint_returns_challenge_shape(): void
    {
        config(['login_security.pow.enabled' => true]);

        $this->postJson('/api/v1/auth/login/pow-challenge', ['email' => 'user@example.test'])
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'nonce',
                    'difficulty',
                    'algorithm',
                    'expires_in',
                ],
            ]);
    }
}
