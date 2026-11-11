<?php

namespace Tests\Unit;

use App\Support\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Models\UserMfa;
use Modules\Auth\Support\LoginStepUp;
use Tests\TestCase;
use Tests\TestCredentials;

class LoginStepUpTest extends TestCase
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

    public function test_step_up_uses_mfa_when_enabled_and_email_otherwise(): void
    {
        $mfaUser = $this->makeUser('mfa');
        UserMfa::query()->create([
            'user_id' => $mfaUser->user_id,
            'secret' => Totp::generateSecret(),
            'confirmed_at' => now(),
        ]);
        $mfa = app(LoginStepUp::class)->issue($mfaUser, '10.0.0.5');
        $this->assertSame('mfa', $mfa['method']);

        $plain = $this->makeUser('plain');
        Mail::fake();
        $email = app(LoginStepUp::class)->issue($plain, '10.0.0.5');
        $this->assertSame('email', $email['method']);
        Mail::assertSent(LoginOtpMail::class);
    }

    public function test_password_attempts_are_limited_to_three(): void
    {
        $user = $this->makeUser();
        Mail::fake();
        $issued = app(LoginStepUp::class)->issue($user, '10.0.0.5');
        $this->assertSame(3, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
        $this->assertSame(2, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
        $this->assertSame(1, app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['attempts_left']);
        $this->assertFalse(app(LoginStepUp::class)->consumePasswordAttempt($issued['token'])['ok']);
    }
}
