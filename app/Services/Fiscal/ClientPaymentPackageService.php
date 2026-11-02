<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Mail\ClientReminderMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ClientPortal\ClientAccessService;
use App\Services\Notifications\ClientMailIdentityService;
use App\Services\WorkManagement\WorkNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * What the client has to pay to the SAT for a month: each obligation of that month with the amount the office
 * calculated, the access number of its payment form and its due date, plus the filing receipt once presented. The office
 * sends it as one message ("this month you pay Q X of IVA and Q Y of ISR before day Z") to the client's panel and email.
 *
 * @phpstan-type PackageItem array{id: int, name: string, reference_period: string, due_on: string, status: string, amount_cents: int|null, access_number: string|null, filing_reference: string|null, has_evidence: bool}
 */
class ClientPaymentPackageService
{
    private const SKIPPED = ['cancelled', 'not_applicable'];

    public function __construct(
        private AccountingService $accounting,
        private ClientAccessService $access,
        private WorkNotificationService $notifications,
        private ClientMailIdentityService $identities,
    ) {}

    /**
     * @return array{period: string, items: list<PackageItem>, total_cents: int, due_on: string|null, ready: bool, sent: array{sent_at: string, recipients: int, total_cents: int}|null}
     */
    public function package(int $companyId, int $year, int $month): array
    {
        $items = array_values(DB::table('fiscal_obligations')->where('company_id', $companyId)->where('reference_year', $year)->where('reference_month', $month)
            ->whereNotIn('status', self::SKIPPED)->orderBy('due_on')->orderBy('name')
            ->get(['id', 'name', 'reference_period', 'due_on', 'status', 'payment_amount_cents', 'payment_access_number', 'filing_reference', 'evidence_path'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id, 'name' => (string) $row->name, 'reference_period' => (string) $row->reference_period, 'due_on' => (string) $row->due_on,
                'status' => (string) $row->status, 'amount_cents' => $row->payment_amount_cents === null ? null : (int) $row->payment_amount_cents,
                'access_number' => $row->payment_access_number === null ? null : (string) $row->payment_access_number,
                'filing_reference' => $row->filing_reference === null ? null : (string) $row->filing_reference, 'has_evidence' => $row->evidence_path !== null,
            ])->all());
        $payable = array_values(array_filter($items, fn (array $item): bool => ($item['amount_cents'] ?? 0) > 0));
        $sent = DB::table('client_payment_packages')->where('company_id', $companyId)->where('period', sprintf('%04d-%02d', $year, $month))->first();

        return [
            'period' => sprintf('%04d-%02d', $year, $month), 'items' => $items,
            'total_cents' => array_sum(array_map(fn (array $item): int => (int) $item['amount_cents'], $payable)),
            'due_on' => $payable === [] ? null : min(array_column($payable, 'due_on')),
            'ready' => $items !== [] && collect($items)->every(fn (array $item): bool => $item['amount_cents'] !== null),
            'sent' => $sent === null ? null : ['sent_at' => CarbonImmutable::parse((string) $sent->sent_at)->toIso8601String(), 'recipients' => (int) $sent->recipients, 'total_cents' => (int) $sent->total_cents],
        ];
    }

    /** Records what the client pays for one obligation (0 when nothing is due) and the access number of its payment form. */
    public function setAmount(Organization $organization, User $user, int $companyId, int $obligationId, int $amountCents, ?string $accessNumber): void
    {
        $obligation = DB::table('fiscal_obligations')->where('organization_id', $organization->id)->where('company_id', $companyId)->where('id', $obligationId)->first();
        abort_if($obligation === null, 404);
        if ($amountCents < 0) {
            throw ValidationException::withMessages(['amount' => 'El monto no puede ser negativo.']);
        }
        DB::table('fiscal_obligations')->where('id', $obligationId)->update(['payment_amount_cents' => $amountCents,
            'payment_access_number' => blank($accessNumber) ? null : mb_substr(trim((string) $accessNumber), 0, 60), 'updated_at' => now()]);
        $this->accounting->audit($organization, $user, 'fiscal.obligation.payment_amount', 'fiscal_obligations', $obligationId, ['amount_cents' => $amountCents], (int) $obligation->office_id);
    }

    /**
     * Sends the month's package to the client contacts who can see obligations: in their panel and by email. Returns how
     * many people received it.
     */
    public function send(Organization $organization, User $user, stdClass $company, int $year, int $month): int
    {
        $package = $this->package((int) $company->id, $year, $month);
        $payable = array_values(array_filter($package['items'], fn (array $item): bool => ($item['amount_cents'] ?? 0) > 0));
        if ($package['items'] === [] || ! $package['ready']) {
            throw ValidationException::withMessages(['package' => 'Indica el monto a pagar de cada obligación del mes (0 si no hay pago) antes de enviar el paquete.']);
        }
        $monthName = CarbonImmutable::create($year, $month, 1)->locale('es')->translatedFormat('F Y');
        $title = $payable === [] ? 'Impuestos de '.$monthName.': este mes no hay pagos' : 'Impuestos de '.$monthName.': pagas Q'.number_format($package['total_cents'] / 100, 2).' antes del '.$this->date((string) $package['due_on']);
        $lines = array_map(fn (array $item): string => '- '.$item['name'].' ('.$item['reference_period'].'): Q'.number_format(((int) $item['amount_cents']) / 100, 2).' · vence el '.$this->date($item['due_on'])
            .($item['access_number'] !== null ? ' · número de acceso '.$item['access_number'] : ''), $payable);
        $body = $payable === []
            ? 'Este mes '.$company->name.' no tiene impuestos por pagar. Las constancias de presentación están en tu portal, en Obligaciones.'
            : 'Este mes '.$company->name.' paga Q'.number_format($package['total_cents'] / 100, 2).' en impuestos:'."\n".implode("\n", $lines)."\n"
                .'Paga en tu banco con el número de acceso de cada formulario antes de su vencimiento. Las constancias de presentación están en tu portal, en Obligaciones.';
        $identity = $this->identities->identity($organization->id);
        $recipients = 0;
        foreach ($this->access->contacts((int) $company->id) as $contact) {
            $client = User::query()->find($contact['id']);
            if (! $client instanceof User || ! $this->access->can($organization, $client, (int) $company->id, 'client.obligations.view')) {
                continue;
            }
            $this->notifications->notify($organization->id, $client->id, $user->id, 'client.payment_package', $title, $body, 'client_obligations', $package['items'][0]['id'], ['company_id' => (int) $company->id]);
            try {
                Mail::to($client->email)->send(new ClientReminderMail($title, $body, $identity));
            } catch (Throwable $exception) {
                Log::warning('Payment package email failed', ['company_id' => $company->id, 'user_id' => $client->id, 'error' => $exception->getMessage()]);
            }
            $recipients++;
        }
        if ($recipients === 0) {
            throw ValidationException::withMessages(['package' => 'La empresa no tiene contactos en el portal que puedan ver sus obligaciones. Invítalos desde Portal de clientes.']);
        }
        DB::table('client_payment_packages')->updateOrInsert(['company_id' => $company->id, 'period' => $package['period']], [
            'organization_id' => $organization->id, 'total_cents' => $package['total_cents'], 'due_on' => $package['due_on'], 'recipients' => $recipients,
            'sent_by' => $user->id, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $user, 'fiscal.payment_package.sent', 'companies', (int) $company->id, ['period' => $package['period'], 'total_cents' => $package['total_cents'], 'recipients' => $recipients]);

        return $recipients;
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
}
