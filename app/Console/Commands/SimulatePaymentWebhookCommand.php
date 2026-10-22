<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Tenancy\TenantDatabaseContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SimulatePaymentWebhookCommand extends Command
{
    protected $signature = 'billing:simulate-webhook {payment? : ID público del pago (por defecto, el último pendiente)} {--failed : Simula un pago rechazado en lugar de uno exitoso}';

    protected $description = 'Entrega al sistema un aviso de pago firmado como lo haría Recurrente, para probar la activación, la factura y el correo sin un túnel público. Solo en local.';

    public function handle(TenantDatabaseContext $tenancy): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Este comando solo se puede usar en un entorno local.');

            return self::FAILURE;
        }
        $secret = (string) config('services.recurrente.webhook_secret');
        if (! str_starts_with($secret, 'whsec_')) {
            $this->error('Falta RECURRENTE_WEBHOOK_SECRET (whsec_...) en el .env: es la clave con la que se firma el aviso.');

            return self::FAILURE;
        }

        $payment = $tenancy->runAsSystem(fn (): ?Payment => Payment::query()
            ->when($this->argument('payment'), fn ($query, $id) => $query->where('public_id', $id), fn ($query) => $query->where('status', 'pending'))
            ->whereHas('attempts', fn ($query) => $query->where('status', 'created'))
            ->with(['attempts' => fn ($query) => $query->where('status', 'created')->latest('attempted_at')])
            ->latest('id')->first());
        $checkoutId = $payment?->attempts->first()?->provider_attempt_id;
        if ($payment === null || $checkoutId === null) {
            $this->error('No hay un pago pendiente con checkout. Abre primero la pantalla de pago.');

            return self::FAILURE;
        }

        $failed = (bool) $this->option('failed');
        $body = (string) json_encode([
            'id' => 'pa_simulado_'.Str::lower(Str::random(12)),
            'event_type' => $failed ? 'payment_intent.failed' : 'payment_intent.succeeded',
            'amount_in_cents' => (int) bcmul((string) $payment->amount, '100', 0),
            'currency' => $payment->currency_code,
            'checkout' => ['id' => $checkoutId],
            'failure_message' => $failed ? 'Pago rechazado (simulación).' : null,
        ]);
        $messageId = 'msg_simulado_'.Str::lower(Str::random(16));
        $timestamp = (string) now()->getTimestamp();
        $key = (string) base64_decode(substr($secret, 6), true);
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "$messageId.$timestamp.$body", $key, true));

        $request = Request::create('/webhooks/recurrente', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $messageId,
            'HTTP_SVIX_TIMESTAMP' => $timestamp,
            'HTTP_SVIX_SIGNATURE' => $signature,
        ], content: $body);
        $response = app(Kernel::class)->handle($request);

        $this->line('Respuesta del sistema: HTTP '.$response->getStatusCode());
        $this->info(($failed ? 'Pago rechazado' : 'Pago exitoso').' simulado para '.$payment->public_id.'. Revisa el estado de la suscripción, la factura y el correo.');

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }
}
