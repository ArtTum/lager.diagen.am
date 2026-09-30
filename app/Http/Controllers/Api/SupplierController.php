<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSupplierRequest;
use App\Http\Requests\Api\SupplierHistoryRequest;
use App\Http\Requests\Api\SupplierIndexRequest;
use App\Http\Requests\Api\UpdateSupplierRequest;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(SupplierIndexRequest $request): JsonResponse
    {
        return response()->json($this->suppliers->index($request->validated(), $request->user()->hasPermissionCode('purchases.view')));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->create($request->user(), (string) $request->ip(), $request->validated())], 201);
    }

    public function show(Request $request, string $supplier): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->show((int) $supplier, $request->user()->hasPermissionCode('purchases.view'))]);
    }

    public function history(SupplierHistoryRequest $request, string $supplier): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->history(
            (int) $supplier,
            $request->user()->hasPermissionCode('purchases.view'),
            (int) $request->user()->currentLocationId(),
            $request->validated(),
        )]);
    }

    public function update(UpdateSupplierRequest $request, string $supplier): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->update($request->user(), (string) $request->ip(), (int) $supplier, $request->validated())]);
    }

    public function destroy(Request $request, string $supplier): JsonResponse
    {
        $this->suppliers->deactivate($request->user(), (string) $request->ip(), (int) $supplier);

        return response()->json(['message' => 'Մատակարարը ապաակտիվացվեց։']);
    }
}
