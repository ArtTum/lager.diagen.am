<?php

namespace Tests\Unit;

use App\Services\TabularExportService;
use Tests\TestCase;

class TabularExportServiceTest extends TestCase
{
    public function test_csv_is_utf8_and_prevents_formula_injection(): void
    {
        $response = app(TabularExportService::class)->download(['Անուն'], [['=1+2'], ['Տվյալ']], 'csv', 'sample');
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        self::assertStringContainsString("'=1+2\n", $content);
        self::assertStringContainsString('Տվյալ', $content);
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=sample.csv', $response->headers->get('Content-Disposition'));
    }

    public function test_xlsx_export_uses_the_excel_content_type(): void
    {
        $response = app(TabularExportService::class)->download(['Անուն'], [['Տվյալ']], 'xlsx', 'sample');
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        self::assertStringStartsWith("PK\x03\x04", $content);
        self::assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        self::assertStringContainsString('sample.xlsx', $response->headers->get('Content-Disposition'));
    }
}
