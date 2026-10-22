<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The mail with the six-digit code that confirms the address (see EmailVerificationCodeService). */
class VerifyEmailNotification extends Notification
{
    public function __construct(public string $code = '000000', public int $expiresInMinutes = 15) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu código de verificación | Atlantia Finance')
            ->view('emails.auth.verify-email', [
                'userName' => $notifiable->name,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
