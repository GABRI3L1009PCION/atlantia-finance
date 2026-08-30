<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** @phpstan-type ExportReport array{title: string, filename?: string, company: string, scope: string, note: string, headers: list<string>, money: list<int>, rows: array<int, array<int, mixed>>} */
class ReportExportService
{
    /**
     * In-memory files only; text cells cannot become Excel formulas or remote PDF resources.
     *
     * @param  ExportReport  $report
     */
    public function download(array $report, string $format): Response
    {
        abort_if(count($report['rows']) > ($format === 'pdf' ? 1001 : 10001), 422, 'Reduce el rango de fechas para exportar.');
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        $baseName = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($report['filename'] ?? 'atlantia-reporte')));
        $baseName = trim((string) $baseName, '-') ?: 'atlantia-reporte';
        if ($format === 'pdf') {
            $options = new Options;
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('isJavascriptEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('accounting-report', ['report' => $report])->render());
            $pdf->setPaper('A4', count($report['headers']) > 4 ? 'landscape' : 'portrait');
            $pdf->render();
            $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
            if ($font === null) {
                throw new RuntimeException('No fue posible cargar la fuente del reporte.');
            }
            $pdf->getCanvas()->page_text(35, 20, 'ATLANTIA SYSTEMS · {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

            return response($pdf->output(), 200, [...$headers, 'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$baseName.'.pdf"']);
        }
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet()->setTitle('Reporte');
        $sheet->setCellValueExplicit('A1', $report['title'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A2', $report['company'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', $report['scope'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A4', $report['note'], DataType::TYPE_STRING);
        foreach ([$report['headers'], ...$report['rows']] as $r => $row) {
            foreach ($row as $c => $value) {
                $numeric = $r > 0 && in_array($c, $report['money']);
                $sheet->setCellValueExplicit([$c + 1, $r + 6], $numeric ? $value / 100 : (string) $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($numeric) {
                    $sheet->getStyle([$c + 1, $r + 6])->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
                }
            }
            if ($r > 0) {
                $height = 30;
                foreach ($row as $c => $value) {
                    if (! in_array($c, $report['money'])) {
                        $wrapped = array_sum(array_map(fn ($line) => max(1, (int) ceil(mb_strlen($line) / 24)), explode("\n", (string) $value)));
                        $height = max($height, min(409, $wrapped * 16 + 8));
                    }
                }
                $sheet->getRowDimension($r + 6)->setRowHeight($height);
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($report['headers']));
        foreach (range(1, 4) as $r) {
            $sheet->mergeCells('A'.$r.':'.$last.$r);
            $sheet->getRowDimension($r)->setRowHeight($r === 4 ? 44 : 25);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(18)->getColor()->setRGB('640000');
        $sheet->getStyle('A6:'.$last.'6')->getFill()->setFillType('solid')->getStartColor()->setRGB('640000');
        $sheet->getStyle('A6:'.$last.'6')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        foreach (range(1, count($report['headers'])) as $c) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setWidth(in_array($c - 1, $report['money']) ? 20 : 30);
        }
        $sheet->getStyle('A1:'.$last.(count($report['rows']) + 6))->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->freezePane('A7');
        $sheet->setAutoFilter('A6:'.$last.(count($report['rows']) + 6));

        return response()->streamDownload(function () use ($workbook): void {
            try {
                (new Xlsx($workbook))->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, $baseName.'.xlsx', [...$headers, 'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
