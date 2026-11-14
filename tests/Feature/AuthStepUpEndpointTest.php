<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Support\LoginThrottle;
use Tests\TestCase;
use Tests\TestCredentials;

class AuthStepUpEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_step_up_verify_then_login_issues_tokens_and_clears_state(): void
    {
        config([
            'login_security.backoff.free_attempts' => 0,
            'login_security.backoff.delays' => [900],
            'login_security.backoff.cap_seconds' => 900,
            'login_security.backoff.step_up_after_cycles' => 1,
        ]);
        Cache::flush();
        Mail::fake();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(901));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()->assertJsonPath('data.step_up_required', true)->json('data.step_up_token');
        $code = Mail::sent(LoginOtpMail::class)->first()->otp;

        $this->postJson('/api/v1/auth/step-up/verify', ['step_up_token' => $token, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.step_up_verified', true)
            ->assertJsonPath('data.password_attempts_left', 3)
            ->assertJsonMissingPath('data.access_token');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => TestCredentials::PASSWORD, 'step_up_token' => $token,
        ])->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
        $this->assertSame(0, app(LoginThrottle::class)->retryAfter($user->email, '127.0.0.1'));
    }

    public function test_five_wrong_step_up_codes_lock_the_challenge(): void
    {
        $user = $this->makeUser('lock');
        $token = $this->startStepUp($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/step-up/verify', ['step_up_token' => $token, 'code' => '000000'])
                ->assertStatus($i === 4 ? 423 : 422);
        }
    }

    public function test_resend_is_rate_limited(): void
    {
        $user = $this->makeUser('resend');
        $token = $this->startStepUp($user);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/auth/step-up/resend', ['step_up_token' => $token])->assertOk();
        $this->postJson('/api/v1/auth/step-up/resend', ['step_up_token' => $token])->assertStatus(429);
    }

    public function test_step_up_email_and_resend_follow_each_request_language(): void
    {
        $this->withHeader('Accept-Language', 'en');
        $token = $this->startStepUp($this->makeUser('localized'));
        $this->assertSame('en', Mail::sent(LoginOtpMail::class)->last()->locale);
        Carbon::setTestNow(now()->addSeconds(61));
        $this->withHeader('Accept-Language', 'zh')
            ->postJson('/api/v1/auth/step-up/resend', ['step_up_token' => $token])->assertOk();
        $this->assertSame('zh', Mail::sent(LoginOtpMail::class)->last()->locale);
    }

    private function startStepUp(User $user): string
    {
        config([
            'login_security.backoff.free_attempts' => 0,
            'login_security.backoff.delays' => [900],
            'login_security.backoff.cap_seconds' => 900,
            'login_security.backoff.step_up_after_cycles' => 1,
        ]);
        Cache::flush();
        Mail::fake();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
        Carbon::setTestNow(now()->addSeconds(901));

        return (string) $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertOk()->json('data.step_up_token');
    }

    private function makeUser(string $suffix = 'stepup'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'StepUp Test User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
