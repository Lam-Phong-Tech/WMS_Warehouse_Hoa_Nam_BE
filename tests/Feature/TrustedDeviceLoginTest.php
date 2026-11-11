<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use Modules\Auth\Support\LoginThrottle;
use Modules\Auth\Support\TrustedDeviceService;
use RuntimeException;
use Tests\TestCase;
use Tests\TestCredentials;

class TrustedDeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['login_security.trusted_device.enabled' => true]);
    }

    public function test_unknown_device_requires_email_otp_and_trusted_device_skips_it(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
        $first->assertOk()->assertJsonPath('data.device_verification_required', true)->assertJsonMissingPath('data.access_token');
        $token = $first->json('data.device_token');
        $code = Mail::sent(LoginOtpMail::class)->first()->otp;

        $verified = $this->postJson('/api/v1/auth/device/verify', ['device_token' => $token, 'code' => $code]);
        $verified->assertOk()->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
        $cookieName = app(TrustedDeviceService::class)->cookieName($user->email);
        $trustCookie = $verified->getCookie($cookieName);

        $second = $this->withCookie($trustCookie->getName(), $trustCookie->getValue())
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD]);
        $second->assertOk()->assertJsonMissingPath('data.device_verification_required');
    }

    public function test_trusted_device_bypasses_pair_and_account_backoff(): void
    {
        config(['login_security.backoff.free_attempts' => 0, 'login_security.backoff.delays' => [60]]);
        Cache::flush();
        $user = $this->makeUser('bypass');
        $installationId = (string) Str::uuid();
        DB::table('auth_client_installations')->insert([
            'id' => $installationId,
            'user_id' => $user->user_id,
            'client_device_id' => (string) Str::uuid(),
            'device_label' => 'Test browser',
            'platform' => 'Windows',
            'client_type' => 'web',
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
        ]);
        $trust = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
        app(LoginThrottle::class)->registerFailure($user->email, '127.0.0.1');

        $this->withCookie($trust['cookie']->getName(), $trust['cookie']->getValue())
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.access_token', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_mfa_user_gets_mfa_challenge_not_device_otp(): void
    {
        Mail::fake();
        $user = $this->makeUser('mfa');
        UserMfa::query()->create([
            'user_id' => $user->user_id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'confirmed_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.mfa_required', true)
            ->assertJsonMissingPath('data.device_verification_required');
        Mail::assertNothingSent();
    }

    public function test_two_users_on_the_same_browser_use_different_cookie_names(): void
    {
        Mail::fake();
        $a = $this->makeUser('a');
        $b = $this->makeUser('b');
        $service = app(TrustedDeviceService::class);
        $this->assertNotSame($service->cookieName($a->email), $service->cookieName($b->email));

        $installationId = (string) Str::uuid();
        DB::table('auth_client_installations')->insert([
            'id' => $installationId,
            'user_id' => $a->user_id,
            'client_device_id' => (string) Str::uuid(),
            'device_label' => 'Shared scanner',
            'platform' => 'Windows',
            'client_type' => 'web',
            'first_seen_at' => now('UTC'),
            'last_seen_at' => now('UTC'),
        ]);
        $trust = $service->trust($a, $installationId, Request::create('/'));
        $this->withCookie($trust['cookie']->getName(), $trust['cookie']->getValue())
            ->postJson('/api/v1/auth/login', ['email' => $b->email, 'password' => TestCredentials::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.device_verification_required', true);
    }

    public function test_email_delivery_failure_fails_closed_without_tokens(): void
    {
        $user = $this->makeUser('smtp');
        Mail::shouldReceive('to->send')->andThrow(new RuntimeException('smtp down'));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => TestCredentials::PASSWORD])
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'EMAIL_DELIVERY_UNAVAILABLE');
        $this->assertDatabaseMissing('refresh_tokens', ['user_id' => $user->user_id]);
    }

    private function makeUser(string $suffix = 'trustlogin'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Trusted Login User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
