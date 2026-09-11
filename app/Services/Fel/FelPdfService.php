<?php

declare(strict_types=1);

namespace App\Services\Fel;

use App\Models\FelDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;

class FelPdfService
{
    public function __construct(private FelAudit $audit) {}

    public function generate(int $companyId, string $uuid): void
    {
        DB::transaction(function () use ($companyId, $uuid): void {
            $doc = FelDocument::forCompany($companyId)->where('public_id', $uuid)->lockForUpdate()->firstOrFail();
            if (! in_array($doc->status, [FelDocumentStatus::Certified, FelDocumentStatus::Cancelled], true)) {
                return;
            }
            $path = $this->path($doc);
            if ($doc->pdf_path === $path && Storage::disk('local')->exists($path)) {
                return;
            }
            $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('fel.invoice', ['document' => $doc->load('lines')])->render());
            $pdf->setPaper('A4');
            $pdf->render();
            if (! Storage::disk('local')->put($path, $pdf->output())) {
                throw new RuntimeException('No fue posible guardar el PDF.');
            }
            $doc->forceFill(['pdf_path' => $path, 'pdf_status' => 'ready'])->save();
            $this->audit->record($doc, 'pdf_generated');
        });
    }

    public function path(FelDocument $document): string
    {
        return 'fel/'.$document->company_id.'/'.$document->public_id.'/'.$document->status->value.'.pdf';
    }

    public static function money(int $cents): string
    {
        return 'GTQ '.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function amountInWords(int $cents): string
    {
        $whole = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        $currency = $whole === 1 ? 'QUETZAL' : 'QUETZALES';
        $words = Number::spell($whole, locale: 'es');
        if ($words === false) {
            throw new RuntimeException('No fue posible convertir el total del documento a letras.');
        }

        return Str::upper($words).' '.$currency.' CON '.$fraction.'/100';
    }
}
