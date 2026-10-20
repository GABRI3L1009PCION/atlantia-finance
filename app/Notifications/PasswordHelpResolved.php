<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Answer to a "forgot my password" request: the temporary password when approved, or the reason when it was not. */
class PasswordHelpResolved extends Notification
{
    use Queueable;

    public function __construct(public bool $approved, public string $officeName, public ?string $temporaryPassword = null, public ?string $note = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (! $this->approved) {
            return (new MailMessage)
                ->subject('Tu solicitud de contraseña no fue aprobada | Atlantia Finance')
                ->greeting('Hola')
                ->line('Tu oficina ('.$this->officeName.') revisó tu solicitud para cambiar la contraseña y no la aprobó.')
                ->line($this->note !== null && $this->note !== '' ? 'Motivo: '.$this->note : 'Comunícate con tu oficina contable si necesitas más información.')
                ->line('Si no hiciste esta solicitud, puedes ignorar este correo: tu contraseña actual no cambió.');
        }

        return (new MailMessage)
            ->subject('Tu contraseña temporal | Atlantia Finance')
            ->greeting('Hola')
            ->line('Tu oficina ('.$this->officeName.') aprobó tu solicitud para cambiar la contraseña.')
            ->line('Tu contraseña temporal es: **'.$this->temporaryPassword.'**')
            ->line('Ingresa con tu correo y esta contraseña. El sistema te pedirá crear una contraseña nueva. La temporal vence en 7 días.')
            ->line('Si no hiciste esta solicitud, avisa de inmediato a tu oficina contable.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
