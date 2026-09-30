<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class TabularExportService
{
    public function __construct(private readonly XlsxExportService $xlsx) {}

    /** @param list<string> $headers @param iterable<list<mixed>> $rows */
    public function download(array $headers, iterable $rows, string $format, string $filename): StreamedResponse
    {
        if ($format === 'xlsx') {
            $content = $this->xlsx->build($headers, $rows);

            return response()->streamDownload(static function () use ($content): void {
                echo $content;
            }, $filename.'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(static function () use ($headers, $rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ',', '"', '');
            foreach ($rows as $row) {
                $safe = array_map(static function (mixed $cell): mixed {
                    if (! is_string($cell)) {
                        return $cell;
                    }

                    return preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $cell) ? "'".$cell : $cell;
                }, $row);
                fputcsv($output, $safe, ',', '"', '');
            }
            fclose($output);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
