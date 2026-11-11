<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\TestCase;
use Tests\TestCredentials;

class LoginPowLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pow_is_required_only_after_the_trigger_and_valid_solution_proceeds(): void
    {
        config([
            'login_security.pow.enabled' => true,
            'login_security.pow.trigger_failures' => 1,
            'login_security.pow.base_difficulty' => 4,
            'login_security.backoff.free_attempts' => 0,
            'login_security.backoff.delays' => [1],
        ]);
        Cache::flush();
        $user = $this->makeUser();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(2));

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => TestCredentials::PASSWORD,
        ])->assertStatus(428)->assertJsonPath('error_code', 'POW_REQUIRED')
            ->json('error.details.challenge');

        $solution = 0;
        while (! str_starts_with(bin2hex(hash('sha256', $challenge['nonce'].':'.$solution, true)), '0')) {
            $solution++;
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => TestCredentials::PASSWORD,
            'pow' => ['challenge_id' => $challenge['challenge_id'], 'solution' => $solution],
        ])->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
    }

    private function makeUser(string $suffix = 'pow'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'PoW Login User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
