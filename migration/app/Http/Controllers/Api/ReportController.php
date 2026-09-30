<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportQueryRequest;
use App\Services\ReportService;
use App\Services\TabularExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly TabularExportService $exports,
    ) {}

    public function index(ReportQueryRequest $request): JsonResponse
    {
        return response()->json(
            $this->reports->data($request->user(), $request->validated()),
        );
    }

    public function export(ReportQueryRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $export = $this->reports->export($request->user(), $filters);
        $columnKeys = $export['column_keys'];
        $rows = (static function () use ($export, $columnKeys): \Generator {
            foreach ($export['query']->cursor() as $row) {
                $values = method_exists($row, 'getAttributes')
                    ? $row->getAttributes()
                    : (array) $row;
                yield array_map(
                    static fn (string $key): mixed => $values[$key] ?? null,
                    $columnKeys,
                );
            }
        })();

        return $this->exports->download(
            $export['headers'],
            $rows,
            (string) ($filters['format'] ?? 'csv'),
            'diagen-'.$export['type'],
        );
    }
}
