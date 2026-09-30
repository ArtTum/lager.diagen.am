<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Requests\Api\StoreReceiptRequest;
use App\Services\PurchasingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(private readonly PurchasingService $purchasing) {}

    public function options(string $kind): JsonResponse
    {
        return response()->json(['data' => $this->purchasing->options($kind)]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->purchasing->createOrder($request->user(), (string) $request->ip(), $request->validated())], 201);
    }

    public function approve(Request $request, string $order): JsonResponse
    {
        $this->purchasing->approve((int) $order, $request->user(), (string) $request->ip());

        return response()->json(['message' => 'Գնման պատվերը հաստատվեց։']);
    }

    public function receive(StoreReceiptRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->purchasing->receive($request->user(), (string) $request->ip(), $request->validated())], 201);
    }
}
