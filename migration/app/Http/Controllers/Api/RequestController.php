<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReviewStockRequest;
use App\Http\Requests\Api\StoreStockRequest;
use App\Http\Requests\Api\UpdateStockRequestDraft;
use App\Services\StockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestController extends Controller
{
    public function __construct(private readonly StockRequestService $requests) {}

    public function show(Request $request, int $stockRequest): JsonResponse
    {
        return response()->json(['data' => $this->requests->details($stockRequest, $request->user())]);
    }

    public function store(StoreStockRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->create($request->user(), (string) $request->ip(), $request->validated())], 201);
    }

    public function updateDraft(UpdateStockRequestDraft $request, int $stockRequest): JsonResponse
    {
        $this->requests->updateDraft($stockRequest, $request->user(), (string) $request->ip(), $request->validated());
        return response()->json(['message' => 'Պահանջագիրը պահպանվեց։']);
    }

    public function review(ReviewStockRequest $request, int $stockRequest): JsonResponse
    {
        $this->requests->review($stockRequest, $request->user(), (string) $request->ip(), $request->validated());
        return response()->json(['message' => 'Պահանջագրի որոշումը պահպանվեց։']);
    }

    public function transition(Request $request, int $stockRequest, string $action): JsonResponse
    {
        $this->requests->transition($stockRequest, $action, $request->user(), (string) $request->ip());
        return response()->json(['message' => 'Պահանջագրի կարգավիճակը թարմացվեց։']);
    }
}
