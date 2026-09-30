<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PageDataRequest;
use App\Services\PageDataService;
use App\Services\TabularExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PageDataController extends Controller
{
    public function __construct(private readonly PageDataService $pages, private readonly TabularExportService $exports) {}

    public function show(PageDataRequest $request, string $page): JsonResponse
    {
        return response()->json($this->pages->page($page, (int) $request->user()->currentLocationId(), $request->validated(), $request->user()));
    }

    public function export(PageDataRequest $request, string $page): StreamedResponse
    {
        $export = $this->pages->export($page, (int) $request->user()->currentLocationId(), $request->validated(), $request->user());
        $format = $request->validated('format', 'csv') ?? 'csv';

        return $this->exports->download(array_values($export['columns']), $export['rows'], $format, 'diagen-'.$page);
    }
}
