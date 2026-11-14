<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Mail\PasswordResetOtpMail;
use Modules\Auth\Support\AuthMailLocale;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthMailLocalizationTest extends TestCase
{
    public static function locales(): array
    {
        return [
            ['vi', 'Mã có hiệu lực trong', '7 phút', 'Mã xác thực đặt lại mật khẩu', 'Mã xác minh thiết bị đăng nhập mới', 'Mã xác minh bảo mật đăng nhập'],
            ['en', 'This code is valid for', '7 minutes', 'Password reset verification code', 'New device verification code', 'Sign-in security verification code'],
            ['zh', '此验证码自发送起', '7 分钟', '重置密码验证码', '新设备登录验证码', '登录安全验证码'],
        ];
    }

    #[DataProvider('locales')]
    public function test_subject_and_body_follow_the_mail_locale_even_after_serialization(
        string $locale, string $expiry, string $duration, string $resetSubject, string $deviceSubject, string $stepUpSubject,
    ): void {
        foreach ([
            [(new PasswordResetOtpMail('123456', 7))->locale($locale), $resetSubject],
            [(new LoginOtpMail('123456', 'DEVICE', 7))->locale($locale), $deviceSubject],
            [(new LoginOtpMail('123456', 'STEP_UP', 7))->locale($locale), $stepUpSubject],
        ] as [$mail, $subject]) {
            $queued = unserialize(serialize($mail));
            app()->setLocale('vi');
            $html = $queued->render();
            $this->assertStringContainsString($subject, $queued->subject);
            $this->assertStringContainsString($expiry, $html);
            $this->assertStringContainsString($duration, $html);
            $this->assertStringContainsString('123456', $html);
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            if ($locale !== 'vi') {
                $this->assertStringNotContainsString('Mã có hiệu lực', $html);
                $this->assertStringNotContainsString('Nếu bạn', $html);
                $this->assertStringNotContainsString('Nếu ', $html);
                $this->assertStringNotContainsString('Bạn ', $html);
                $this->assertStringNotContainsString('Mã ', $html);
            }
            $this->assertSame('vi', app()->getLocale());
        }
    }

    public function test_request_locale_negotiation_and_vietnamese_default(): void
    {
        foreach ([
            '' => 'vi',
            'vi-VN' => 'vi',
            'en-US,en;q=0.9' => 'en',
            'zh-CN,zh;q=0.9' => 'zh',
            'en;q=0.2,zh;q=0.9' => 'zh',
            'fr-FR' => 'vi',
        ] as $header => $expected) {
            $request = Request::create('/');
            $request->headers->set('Accept-Language', $header);
            $this->assertSame($expected, AuthMailLocale::fromRequest($request), $header);
        }
    }

    public function test_callers_without_a_locale_still_receive_vietnamese_and_otp_is_escaped(): void
    {
        $mail = new PasswordResetOtpMail('<code>', 5);
        $html = $mail->render();
        $this->assertStringContainsString('Mã xác thực đặt lại mật khẩu', $mail->subject);
        $this->assertStringContainsString('&lt;code&gt;', $html);
        $this->assertStringNotContainsString('<code>', $html);
    }
}
