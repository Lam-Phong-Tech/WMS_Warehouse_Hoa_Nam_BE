<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Support\DeviceVerification;
use Tests\TestCase;
use Tests\TestCredentials;

class DeviceVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_challenge_verifies_email_otp_and_returns_installation(): void
    {
        Mail::fake();
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
        $issued = app(DeviceVerification::class)->issue($user, $installationId, '10.0.0.5');
        $this->assertStringContainsString('***', $issued['masked_email']);
        $code = Mail::sent(LoginOtpMail::class)->first()->otp;
        $result = app(DeviceVerification::class)->verify($issued['device_token'], $code);
        $this->assertTrue($result['ok']);
        $this->assertSame($installationId, $result['installation_id']);
    }

    private function makeUser(string $suffix = 'devverify'): User
    {
        return User::query()->create([
            'user_id' => (string) Str::uuid(),
            'username' => "user_{$suffix}_".Str::lower(Str::random(6)),
            'email' => "user.{$suffix}.".Str::lower(Str::random(6)).'@example.test',
            'display_name' => 'Device Verify User',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'must_change_password' => false,
        ]);
    }
}
