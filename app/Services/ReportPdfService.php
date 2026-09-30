<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class ReportPdfService
{
    /** @param list<string> $headers @param iterable<list<mixed>> $rows @param array<string, string> $metadata */
    public function download(array $headers, iterable $rows, string $title, string $filename, array $metadata = []): Response
    {
        $formatCell = static function (mixed $value): string {
            if ($value === null || $value === '') {
                return '—';
            }
            if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $value, $date)) {
                return $date[3].'.'.$date[2].'.'.$date[1];
            }
            if (is_float($value)) {
                return number_format($value, 3, '.', ' ');
            }

            return (string) $value;
        };
        foreach ($metadata as $label => $value) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date)) {
                $metadata[$label] = $date[3].'.'.$date[2].'.'.$date[1];
            }
        }

        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 18,
            'margin_bottom' => 16,
            'default_font' => 'dejavusans',
            'tempDir' => $tempDir,
        ]);
        $pdf->SetTitle($title);
        $pdf->SetHTMLHeader('<div style="border-bottom:1px solid #d9dfeb;padding-bottom:6px;color:#64718a;font-size:8pt">Դիագեն Պլյուս · Պահեստային համակարգ</div>');
        $pdf->SetHTMLFooter('<div style="border-top:1px solid #d9dfeb;padding-top:5px;color:#64718a;font-size:8pt;text-align:right">Էջ {PAGENO} / {nbpg}</div>');
        $html = view('reports.pdf', compact('headers', 'rows', 'title', 'metadata', 'formatCell'))->render();
        $pdf->WriteHTML($html);
        $content = $pdf->Output('', 'S');

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
