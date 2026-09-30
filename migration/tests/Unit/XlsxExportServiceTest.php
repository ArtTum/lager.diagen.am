<?php

namespace Tests\Unit;

use App\Services\XlsxExportService;
use PHPUnit\Framework\TestCase;

class XlsxExportServiceTest extends TestCase
{
    public function test_builds_an_xlsx_archive_with_literal_text_and_numeric_cells(): void
    {
        $xlsx = (new XlsxExportService)->build(['Ապրանք', 'Քանակ'], [['=1+1', 12.5]]);

        self::assertSame("PK\x03\x04", substr($xlsx, 0, 4));
        self::assertStringContainsString('xl/worksheets/sheet1.xml', $xlsx);
        self::assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=1+1</t>', $xlsx);
        self::assertStringContainsString('<c r="B2"><v>12.5</v></c>', $xlsx);
        self::assertStringContainsString("PK\x05\x06", substr($xlsx, -22));
    }
}
