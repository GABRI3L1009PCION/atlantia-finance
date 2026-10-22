<?php

declare(strict_types=1);

namespace App\Services\ClientPortal;

use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Platform\UsageMeterService;
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
 * The client's document mailbox. A delivered file is received and reviewed by the office; it never becomes an
 * accounting entry by itself. Replacing a document adds a version (the previous one is kept with its hash), and files
 * are only served through an authorized download.
 */
class ClientDocumentService
{
    public const CATEGORIES = [
        'invoices' => 'Facturas', 'bank_statements' => 'Estados de cuenta', 'receipts' => 'Recibos', 'contracts' => 'Contratos', 'vouchers' => 'Comprobantes', 'payroll' => 'Documentos laborales',
        'fiscal' => 'Documentos fiscales', 'purchases' => 'Archivos de compras', 'sales' => 'Archivos de ventas', 'other' => 'Otros',
    ];

    /** What the client sees: technical states mapped to clear words. */
    public const CLIENT_LABELS = [
        'received' => 'Recibido', 'under_review' => 'En revisión', 'more_information_required' => 'Requiere información', 'accepted' => 'Procesado', 'processed' => 'Procesado',
        'rejected' => 'Rechazado', 'archived' => 'Archivado',
    ];

    public const OFFICE_LABELS = [
        'received' => 'Recibido', 'under_review' => 'En revisión', 'more_information_required' => 'Requiere información', 'accepted' => 'Aceptado', 'processed' => 'Procesado',
        'rejected' => 'Rechazado', 'archived' => 'Archivado',
    ];

    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'csv', 'xml', 'docx', 'doc', 'txt', 'zip'];

    public function __construct(private AccountingService $accounting, private ClientAccessService $access, private WorkNotificationService $notifications) {}

    public function upload(Organization $organization, User $client, int $companyId, UploadedFile $file, string $category, string $title, ?string $period, ?int $requestId = null): int
    {
        $this->access->ensure($organization, $client, $companyId, $category === 'bank_statements' ? 'client.bank_documents.upload' : 'client.documents.upload');
        $category = isset(self::CATEGORIES[$category]) ? $category : 'other';

        return DB::transaction(function () use ($organization, $client, $companyId, $file, $category, $title, $period, $requestId): int {
            $id = DB::table('client_documents')->insertGetId([
                'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'company_id' => $companyId, 'client_request_id' => $requestId, 'category' => $category, 'title' => mb_substr($title, 0, 180),
                'period_label' => $period, 'status' => 'received', 'current_version' => 1, 'uploaded_by' => $client->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->storeVersion($organization, $client, $companyId, $id, 1, $file, null);
            foreach ($this->responsibles($companyId) as $recipient) {
                $this->notifications->notify($organization->id, $recipient, $client->id, 'client_portal', 'Documento recibido del cliente: '.$title, self::CATEGORIES[$category], 'client_documents', $id);
            }
            $this->accounting->audit($organization, $client, 'client.document.uploaded', 'client_documents', $id, ['company_id' => $companyId, 'category' => $category, 'request_id' => $requestId]);

            return $id;
        });
    }

    /** New version of a document (the previous one is never deleted). */
    public function replace(Organization $organization, User $client, int $documentId, UploadedFile $file, string $reason): void
    {
        DB::transaction(function () use ($organization, $client, $documentId, $file, $reason): void {
            $document = DB::table('client_documents')->where('client_documents.organization_id', $organization->id)->where('id', $documentId)->lockForUpdate()->first() ?? abort(404);
            $this->access->ensure($organization, $client, (int) $document->company_id, 'client.documents.replace');
            if (! in_array($document->status, ['received', 'under_review', 'more_information_required', 'rejected'], true)) {
                throw ValidationException::withMessages(['file' => 'Un documento ya procesado no se reemplaza; entrega uno nuevo si cambió la información.']);
            }
            if (mb_strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'Indica el motivo del reemplazo.']);
            }
            $version = (int) $document->current_version + 1;
            $this->storeVersion($organization, $client, (int) $document->company_id, $documentId, $version, $file, $reason);
            DB::table('client_documents')->where('id', $documentId)->update(['current_version' => $version, 'status' => 'received', 'updated_at' => now()]);
            if ($document->client_request_id !== null) {
                DB::table('client_requests')->where('id', $document->client_request_id)->where('status', 'more_information_required')->update(['status' => 'submitted', 'responded_at' => now(), 'updated_at' => now()]);
            }
            foreach ($this->responsibles((int) $document->company_id) as $recipient) {
                $this->notifications->notify($organization->id, $recipient, $client->id, 'client_portal', 'Documento reemplazado por el cliente: '.$document->title, 'Versión '.$version.' · '.$reason, 'client_documents', $documentId);
            }
            $this->accounting->audit($organization, $client, 'client.document.replaced', 'client_documents', $documentId, ['version' => $version, 'reason' => $reason]);
        });
    }

    /** review | accept | process | more_information | reject (office). The client sees the note, never the internal one. */
    public function review(Organization $organization, User $actor, int $documentId, string $action, ?string $note, ?string $internalNote): void
    {
        DB::transaction(function () use ($organization, $actor, $documentId, $action, $note, $internalNote): void {
            $document = DB::table('client_documents')->where('client_documents.organization_id', $organization->id)->where('id', $documentId)->lockForUpdate()->first() ?? abort(404);
            app(ClientRequestService::class)->assertOffice($organization, $actor, (int) $document->company_id, 'client_portal.documents.review');
            [$from, $to] = match ($action) {
                'review' => [['received'], 'under_review'],
                'accept' => [['received', 'under_review'], 'accepted'],
                'process' => [['received', 'under_review', 'accepted'], 'processed'],
                'more_information' => [['received', 'under_review'], 'more_information_required'],
                'reject' => [['received', 'under_review'], 'rejected'],
                default => abort(404),
            };
            if (! in_array($document->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'El documento está '.mb_strtolower(self::OFFICE_LABELS[$document->status]).'.']);
            }
            if (in_array($action, ['more_information', 'reject'], true) && mb_strlen(trim((string) $note)) < 5) {
                throw ValidationException::withMessages(['note' => 'Indica al cliente el motivo y qué debe corregir.']);
            }
            DB::table('client_documents')->where('id', $documentId)->update(['status' => $to, 'review_note' => $note ?? $document->review_note, 'internal_note' => $internalNote ?? $document->internal_note,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'processed_at' => $to === 'processed' ? now() : $document->processed_at, 'updated_at' => now()]);
            if ($document->client_request_id !== null && in_array($action, ['more_information', 'reject'], true)) {
                DB::table('client_requests')->where('id', $document->client_request_id)->whereIn('status', ['submitted', 'under_review'])->update(['status' => 'more_information_required', 'review_note' => $note, 'updated_at' => now()]);
            }
            if ($action !== 'review') {
                $this->notifications->notify($organization->id, (int) $document->uploaded_by, $actor->id, 'client_portal', 'Documento '.mb_strtolower(self::CLIENT_LABELS[$to]).': '.$document->title, $note, 'client_documents', $documentId);
            }
            $this->accounting->audit($organization, $actor, 'client_portal.document.'.$action, 'client_documents', $documentId, ['note' => $note]);
        });
    }

    /** Authorized download of a version (client of the company, or office user working on it). */
    public function download(Organization $organization, User $user, int $documentId, ?int $version): StreamedResponse
    {
        $document = DB::table('client_documents')->where('client_documents.organization_id', $organization->id)->where('id', $documentId)->first() ?? abort(404);
        $isClient = $this->access->can($organization, $user, (int) $document->company_id, 'client.documents.view');
        if (! $isClient) {
            app(ClientRequestService::class)->assertOffice($organization, $user, (int) $document->company_id, 'client_portal.view');
        }
        $row = DB::table('client_document_versions')->where('client_document_id', $documentId)->where('version', $version ?? (int) $document->current_version)->first() ?? abort(404);
        abort_unless(Storage::disk('local')->exists((string) $row->file_path), 404);

        return Storage::disk('local')->download((string) $row->file_path, (string) $row->file_name);
    }

    /** @return array<string, mixed> */
    public function row(stdClass $row, bool $office = false): array
    {
        $versions = DB::table('client_document_versions')->where('client_document_id', $row->id)->orderByDesc('version')->get(['version', 'file_name', 'reason', 'uploaded_at', 'file_hash']);

        return [
            'id' => (int) $row->id, 'title' => (string) $row->title, 'filename' => (string) ($versions->first()->file_name ?? ''), 'category' => (string) $row->category,
            'category_label' => self::CATEGORIES[$row->category] ?? (string) $row->category, 'period' => $row->period_label, 'uploaded_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'uploaded_by' => (string) DB::table('users')->where('id', $row->uploaded_by)->value('name'), 'status' => (string) $row->status,
            'status_label' => ($office ? self::OFFICE_LABELS : self::CLIENT_LABELS)[$row->status] ?? (string) $row->status, 'processed_at' => $row->processed_at, 'review_note' => $row->review_note,
            'requested_by' => $row->client_request_id === null ? null : (string) DB::table('client_requests')->join('users', 'users.id', '=', 'client_requests.requested_by')->where('client_requests.id', $row->client_request_id)->value('users.name'),
            'request_id' => $row->client_request_id === null ? null : (int) $row->client_request_id, 'current_version' => (int) $row->current_version,
            'versions' => $versions->map(fn (stdClass $v): array => ['version' => (int) $v->version, 'file_name' => (string) $v->file_name, 'reason' => $v->reason, 'uploaded_at' => (string) $v->uploaded_at,
                'download' => ($office ? '/work/client-portal/documents/' : '/client/documents/').$row->id.'/download?version='.$v->version])->values()->all(),
            'can_replace' => in_array($row->status, ['received', 'under_review', 'more_information_required', 'rejected'], true),
            ...($office ? ['internal_note' => $row->internal_note] : []),
        ];
    }

    private function storeVersion(Organization $organization, User $user, int $companyId, int $documentId, int $version, UploadedFile $file, ?string $reason): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::ALLOWED, true) || $file->getSize() > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Formato no permitido o archivo mayor a 20 MB.']);
        }
        app(UsageMeterService::class)->assertCanAdd((int) $organization->id, 'storage_bytes', (int) $file->getSize(), 'file');
        $path = $file->storeAs('client-documents/'.$organization->id.'/'.$companyId, Str::uuid().'.'.$extension, 'local');
        DB::table('client_document_versions')->insert(['organization_id' => $organization->id, 'company_id' => $companyId, 'client_document_id' => $documentId, 'version' => $version, 'file_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 250), 'mime' => $file->getClientMimeType(), 'size_bytes' => (int) $file->getSize(), 'file_hash' => (string) hash_file('sha256', (string) $file->getRealPath()),
            'reason' => $reason, 'uploaded_by' => $user->id, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<int> */
    private function responsibles(int $companyId): array
    {
        return array_values(array_map('intval', DB::table('company_assignments')->where('company_id', $companyId)->whereIn('assignment_role', ['accounting_assistant', 'accountant_in_charge'])->where('status', 'active')->whereNull('deleted_at')->pluck('user_id')->unique()->all()));
    }
}
