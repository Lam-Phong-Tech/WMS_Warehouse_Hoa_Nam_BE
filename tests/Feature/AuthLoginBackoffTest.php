<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\LoginThrottle;
use Tests\TestCase;
use Tests\TestCredentials;

class AuthLoginBackoffTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_five_cap_cycles_require_step_up_before_tokens(): void
    {
        Mail::fake();
        config([
            'login_security.backoff.free_attempts' => 0,
            'login_security.backoff.delays' => [900],
            'login_security.backoff.cap_seconds' => 900,
            'login_security.backoff.step_up_after_cycles' => 1,
            'login_security.backoff.counter_ttl_seconds' => 86400,
        ]);
        Cache::flush();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(901));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()
            ->assertJsonPath('data.step_up_required', true)
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_successful_login_clears_the_throttle(): void
    {
        config(['login_security.backoff.free_attempts' => 0, 'login_security.backoff.delays' => [1]]);
        Cache::flush();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(2));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk();
        $this->assertSame(0, app(LoginThrottle::class)->retryAfter($user->email, '127.0.0.1'));
    }

    private function makeUser(string $suffix = 'backoff'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Backoff Test User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
