<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ProductTraceRequest;
use App\Services\ProductTraceService;
use Illuminate\Http\JsonResponse;

class ProductTraceController extends Controller
{
    public function __construct(private readonly ProductTraceService $trace) {}

    public function show(ProductTraceRequest $request, string $product): JsonResponse
    {
        return response()->json(['data' => $this->trace->show($request->user(), (int) $product, $request->validated())]);
    }
}
