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
        $locale = $this->locale ?? 'vi';
        $copy = trans('auth_mail.password_reset', [], $locale);
        $expires = trans('auth_mail.expires', ['minutes' => $ttl], $locale);

        return $this->subject($copy['subject'])
            ->html(<<<HTML
                <div lang="{$locale}" style="font-family: Arial, sans-serif; max-width: 480px; margin: 0 auto;">
                    <h2 style="color: #1f2937;">Hoa Nam WMS</h2>
                    <p>{$copy['intro']}</p>
                    <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1d4ed8; margin: 24px 0;">
                        {$safeOtp}
                    </p>
                    <p>{$expires}</p>
                    <p>{$copy['advice']}</p>
                </div>
                HTML);
    }
}
