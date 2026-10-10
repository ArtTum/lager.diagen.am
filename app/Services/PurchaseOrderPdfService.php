<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderPdfService
{
    public function download(array $document): Response
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans',
            'percentSubset' => 0,
            'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 22, 'margin_bottom' => 20,
            'margin_header' => 8, 'margin_footer' => 9, 'tempDir' => $tempDir,
        ]);
        $pdf->SetTitle('Գնման պատվեր - '.$document['order_no']);
        $pdf->SetAuthor('Դիագեն Պլյուս');
        $pdf->SetHTMLHeader('<div style="border-bottom:1px solid #dce2ec;padding-bottom:6px;color:#65718a;font-size:8pt">Դիագեն Պլյուս | Գնման պատվեր '.e($document['order_no']).'</div>');
        $pdf->SetHTMLFooter('<div style="border-top:1px solid #dce2ec;padding-top:6px;color:#65718a;font-size:8pt;text-align:right">Էջ {PAGENO} / {nbpg}</div>');
        $number = static function (string $value, bool $quantity = false): string {
            [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $integer);
            $fraction = $quantity ? rtrim($fraction, '0') : str_pad($fraction, 2, '0');

            return $integer.($fraction === '' ? '' : '.'.$fraction);
        };
        $pdf->WriteHTML(view('purchasing.order-pdf', compact('document', 'number'))->render());

        return response($pdf->Output('', 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="purchase-order-'.$document['id'].'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
