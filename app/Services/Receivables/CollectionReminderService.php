<?php

declare(strict_types=1);

namespace App\Services\Receivables;

use App\Jobs\SendCollectionReminderJob;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Reminders and statements e-mailed to a company's customers through the queue (with retries). Texts come from the firm's templates (defaults are
 * created once and can be edited), placeholders are filled from the real balances, and every send is logged with
 * who, when, which template, the recipient and whether delivery succeeded.
 */
class CollectionReminderService
{
    public const KINDS = ['upcoming' => 'Próximo vencimiento', 'overdue' => 'Documento vencido', 'promise' => 'Promesa próxima', 'broken_promise' => 'Promesa incumplida', 'statement' => 'Estado de cuenta'];

    private const DEFAULTS = [
        'upcoming' => ['Documentos por vencer', "Estimado(a) {cliente}:\n\nLe recordamos que los siguientes documentos de {empresa} vencen próximamente:\n{documentos}\n\nSaldo total: {saldo_total}.\n\nSi ya realizó el pago, por favor ignore este mensaje."],
        'overdue' => ['Documentos vencidos', "Estimado(a) {cliente}:\n\nSegún nuestros registros, tiene documentos vencidos con {empresa}:\n{documentos}\n\nSaldo vencido: {saldo_vencido}. Le agradeceremos confirmar la fecha de pago."],
        'promise' => ['Recordatorio de promesa de pago', "Estimado(a) {cliente}:\n\nLe recordamos su compromiso de pago por {monto_promesa} para el {fecha_promesa} con {empresa}.\n\nSaldo total: {saldo_total}."],
        'broken_promise' => ['Promesa de pago pendiente', "Estimado(a) {cliente}:\n\nNo hemos recibido el pago comprometido por {monto_promesa} para el {fecha_promesa}. Le agradeceremos comunicarse con {empresa}.\n\nSaldo vencido: {saldo_vencido}."],
        'statement' => ['Estado de cuenta', "Estimado(a) {cliente}:\n\nAdjuntamos su estado de cuenta con {empresa} del {desde} al {hasta}.\n\nSaldo a la fecha: {saldo_total}."],
    ];

    public function __construct(
        private AccountingService $accounting,
        private ReceivableService $receivables,
    ) {}

    /** @return list<array{id: int, kind: string, kind_label: string, name: string, subject: string, body: string}> */
    public function templates(Organization $organization): array
    {
        foreach (self::DEFAULTS as $kind => [$subject, $body]) {
            DB::table('collection_reminder_templates')->insertOrIgnore(['organization_id' => $organization->id, 'kind' => $kind, 'name' => 'Predeterminada', 'subject' => $subject, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }

        return array_values(DB::table('collection_reminder_templates')->where('collection_reminder_templates.organization_id', $organization->id)->where('is_active', true)->orderBy('kind')->orderBy('name')->get()
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'kind' => (string) $row->kind, 'kind_label' => self::KINDS[$row->kind] ?? $row->kind, 'name' => (string) $row->name, 'subject' => (string) $row->subject, 'body' => (string) $row->body])->all());
    }

