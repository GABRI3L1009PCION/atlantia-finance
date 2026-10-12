<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountingDocumentController extends Controller
{
    public function __construct(private WorkspaceContext $context, private AccountingService $accounting) {}

    /** Attach a verified support file to a draft entry; posted records remain immutable. */
    public function store(Request $request, string $company, string $entry): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $companyRecord = $this->accounting->company($org, $request->user(), $company, true);
        abort_unless($this->accounting->canJournal($org, $companyRecord->id, $request->user(), 'attach_support'), 403);
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'document.required' => 'Selecciona un documento.',
            'document.mimes' => 'Solo se permiten PDF, JPG, PNG o WEBP.',
            'document.max' => 'El documento no puede superar 10 MB.',
        ]);
        $file = $request->file('document');
        abort_unless($file instanceof UploadedFile, 422);
        $entryRecord = $this->entry($companyRecord->id, $entry);
        if (! in_array($entryRecord->status, ['draft', 'pending_review', 'reviewed', 'approved'], true)) {
            throw ValidationException::withMessages(['document' => 'Los respaldos de un asiento contabilizado son inmutables.']);
        }
        $this->accounting->openPeriod($companyRecord->id, $entryRecord->accounting_period_id, $entryRecord->entry_date);

        $uuid = (string) Str::uuid();
        $extension = strtolower($file->guessExtension() ?: $file->extension() ?: 'bin');
        $path = "accounting/{$companyRecord->public_id}/{$entry}/{$uuid}.{$extension}";
        $stored = Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        if (! $stored) {
            throw ValidationException::withMessages(['document' => 'No fue posible guardar el documento. Inténtalo nuevamente.']);
        }

        try {
            DB::transaction(function () use ($companyRecord, $entryRecord, $file, $org, $path, $request, $uuid): void {
                $this->accounting->lock($companyRecord->id);
                $locked = $this->entry($companyRecord->id, $entryRecord->public_id);
                if (! in_array($locked->status, ['draft', 'pending_review', 'reviewed', 'approved'], true)) {
                    throw ValidationException::withMessages(['document' => 'El asiento cambió de estado. Recarga la página.']);
                }
                $id = DB::table('journal_entry_documents')->insertGetId([
                    'public_id' => $uuid,
                    'journal_entry_id' => $locked->id,
                    'uploaded_by' => $request->user()->id,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'storage_path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->accounting->audit($org, $request->user(), 'entry.document.attached', 'journal_entry_documents', $id, ['entry_id' => $locked->id]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('accounting_notice', 'Documento de respaldo vinculado al borrador.');
    }

    public function download(Request $request, string $company, string $entry, string $document): StreamedResponse
    {
        $org = $this->context->organization($request->user());
        $companyRecord = $this->accounting->company($org, $request->user(), $company);
        $entryRecord = $this->entry($companyRecord->id, $entry);
        $record = $this->row(DB::table('journal_entry_documents')->where('journal_entry_id', $entryRecord->id)->where('public_id', $document));
        abort_unless(Storage::disk('local')->exists($record->storage_path), 404);

        return Storage::disk('local')->download($record->storage_path, $record->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $company, string $entry, string $document): RedirectResponse
    {
        $org = $this->context->organization($request->user());
        $companyRecord = $this->accounting->company($org, $request->user(), $company, true);
        abort_unless($this->accounting->canJournal($org, $companyRecord->id, $request->user(), 'attach_support'), 403);
        $entryRecord = $this->entry($companyRecord->id, $entry);
        if (! in_array($entryRecord->status, ['draft', 'pending_review', 'reviewed', 'approved'], true)) {
            throw ValidationException::withMessages(['document' => 'Los respaldos de un asiento contabilizado son inmutables.']);
        }
        $this->accounting->openPeriod($companyRecord->id, $entryRecord->accounting_period_id, $entryRecord->entry_date);
        $record = $this->row(DB::table('journal_entry_documents')->where('journal_entry_id', $entryRecord->id)->where('public_id', $document));

        DB::transaction(function () use ($companyRecord, $entryRecord, $org, $record, $request): void {
            $this->accounting->lock($companyRecord->id);
            abort_unless(in_array($this->entry($companyRecord->id, $entryRecord->public_id)->status, ['draft', 'pending_review', 'reviewed', 'approved'], true), 409);
            DB::table('journal_entry_documents')->where('id', $record->id)->delete();
            $this->accounting->audit($org, $request->user(), 'entry.document.removed', 'journal_entry_documents', $record->id, ['entry_id' => $entryRecord->id]);
        });
        Storage::disk('local')->delete($record->storage_path);

        return back()->with('accounting_notice', 'Documento retirado del borrador.');
    }

    private function entry(int $companyId, string $publicId): stdClass
    {
        return $this->row(DB::table('journal_entries as e')
            ->join('accounting_periods as p', 'p.id', '=', 'e.accounting_period_id')
            ->where('p.company_id', $companyId)
            ->where('e.public_id', $publicId)
            ->select('e.*'));
    }

    private function row(Builder $query): stdClass
    {
        return $query->firstOrFail();
    }
}
