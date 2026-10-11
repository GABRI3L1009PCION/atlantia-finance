<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Services\ReportExportService;
use App\Services\WorkManagement\WorkNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports the office publishes to the client. The PDF is generated once from the official statements (or uploaded by
 * the office) and kept as a snapshot: later accounting changes never alter a published file, a correction is a new
 * version, and an error is revoked with its reason instead of being deleted. The client only sees published reports.
 */
class PublishedReportService
{
    public const TYPES = [
        'income_statement' => 'Estado de Resultados', 'balance_sheet' => 'Balance General', 'trial_balance' => 'Balance de Comprobación', 'receivables' => 'Estado de Cuentas por Cobrar',
        'payables' => 'Estado de Cuentas por Pagar', 'cash_flow' => 'Flujo de efectivo', 'fiscal' => 'Reporte fiscal', 'other' => 'Otro reporte',
    ];

    /** Types generated from the official statements; the rest are uploaded by the office. */
    public const GENERATED = ['income_statement' => 'income', 'balance_sheet' => 'position', 'trial_balance' => 'trial'];

    public const FINANCIAL = ['income_statement', 'balance_sheet', 'trial_balance', 'cash_flow'];

    public const STATUS_LABELS = ['draft' => 'Borrador', 'in_review' => 'En revisión', 'approved' => 'Aprobado', 'published' => 'Publicado', 'revoked' => 'Revocado'];

    public function __construct(
        private AccountingService $accounting,
        private FinancialReportService $reports,
        private ReportExportService $exports,
        private ClientAccessService $access,
        private WorkNotificationService $notifications,
    ) {}

