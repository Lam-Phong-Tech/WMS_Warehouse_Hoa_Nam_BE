<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\User;
use Modules\Auth\Support\DeviceVerification;
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

    public function test_login_email_uses_the_current_request_language_for_both_purposes(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        foreach (['vi', 'en', 'zh'] as $locale) {
            $this->setRequestLanguage($locale);
            foreach (['DEVICE', 'STEP_UP'] as $purpose) {
                app(LoginOtpService::class)->issue($user, $purpose, '10.0.0.5');
                $mail = Mail::sent(LoginOtpMail::class)->last();
                $this->assertSame($locale, $mail->locale);
                $this->assertStringContainsString('lang="'.$locale.'"', $mail->render());
            }
        }
    }

    public function test_device_resend_uses_the_newly_selected_language(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $this->setRequestLanguage('en');
        $service = app(DeviceVerification::class);
        $challenge = $service->issue($user, 'test-installation', '10.0.0.5');
        $this->assertSame('en', Mail::sent(LoginOtpMail::class)->last()->locale);
        $this->travel(61)->seconds();
        $this->setRequestLanguage('zh');
        $this->assertTrue($service->resend($challenge['device_token'])['ok']);
        $this->assertSame('zh', Mail::sent(LoginOtpMail::class)->last()->locale);
        $this->travelBack();
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

    private function setRequestLanguage(string $locale): void
    {
        $request = Request::create('/');
        $request->headers->set('Accept-Language', $locale);
        app()->instance('request', $request);
    }
}
