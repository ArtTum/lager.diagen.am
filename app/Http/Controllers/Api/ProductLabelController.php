<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductLabelService;
use Illuminate\Http\JsonResponse;

class ProductLabelController extends Controller
{
    public function __construct(private readonly ProductLabelService $labels) {}

    public function show(string $product): JsonResponse
    {
        return response()->json(['data' => $this->labels->make((int) $product)]);
    }
}
