<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $purpose = 'DEVICE',
        public readonly int $ttlMinutes = 5,
    ) {}

    public function build(): self
    {
        $safeOtp = e($this->otp);
        $ttl = (int) $this->ttlMinutes;
        $subject = $this->purpose === 'STEP_UP'
            ? 'Mã xác minh bảo mật đăng nhập - Hoa Nam WMS'
            : 'Mã xác minh thiết bị đăng nhập mới - Hoa Nam WMS';

        return $this->subject($subject)
            ->html(<<<HTML
                <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;">
                    <h2 style="color: #1f2937;">Hoa Nam WMS</h2>
                    <p>Mã xác minh đăng nhập của bạn là:</p>
                    <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1d4ed8; margin: 24px 0;">
                        {$safeOtp}
                    </p>
                    <p>Mã có hiệu lực trong <strong>{$ttl} phút</strong> kể từ khi gửi.</p>
                    <p>Nếu không phải bạn, hãy đổi mật khẩu và liên hệ quản trị viên.</p>
                </div>
                HTML);
    }
}
