<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitation extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance. The owner of a firm onboarded from the Control Center gets an activation
     * message instead of the collaborator one.
     */
    public function __construct(
        public string $organizationName,
        public string $invitationUrl,
        public ?CarbonInterface $expiresAt = null,
        public bool $asOwner = false,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->asOwner
            ? 'Activa tu cuenta de '.$this->organizationName.' | Atlantia Finance'
            : 'Te invitaron a '.$this->organizationName.' | Atlantia Finance';

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.workspace-invitation', [
                'organizationName' => $this->organizationName,
                'invitationUrl' => $this->invitationUrl,
                'asOwner' => $this->asOwner,
                'validity' => $this->validity(),
            ]);
    }

    /**
     * How long the link stays valid, in words.
     */
    public function validity(): string
    {
        $days = $this->expiresAt === null ? 3 : max(1, (int) round(now()->diffInHours($this->expiresAt) / 24));

        return $days === 1 ? '24 horas' : $days.' días';
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
