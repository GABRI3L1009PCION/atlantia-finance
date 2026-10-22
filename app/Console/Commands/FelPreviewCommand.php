<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\FelPreviewMail;
use App\Services\Fel\FelPreviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class FelPreviewCommand extends Command
{
    protected $signature = 'fel:preview {email? : Correo al que se envía la vista previa} {--save= : Guarda el PDF en esta ruta en lugar de enviarlo} {--name= : Nombre del receptor} {--nit= : NIT del receptor} {--address= : Dirección del receptor}';

    protected $description = 'Genera una factura de ejemplo (sin validez fiscal) para comprobar cómo se ven las facturas enviadas, y la envía por correo o la guarda como PDF.';

    public function handle(FelPreviewService $preview): int
    {
        $email = (string) $this->argument('email');
        $save = (string) $this->option('save');
        if ($email === '' && $save === '') {
            $this->error('Indica un correo para enviarla o --save=ruta.pdf para guardarla.');

            return self::FAILURE;
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('El correo no es válido.');

            return self::FAILURE;
        }

        $pdf = $preview->pdf(array_filter(['name' => $this->option('name'), 'nit' => $this->option('nit'), 'address' => $this->option('address')], fn ($value): bool => is_string($value) && $value !== ''));

        if ($save !== '') {
            file_put_contents($save, $pdf);
            $this->info('PDF guardado en '.$save);
        }
        if ($email !== '') {
            Mail::to($email)->send(new FelPreviewMail($pdf));
            $this->info('Vista previa enviada a '.$email);
        }

        return self::SUCCESS;
    }
}
