<?php

namespace App\Http\Controllers;

use App\Models\OperationalRecord;
use App\Models\OperationalRecordFile;
use App\Models\Organization;
use App\Services\AccountingService;
use App\Services\Operations\OperationalWorkflowRegistry;
use App\Services\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalRecordFileController extends Controller
{
    public function __construct(
        private WorkspaceContext $workspace,
        private AccountingService $accounting,
        private OperationalWorkflowRegistry $workflows,
    ) {}

    public function store(Request $request, string $company, string $module, string $record): JsonResponse
    {
        [$organization, $companyRecord, $operationalRecord] = $this->scope($request, $company, $module, $record, true);
        abort_if(in_array($operationalRecord->status, ['closed', 'cancelled', 'archived', 'published', 'posted'], true), 422, 'El registro ya no admite archivos.');
        $data = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,doc,docx,txt,xml']]);
        $uploaded = $data['file'];
        $uuid = (string) Str::uuid();
        $extension = strtolower($uploaded->getClientOriginalExtension());
        $path = "operations/{$companyRecord->public_id}/{$module}/{$operationalRecord->public_id}/{$uuid}.{$extension}";
        Storage::disk('local')->putFileAs(dirname($path), $uploaded, basename($path));

        try {
            $file = DB::transaction(function () use ($organization, $request, $companyRecord, $operationalRecord, $uploaded, $path): OperationalRecordFile {
                $this->accounting->lock($companyRecord->id);
                $file = OperationalRecordFile::query()->create([
                    'operational_record_id' => $operationalRecord->id,
                    'company_id' => $companyRecord->id,
                    'uploaded_by' => $request->user()->id,
                    'original_name' => Str::limit(basename($uploaded->getClientOriginalName()), 255, ''),
                    'storage_path' => $path,
                    'mime_type' => $uploaded->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $uploaded->getSize(),
                    'sha256' => hash_file('sha256', $uploaded->getRealPath()),
                ]);
                $this->accounting->audit($organization, $request->user(), 'operation.file.attached', 'operational_record_files', $file->id);

                return $file->load('uploader:id,name,email');
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['file' => $file], 201);
    }

    public function download(Request $request, string $company, string $module, string $record, string $file): StreamedResponse
    {
        [, , $operationalRecord] = $this->scope($request, $company, $module, $record);
        $stored = OperationalRecordFile::query()->where('operational_record_id', $operationalRecord->id)
            ->where('public_id', $file)->firstOrFail();

        return Storage::disk('local')->download($stored->storage_path, $stored->original_name, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $company, string $module, string $record, string $file): JsonResponse
    {
        [$organization, $companyRecord, $operationalRecord] = $this->scope($request, $company, $module, $record, true);
        abort_if(in_array($operationalRecord->status, ['closed', 'cancelled', 'archived', 'published', 'posted'], true), 422, 'Los archivos del registro son inmutables.');
        $stored = OperationalRecordFile::query()->where('operational_record_id', $operationalRecord->id)
            ->where('public_id', $file)->firstOrFail();
        DB::transaction(function () use ($organization, $request, $companyRecord, $stored): void {
            $this->accounting->lock($companyRecord->id);
            $stored->delete();
            $this->accounting->audit($organization, $request->user(), 'operation.file.removed', 'operational_record_files', $stored->id);
        });
        Storage::disk('local')->delete($stored->storage_path);

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    /** @return array{Organization, \stdClass, OperationalRecord} */
    private function scope(Request $request, string $company, string $module, string $record, bool $write = false): array
    {
        $organization = $this->workspace->organization($request->user());
        $definition = $this->workflows->definition($module);
        $this->workspace->requireFeature($organization, $definition['feature']);
        $companyRecord = $this->accounting->company($organization, $request->user(), $company, $write);
        $this->accounting->assertModule($organization, $companyRecord->id, $request->user(), $write ? 'operations.manage' : 'operations.view');
        $operationalRecord = OperationalRecord::query()->where('company_id', $companyRecord->id)
            ->where('module', $module)->where('public_id', $record)->firstOrFail();

        return [$organization, $companyRecord, $operationalRecord];
    }
}
