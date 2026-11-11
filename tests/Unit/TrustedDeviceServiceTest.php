<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\TrustedDeviceService;
use Tests\TestCase;
use Tests\TestCredentials;

class TrustedDeviceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_trust_token_is_hashed_expires_and_is_per_user(): void
    {
        $user = $this->makeUser();
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
        $issued = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
        $this->assertNotSame($issued['raw_token'], $issued['model']->token_hash);
        $this->assertSame(hash('sha256', $issued['raw_token']), $issued['model']->token_hash);
        $this->assertNotSame(
            app(TrustedDeviceService::class)->cookieName('a@example.test'),
            app(TrustedDeviceService::class)->cookieName('b@example.test'),
        );
        Carbon::setTestNow(now()->addDays(31));
        $this->assertNull(app(TrustedDeviceService::class)->findValid($user->email, $issued['raw_token']));
    }

    public function test_privileged_accounts_get_a_seven_day_trust_ttl(): void
    {
        $user = $this->makeUser('priv');
        $this->mock(MfaService::class, fn ($mock) => $mock->shouldReceive('isPrivileged')->with($user)->andReturn(true));
        $this->assertSame(7, app(TrustedDeviceService::class)->ttlDaysFor($user));
    }

    public function test_trust_cookie_is_http_only_secure_and_rotates_with_grace(): void
    {
        $user = $this->makeUser('rotate');
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
        $issued = app(TrustedDeviceService::class)->trust($user, $installationId, Request::create('/'));
        $this->assertTrue($issued['cookie']->isHttpOnly());
        $this->assertTrue($issued['cookie']->isSecure());
        $this->assertSame('lax', $issued['cookie']->getSameSite());

        Carbon::setTestNow(now()->addDays(8));
        $rotated = app(TrustedDeviceService::class)->rotateIfNeeded($issued['model']);
        $this->assertNotNull($rotated);
        $this->assertNotNull($issued['model']->fresh()->previous_hash);
    }

    private function makeUser(string $suffix = 'trust'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Trusted Device User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