    public function prepare(Organization $organization, User $actor, int $companyId, string $type, string $from, string $to, ?UploadedFile $file): int
    {
        $requests = app(ClientRequestService::class);
        $requests->assertOffice($organization, $actor, $companyId, 'client_portal.view');
        abort_unless(isset(self::TYPES[$type]), 422);
        if (! isset(self::GENERATED[$type]) && $file === null) {
            throw ValidationException::withMessages(['file' => 'Adjunta el PDF del reporte.']);
        }
        if ($from > $to) {
            throw ValidationException::withMessages(['period_start' => 'El período no es válido.']);
        }
        $company = DB::table('companies')->where('id', $companyId)->first(['id', 'name']) ?? abort(404);
        if ($file !== null) {
            if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
                throw ValidationException::withMessages(['file' => 'El reporte publicado debe ser PDF.']);
            }
            $content = (string) file_get_contents((string) $file->getRealPath());
        } else {
            $book = $this->reports->book($companyId, self::GENERATED[$type], $from, $to, 1000);
            $content = (string) $this->exports->download(['title' => self::TYPES[$type], 'company' => (string) $company->name, 'scope' => 'Período '.Carbon::parse($from)->format('d/m/Y').' al '.Carbon::parse($to)->format('d/m/Y'),
                'note' => 'Reporte preparado por la oficina contable a partir de los registros contabilizados al '.now()->format('d/m/Y H:i').'.', 'headers' => $book['headers'], 'money' => $book['money'], 'rows' => $book['rows']], 'pdf')->getContent();
        }
        $path = 'published-reports/'.$organization->id.'/'.$companyId.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $content);
        $version = (int) DB::table('published_reports')->where('company_id', $companyId)->where('report_type', $type)->where('period_start', $from)->where('period_end', $to)->max('version') + 1;
        $id = DB::table('published_reports')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'report_type' => $type, 'title' => self::TYPES[$type],
            'period_label' => Carbon::parse($from)->format('d/m/Y').' – '.Carbon::parse($to)->format('d/m/Y'), 'period_start' => $from, 'period_end' => $to, 'version' => $version, 'status' => 'draft',
            'file_path' => $path, 'file_hash' => hash('sha256', $content), 'metadata' => json_encode(['source' => $file === null ? 'generated' : 'uploaded', 'generated_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR),
            'prepared_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->accounting->audit($organization, $actor, 'client_portal.report.prepared', 'published_reports', $id, ['type' => $type, 'version' => $version, 'company_id' => $companyId]);

        return $id;
    }

    /** submit | approve | publish | revoke. Approval and publication need the permission; the approver is not the preparer. */
    public function action(Organization $organization, User $actor, int $reportId, string $action, ?string $reason): void
    {
        DB::transaction(function () use ($organization, $actor, $reportId, $action, $reason): void {
            $report = DB::table('published_reports')->where('published_reports.organization_id', $organization->id)->where('id', $reportId)->lockForUpdate()->first() ?? abort(404);
            $requests = app(ClientRequestService::class);
            $requests->assertOffice($organization, $actor, (int) $report->company_id, $action === 'submit' ? 'client_portal.view' : 'client_portal.reports.publish');
            $values = match ($action) {
                'submit' => $report->status === 'draft' ? ['status' => 'in_review'] : $this->invalid($report),
                'approve' => $report->status === 'in_review' ? ((int) $report->prepared_by === $actor->id ? throw ValidationException::withMessages(['status' => 'Quien prepara el reporte no puede aprobarlo.']) : ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]) : $this->invalid($report),
                'publish' => $report->status === 'approved' ? ['status' => 'published', 'published_by' => $actor->id, 'published_at' => now()] : $this->invalid($report),
                'revoke' => $report->status === 'published' && mb_strlen(trim((string) $reason)) >= 5 ? ['status' => 'revoked', 'revoked_by' => $actor->id, 'revoked_at' => now(), 'revoke_reason' => $reason] : throw ValidationException::withMessages(['reason' => 'Solo se revoca un reporte publicado, indicando el motivo.']),
                default => abort(404),
            };
            DB::table('published_reports')->where('id', $reportId)->update([...$values, 'updated_at' => now()]);
            if ($action === 'publish') {
                foreach ($this->access->contacts((int) $report->company_id) as $contact) {
                    $user = User::query()->find($contact['id']);
                    if ($user instanceof User && $this->canSee($organization, $user, $report)) {
                        $this->notifications->notify($organization->id, $contact['id'], $actor->id, 'client_portal', 'Reporte publicado: '.$report->title, $report->period_label.' · versión '.$report->version, 'published_reports', $reportId);
                    }
                }
            }
            $this->accounting->audit($organization, $actor, 'client_portal.report.'.$action, 'published_reports', $reportId, ['version' => (int) $report->version, 'reason' => $reason]);
        });
    }

    public function download(Organization $organization, User $user, int $reportId, bool $client): StreamedResponse
    {
        $report = DB::table('published_reports')->where('published_reports.organization_id', $organization->id)->where('id', $reportId)->first() ?? abort(404);
        if ($client) {
            abort_unless($report->status === 'published' && $this->canSee($organization, $user, $report) && $this->access->can($organization, $user, (int) $report->company_id, 'client.reports.download'), 404);
            $this->accounting->audit($organization, $user, 'client.report.downloaded', 'published_reports', $reportId, ['version' => (int) $report->version, 'company_id' => (int) $report->company_id]);
        } else {
            app(ClientRequestService::class)->assertOffice($organization, $user, (int) $report->company_id, 'client_portal.view');
        }
        abort_unless($report->file_path !== null && Storage::disk('local')->exists((string) $report->file_path), 404);

        return Storage::disk('local')->download((string) $report->file_path, Str::slug($report->title.' '.$report->period_label).'-v'.$report->version.'.pdf');
    }

    public function canSee(Organization $organization, User $user, stdClass $report): bool
    {
        return $this->access->can($organization, $user, (int) $report->company_id, 'client.reports.view')
            && (! in_array($report->report_type, self::FINANCIAL, true) || $this->access->can($organization, $user, (int) $report->company_id, 'client.financial_reports.view'));
    }

    /**
     * Published reports a client may see (newest version marked; older versions flagged as replaced).
     *
     * @return list<array<string, mixed>>
     */
    public function forClient(Organization $organization, User $user, int $companyId): array
    {
        $rows = DB::table('published_reports')->where('company_id', $companyId)->where('status', 'published')->orderByDesc('published_at')->orderByDesc('version')->get();

        return array_values($rows->filter(fn (stdClass $row): bool => $this->canSee($organization, $user, $row))->map(function (stdClass $row) use ($rows, $organization, $user): array {
            $newer = $rows->first(fn (stdClass $other): bool => $other->report_type === $row->report_type && $other->period_start === $row->period_start && $other->period_end === $row->period_end && (int) $other->version > (int) $row->version);

            return ['id' => (int) $row->id, 'report_type' => (string) $row->report_type, 'title' => (string) $row->title, 'period' => (string) $row->period_label, 'version' => (int) $row->version,
                'published_at' => Carbon::parse($row->published_at)->toIso8601String(), 'replaced' => $newer !== null,
                'downloadable' => $this->access->can($organization, $user, (int) $row->company_id, 'client.reports.download'), 'destination' => '/client/reports/'.$row->id.'/download'];
        })->all());
    }

    /** @return array<string, mixed> */
    public function officeRow(stdClass $row): array
    {
        $name = fn (?int $id): ?string => $id === null ? null : (string) DB::table('users')->where('id', $id)->value('name');

        return ['id' => (int) $row->id, 'title' => (string) $row->title, 'report_type' => (string) $row->report_type, 'period' => (string) $row->period_label, 'version' => (int) $row->version,
            'status' => (string) $row->status, 'status_label' => self::STATUS_LABELS[$row->status] ?? (string) $row->status, 'prepared_by' => $name((int) $row->prepared_by),
            'approved_by' => $name($row->approved_by === null ? null : (int) $row->approved_by), 'published_at' => $row->published_at, 'revoke_reason' => $row->revoke_reason,
            'download' => '/work/client-portal/reports/'.$row->id.'/download'];
    }

    private function invalid(stdClass $report): never
    {
        throw ValidationException::withMessages(['status' => 'Acción no disponible para un reporte '.mb_strtolower(self::STATUS_LABELS[$report->status] ?? $report->status).'.']);
    }
}
