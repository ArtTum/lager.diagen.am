<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DashboardQueryRequest;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(DashboardQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();

        return response()->json(['data' => $this->dashboard->summary(
            $request->user(),
            isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
        )]);
    }
}
