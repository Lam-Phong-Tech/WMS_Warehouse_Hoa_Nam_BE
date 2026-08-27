<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Dựng HTML trực tiếp trong build() thay vì view blade riêng — module này
 * chưa đăng ký view namespace nào và đây là email nội bộ đơn giản, không đáng
 * để mở thêm hạ tầng view cho module Auth chỉ vì 1 email.
 */
class PasswordResetOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $otp, public readonly int $ttlMinutes)
    {
    }

    public function build(): self
    {
        $safeOtp = e($this->otp);
        $ttl = (int) $this->ttlMinutes;

        return $this->subject('Mã xác thực đặt lại mật khẩu - Hoa Nam WMS')
            ->html(<<<HTML
                <div style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;">
                    <h2 style="color: #1f2937;">Hoa Nam WMS</h2>
                    <p>Bạn (hoặc ai đó) vừa yêu cầu đặt lại mật khẩu cho tài khoản này.</p>
                    <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1d4ed8; margin: 24px 0;">
                        {$safeOtp}
                    </p>
                    <p>Mã có hiệu lực trong <strong>{$ttl} phút</strong> kể từ khi gửi.</p>
                    <p>Nếu bạn không yêu cầu việc này, vui lòng bỏ qua email và không chia sẻ mã cho bất kỳ ai.</p>
                </div>
                HTML);
    }
}
