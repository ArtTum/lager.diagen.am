<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RequestSuggestionsRequest;
use App\Http\Requests\Api\ReviewStockRequest;
use App\Http\Requests\Api\StoreStockRequest;
use App\Http\Requests\Api\UpdateStockRequestDraft;
use App\Services\StockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestController extends Controller
{
    public function __construct(private readonly StockRequestService $requests) {}

    public function show(Request $request, string $stockRequest): JsonResponse
    {
        return response()->json(['data' => $this->requests->details((int) $stockRequest, $request->user())]);
    }

    public function dispatchDocument(Request $request, string $stockRequest): JsonResponse
    {
        return response()->json(['data' => $this->requests->dispatchDocument((int) $stockRequest, $request->user())]);
    }

    public function suggestions(RequestSuggestionsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->suggestions($request->user(), (int) $request->validated('branch_id'))]);
    }

    public function store(StoreStockRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->requests->create($request->user(), (string) $request->ip(), $request->validated())], 201);
    }

    public function updateDraft(UpdateStockRequestDraft $request, string $stockRequest): JsonResponse
    {
        $this->requests->updateDraft((int) $stockRequest, $request->user(), (string) $request->ip(), $request->validated());

        return response()->json(['message' => 'Պահանջագիրը պահպանվեց։']);
    }

    public function review(ReviewStockRequest $request, string $stockRequest): JsonResponse
    {
        $this->requests->review((int) $stockRequest, $request->user(), (string) $request->ip(), $request->validated());

        return response()->json(['message' => 'Պահանջագրի որոշումը պահպանվեց։']);
    }

    public function transition(Request $request, string $stockRequest, string $action): JsonResponse
    {
        $this->requests->transition((int) $stockRequest, $action, $request->user(), (string) $request->ip());

        return response()->json(['message' => 'Պահանջագրի կարգավիճակը թարմացվեց։']);
    }
}
