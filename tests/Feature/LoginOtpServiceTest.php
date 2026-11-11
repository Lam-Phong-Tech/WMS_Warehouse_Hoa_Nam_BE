<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Support\LoginOtpService;
use Tests\TestCase;
use Tests\TestCredentials;

class LoginOtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $suffix = 'login'): User
    {
        return User::create([
            'username' => $suffix.'_'.uniqid(),
            'email' => $suffix.'_'.uniqid().'@example.test',
            'display_name' => 'Login Security Test',
            'password_hash' => Hash::make(TestCredentials::PASSWORD),
            'status' => 'ACTIVE',
            'version' => 1,
            'must_change_password' => false,
        ]);
    }

    public function test_issue_verify_consume_and_attempt_limit(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $service = app(LoginOtpService::class);

        $issued = $service->issue($user, 'DEVICE', '10.0.0.5');
        Mail::assertSent(LoginOtpMail::class);

        $this->assertFalse($service->verify($issued['otp_id'], '000000'));
        $this->assertTrue($service->verify($issued['otp_id'], $issued['code']));
        $service->consume($issued['otp_id']);
        $this->assertFalse($service->verify($issued['otp_id'], $issued['code']));
    }
}