    /**
     * @param  array{from?: string, to?: string}  $range
     */
    public function send(Organization $organization, User $actor, int $companyId, int $customerId, int $templateId, ?string $attachment = null, array $range = []): int
    {
        $customer = DB::table('sales_customers')->where('id', $customerId)->where('company_id', $companyId)->first(['id', 'name', 'email']);
        if ($customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente no pertenece a la empresa.']);
        }
        if ($customer->email === null || ! filter_var($customer->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['customer_id' => 'El cliente no tiene un correo válido registrado en Ventas.']);
        }
        $template = DB::table('collection_reminder_templates')->where('collection_reminder_templates.organization_id', $organization->id)->where('id', $templateId)->where('is_active', true)->first();
        if ($template === null) {
            throw ValidationException::withMessages(['template_id' => 'Elige una plantilla activa.']);
        }
        $company = DB::table('companies')->where('id', $companyId)->first(['name', 'office_id']);
        $documents = collect($this->receivables->documents([$companyId], ['customer_id' => $customerId]));
        $promise = DB::table('promises_to_pay')->where('company_id', $companyId)->where('sales_customer_id', $customerId)->whereIn('status', ['pending', 'partially_fulfilled', 'broken'])->orderByDesc('promised_date')->first(['amount_cents', 'promised_date']);
        $money = fn (int $cents): string => 'Q '.number_format($cents / 100, 2);
        $values = [
            '{cliente}' => (string) $customer->name,
            '{empresa}' => (string) $company?->name,
            '{saldo_total}' => $money((int) $documents->sum('outstanding_cents')),
            '{saldo_vencido}' => $money((int) $documents->where('overdue', true)->sum('outstanding_cents')),
            '{documentos}' => $documents->map(fn (array $row): string => '- '.$row['document'].' · vence '.Carbon::parse($row['due_date'])->format('d/m/Y').' · '.$money($row['outstanding_cents']))->implode("\n") ?: '- Sin documentos pendientes',
            '{monto_promesa}' => $promise === null ? '—' : $money((int) $promise->amount_cents),
            '{fecha_promesa}' => $promise === null ? '—' : Carbon::parse($promise->promised_date)->format('d/m/Y'),
            '{desde}' => isset($range['from']) ? Carbon::parse($range['from'])->format('d/m/Y') : '—',
            '{hasta}' => isset($range['to']) ? Carbon::parse($range['to'])->format('d/m/Y') : '—',
        ];
        $subject = strtr((string) $template->subject, $values);
        $body = strtr((string) $template->body, $values);
        $id = DB::table('collection_reminders')->insertGetId([
            'organization_id' => $organization->id, 'office_id' => $company?->office_id, 'company_id' => $companyId, 'sales_customer_id' => $customerId, 'collection_reminder_template_id' => $template->id,
            'kind' => $template->kind, 'channel' => 'email', 'recipient' => $customer->email, 'subject' => $subject, 'body' => $body, 'status' => 'queued', 'sent_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = null;
        if ($attachment !== null) {
            $path = 'collection-reminders/'.$organization->id.'/'.$id.'.pdf';
            Storage::disk('local')->put($path, $attachment);
        }
        try {
            SendCollectionReminderJob::dispatch($id, $path);
        } catch (Throwable $exception) {
            // Only a synchronous queue surfaces delivery errors here; the queued job records them itself.
            DB::table('collection_reminders')->where('id', $id)->where('status', '!=', 'sent')->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
        }
        $this->accounting->audit($organization, $actor, $template->kind === 'statement' ? 'statement.sent' : 'reminder.sent', 'collection_reminders', $id, ['customer' => $customer->name, 'recipient' => $customer->email, 'template' => $template->name], $company === null ? null : (int) $company->office_id);

        return $id;
    }

    public function retry(Organization $organization, User $actor, int $reminderId): void
    {
        $reminder = DB::table('collection_reminders')->where('collection_reminders.organization_id', $organization->id)->where('id', $reminderId)->first(['id', 'status', 'kind', 'office_id']);
        abort_if($reminder === null, 404);
        if ($reminder->status !== 'failed') {
            throw ValidationException::withMessages(['reminder' => 'Solo se reintentan envíos fallidos.']);
        }
        DB::table('collection_reminders')->where('id', $reminderId)->update(['status' => 'queued', 'error' => null, 'updated_at' => now()]);
        $path = 'collection-reminders/'.$organization->id.'/'.$reminderId.'.pdf';
        try {
            SendCollectionReminderJob::dispatch($reminderId, Storage::disk('local')->exists($path) ? $path : null);
        } catch (Throwable $exception) {
            DB::table('collection_reminders')->where('id', $reminderId)->where('status', '!=', 'sent')->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
        }
        $this->accounting->audit($organization, $actor, $reminder->kind === 'statement' ? 'statement.resent' : 'reminder.resent', 'collection_reminders', $reminderId, [], $reminder->office_id === null ? null : (int) $reminder->office_id);
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array<string, mixed>>
     */
    public function history(array $companyIds, ?int $customerId = null, int $limit = 100): array
    {
        return array_values(DB::table('collection_reminders as reminder')->join('sales_customers as customer', 'customer.id', '=', 'reminder.sales_customer_id')->join('users', 'users.id', '=', 'reminder.sent_by')
            ->whereIn('reminder.company_id', $companyIds === [] ? [-1] : $companyIds)->when($customerId !== null, fn ($query) => $query->where('reminder.sales_customer_id', $customerId))
            ->orderByDesc('reminder.id')->limit($limit)->get(['reminder.*', 'customer.name as customer_name', 'users.name as sender'])
            ->map(fn (stdClass $row): array => ['id' => (int) $row->id, 'customer' => (string) $row->customer_name, 'kind' => self::KINDS[$row->kind] ?? $row->kind, 'recipient' => (string) $row->recipient, 'subject' => (string) $row->subject,
                'status' => (string) $row->status, 'status_label' => ['queued' => 'En cola', 'sent' => 'Enviado', 'failed' => 'Falló'][$row->status] ?? $row->status, 'error' => $row->error, 'sender' => (string) $row->sender,
                'at' => Carbon::parse($row->created_at)->toIso8601String()])->all());
    }
}
