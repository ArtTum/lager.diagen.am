<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportQueryRequest;
use App\Services\ReportPdfService;
use App\Services\ReportService;
use App\Services\TabularExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly TabularExportService $exports,
        private readonly ReportPdfService $pdfs,
    ) {}

    public function index(ReportQueryRequest $request): JsonResponse
    {
        return response()->json(
            $this->reports->data($request->user(), $request->validated()),
        );
    }

    public function export(ReportQueryRequest $request): StreamedResponse|Response
    {
        $filters = $request->validated();
        $export = $this->reports->export($request->user(), $filters);
        $rows = $export['rows'];

        if (($filters['format'] ?? 'csv') === 'pdf') {
            return $this->pdfs->download(
                $export['headers'],
                $rows,
                ReportService::types()[$export['type']] ?? 'Հաշվետվություն',
                'diagen-'.$export['type'],
                array_filter([
                    'Սկսած' => $filters['from'] ?? null,
                    'Մինչև' => $filters['to'] ?? null,
                ]),
            );
        }

        return $this->exports->download(
            $export['headers'],
            $rows,
            (string) ($filters['format'] ?? 'csv'),
            'diagen-'.$export['type'],
        );
    }
}
