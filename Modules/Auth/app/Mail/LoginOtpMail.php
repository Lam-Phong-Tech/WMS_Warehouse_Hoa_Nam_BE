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
        $locale = $this->locale ?? 'vi';
        $copy = trans('auth_mail.login', [], $locale);
        $expires = trans('auth_mail.expires', ['minutes' => $ttl], $locale);
        $subject = $this->purpose === 'STEP_UP'
            ? $copy['step_up_subject']
            : $copy['device_subject'];

        return $this->subject($subject)
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
