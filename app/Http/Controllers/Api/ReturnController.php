<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PageDataRequest;
use App\Http\Requests\Api\StoreReturnRequest;
use App\Services\ReturnService;
use App\Services\TabularExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns, private readonly TabularExportService $exports) {}

    public function index(PageDataRequest $request): JsonResponse
    {
        return response()->json($this->returns->index($request->user(), $request->validated()));
    }

    public function export(PageDataRequest $request): StreamedResponse
    {
        $export = $this->returns->export($request->user(), $request->validated());

        return $this->exports->download($export['headers'], $export['rows'], $request->validated('format', 'csv') ?? 'csv', 'diagen-returns');
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->returns->options($request->user())]);
    }

    public function store(StoreReturnRequest $request): JsonResponse
    {
        $result = $this->returns->create($request->user(), (string) $request->ip(), $request->validated());

        return response()->json(['data' => $result, 'message' => 'Ապրանքների վերադարձը գրանցվեց։'], 201);
    }
}
