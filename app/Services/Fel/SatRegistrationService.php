<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\User;
use App\Services\WorkManagement\WorkTaskService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Documents issued by hand in the SAT portal: the draft waits as "pendiente de emitir en SAT", and once the SAT issued it
 * the person registers the result (the XML the SAT gives back, or its four key data). Atlantia compares what was
 * issued with the draft, keeps the evidence and continues with accounting, the IVA sales book and the client's email.
 */
class SatRegistrationService
{
    public function __construct(
        private FelCertificationService $certification,
        private SatXmlReader $reader,
        private FelAudit $audit,
        private WorkTaskService $tasks,
    ) {}

    /**
     * @param  array<string, mixed>  $manual  authorization_number, fel_series, fel_number and certified_at, used when there is no XML
     */
    public function register(FelDocument $document, User $user, array $manual, ?UploadedFile $xml = null): FelDocument
    {
        $fromXml = $xml !== null;
        $issued = $fromXml ? $this->fromXml($xml) : $this->fromForm($manual);

        return DB::transaction(function () use ($document, $user, $issued, $xml, $fromXml): FelDocument {
            $doc = FelDocument::forCompany($document->company_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaiting($doc);

            if ($fromXml) {
                $this->assertMatches($doc, $issued);
            }

            $duplicate = FelDocument::query()->where('authorization_number', $issued['authorization_number'])->whereKeyNot($doc->id)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['authorization_number' => 'Ese número de autorización ya está registrado en otro documento.']);
            }

            $path = null;
            if ($xml !== null) {
                $path = 'fel-sat/'.$doc->organization_id.'/'.$doc->company_id.'/'.$doc->public_id.'.xml';
                Storage::disk('local')->put($path, (string) file_get_contents($xml->getRealPath()));
            }

            $doc->transition(FelDocumentStatus::Certified);
            $doc->forceFill([
                'authorization_number' => $issued['authorization_number'], 'fel_series' => $issued['series'], 'fel_number' => $issued['number'],
                'certified_at' => $issued['certified_at'], 'error_code' => null,
                'sat_source' => $fromXml ? 'xml' : 'manual', 'sat_registered_by' => $user->id, 'sat_registered_at' => now(), 'sat_xml_path' => $path,
                'provider_response' => ['provider' => 'sat_manual', 'source' => $fromXml ? 'xml' : 'manual', 'registered_by' => $user->id],
            ])->save();
            $this->audit->record($doc, 'sat_registered', $user->id, ['source' => $fromXml ? 'xml' : 'manual']);
            $this->certification->finalize($doc);

            return $doc->fresh() ?? $doc;
        }, attempts: 3);
    }

    /** The prepared document goes back to a draft so it can be corrected (a mistake found before or while issuing it). */
    public function returnToDraft(FelDocument $document, User $user): void
    {
        DB::transaction(function () use ($document, $user): void {
            $doc = FelDocument::forCompany($document->company_id)->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertAwaiting($doc);
            $doc->transition(FelDocumentStatus::Draft);
            $doc->forceFill(['awaiting_sat_at' => null, 'sat_alerted_at' => null])->save();
            $this->audit->record($doc, 'returned_to_draft', $user->id);
        });
    }

    /** The accountant hands the portal step to an assistant as a work task that points to the document. */
    public function assignTask(FelDocument $document, Organization $organization, User $actor, int $assigneeId): int
    {
        if ($document->status !== FelDocumentStatus::AwaitingSat) {
            throw ValidationException::withMessages(['document' => 'Solo se asignan documentos pendientes de emitir en la SAT.']);
        }

        $officeId = (int) DB::table('companies')->where('id', $document->company_id)->value('office_id');
        $company = (string) DB::table('companies')->where('id', $document->company_id)->value('public_id');
        $receiver = (string) ($document->receiver_snapshot['name'] ?? '');
        $key = 'fel-sat-'.$document->public_id;

        return $this->tasks->create($organization, $actor, [
            'office_id' => $officeId, 'company_id' => $document->company_id, 'module' => 'sales', 'task_type' => 'fel_sat_issue',
            'title' => 'Emitir en el portal de la SAT: '.$document->series.'-'.$document->number.' · '.$receiver,
            'description' => 'Abre el documento en Atlantia, emítelo en el portal de la SAT y registra el resultado (XML o los datos de autorización): /accounting/companies/'.$company.'/fel/'.$document->public_id,
            'assigned_to' => $assigneeId, 'priority' => 'high', 'due_at' => now()->addDay(),
            'source_type' => 'fel_documents', 'source_id' => $document->id, 'automation_key' => $key,
            'metadata' => ['document' => $document->public_id],
        ]);
    }

    private function assertAwaiting(FelDocument $doc): void
    {
        if ($doc->provider !== FelProviderResolver::SAT_MANUAL || $doc->status !== FelDocumentStatus::AwaitingSat) {
            throw ValidationException::withMessages(['document' => 'Este documento no está pendiente de emitir en la SAT.']);
        }
    }

    /**
     * @return array{authorization_number: string, series: string, number: string, certified_at: CarbonImmutable, issuer_nit: ?string, receiver_id: ?string, total_cents: ?int, document_type: ?string}
     */
    private function fromXml(UploadedFile $xml): array
    {
        Validator::make(['sat_xml' => $xml], ['sat_xml' => ['required', 'file', 'max:2048', 'mimetypes:text/xml,application/xml,text/plain']], ['sat_xml.max' => 'El XML pesa demasiado.', 'sat_xml.mimetypes' => 'Sube el archivo XML que descargas de la SAT.'])->validate();
        $read = $this->reader->read((string) file_get_contents($xml->getRealPath()));

        try {
            $certifiedAt = CarbonImmutable::parse($read['certified_at'] !== '' ? $read['certified_at'] : 'now');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['sat_xml' => 'No se pudo leer la fecha de certificación del XML.']);
        }

        return [...$read, 'series' => $read['series'] !== '' ? $read['series'] : 'SAT', 'number' => $read['number'] !== '' ? $read['number'] : '0', 'certified_at' => $certifiedAt];
    }

    /**
     * @param  array<string, mixed>  $manual
     * @return array{authorization_number: string, series: string, number: string, certified_at: CarbonImmutable, issuer_nit: ?string, receiver_id: ?string, total_cents: ?int, document_type: ?string}
     */
    private function fromForm(array $manual): array
    {
        $data = Validator::make($manual, [
            'authorization_number' => ['required', 'string', 'max:255'], 'fel_series' => ['required', 'string', 'max:50'],
            'fel_number' => ['required', 'string', 'max:50'], 'certified_at' => ['required', 'date', 'before_or_equal:now'],
        ], [], ['authorization_number' => 'número de autorización', 'fel_series' => 'serie', 'fel_number' => 'número', 'certified_at' => 'fecha de emisión'])->validate();

        return [
            'authorization_number' => trim((string) $data['authorization_number']), 'series' => trim((string) $data['fel_series']), 'number' => trim((string) $data['fel_number']),
            'certified_at' => CarbonImmutable::parse((string) $data['certified_at']), 'issuer_nit' => null, 'receiver_id' => null, 'total_cents' => null, 'document_type' => null,
        ];
    }

    /** @param array{issuer_nit: ?string, receiver_id: ?string, total_cents: ?int, ...} $issued */
    private function assertMatches(FelDocument $doc, array $issued): void
    {
        $normalize = fn (?string $nit): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $nit));
        $differences = [];

        if ($issued['issuer_nit'] !== null && $normalize($issued['issuer_nit']) !== $normalize((string) ($doc->fiscal_snapshot['nit'] ?? ''))) {
            $differences[] = 'El NIT del emisor del XML ('.$issued['issuer_nit'].') no es el de la empresa ('.($doc->fiscal_snapshot['nit'] ?? '').').';
        }
        if ($issued['receiver_id'] !== null && $normalize($issued['receiver_id']) !== $normalize((string) ($doc->receiver_snapshot['nit'] ?? ''))) {
            $differences[] = 'El NIT del receptor del XML ('.$issued['receiver_id'].') no es el del borrador ('.($doc->receiver_snapshot['nit'] ?? '').').';
        }
        if ($issued['total_cents'] !== null && $issued['total_cents'] !== $doc->total_cents) {
            $differences[] = 'El total del XML (Q '.number_format($issued['total_cents'] / 100, 2).') no es el del borrador (Q '.number_format($doc->total_cents / 100, 2).').';
        }

        if ($differences !== []) {
            throw ValidationException::withMessages(['sat_xml' => implode(' ', $differences).' Revisa lo emitido en la SAT o devuelve el documento a borrador.']);
        }
    }
}
