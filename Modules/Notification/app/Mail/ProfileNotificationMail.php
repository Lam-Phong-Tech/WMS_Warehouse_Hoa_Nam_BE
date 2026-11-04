<?php

namespace Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Opt-in notification mail. No credentials, request headers or access tokens are included. */
class ProfileNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $notificationTitle, public readonly string $notificationBody)
    {
        $this->afterCommit();
    }

    public function build(): static
    {
        return $this->subject($this->notificationTitle)->text('notification::profile-mail');
    }
}
