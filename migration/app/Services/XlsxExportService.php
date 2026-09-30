<?php

namespace App\Services;

class XlsxExportService
{
    /** @param list<string> $headers @param iterable<list<mixed>> $rows */
    public function build(array $headers, iterable $rows): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $columnName = static function (int $column): string {
            $name = '';
            while ($column > 0) {
                $column--;
                $name = chr(65 + $column % 26).$name;
                $column = intdiv($column, 26);
            }

            return $name;
        };
        $numericHeader = static fn (string $header): bool => (bool) preg_match('/(քանակ|գին|արժեք|մնացորդ|պահուստ|ազատ|տարբերություն|օգտագործում|պահանջված|հաստատված|ստացված|սպառում|օրերի|միջին|ամսական|qty|price|cost|total|average|stock|min|max|optimal)/iu', $header);

        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $rowNumber = 0;
        foreach ((static function () use ($headers, $rows): iterable {
            yield array_values($headers);
            foreach ($rows as $row) {
                yield array_values($row);
            }
        })() as $row) {
            $rowNumber++;
            $sheet .= '<row r="'.$rowNumber.'">';
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $columnName($columnIndex + 1).$rowNumber;
                $isDataRow = $rowNumber > 1;
                if ($isDataRow && $numericHeader((string) ($headers[$columnIndex] ?? '')) && is_numeric($value)) {
                    $sheet .= '<c r="'.$reference.'"><v>'.(string) (float) $value.'</v></c>';
                } else {
                    $sheet .= '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$escape($value).'</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Հաշվետվություն" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$sheet,
        ];

        return $this->zipStoredFiles($files);
    }

    /** @param array<string, string> $files */
    private function zipStoredFiles(array $files): string
    {
        $body = '';
        $directory = '';
        foreach ($files as $name => $content) {
            $nameLength = strlen($name);
            $length = strlen($content);
            $crc = crc32($content);
            $offset = strlen($body);
            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0, $crc, $length, $length, $nameLength, 0).$name.$content;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0, $crc, $length, $length, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
        }

        return $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($body), 0);
    }
}
