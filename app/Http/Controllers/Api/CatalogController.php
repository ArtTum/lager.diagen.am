<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCatalogRecordRequest;
use App\Http\Requests\Api\StoreCategoryRequest;
use App\Http\Requests\Api\StoreRoleRequest;
use App\Http\Requests\Api\UpdateCatalogRecordRequest;
use App\Http\Requests\Api\UpdateRolePermissionsRequest;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function options(Request $request, string $kind): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->options($kind, $request->user()),
        ]);
    }

    public function show(Request $request, string $record, string $kind): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->show($kind, (int) $record, $request->user()),
        ]);
    }

    public function store(StoreCatalogRecordRequest $request, string $kind): JsonResponse
    {
        $record = $this->catalog->store($request->user(), (string) $request->ip(), $kind, $request->validated());

        return response()->json(['data' => $record], 201);
    }

    public function update(UpdateCatalogRecordRequest $request, string $record, string $kind): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->update(
                $request->user(),
                (string) $request->ip(),
                $kind,
                (int) $record,
                $request->validated(),
            ),
        ]);
    }

    public function deactivate(Request $request, string $record, string $kind): JsonResponse
    {
        $this->catalog->deactivate($request->user(), (string) $request->ip(), $kind, (int) $record);

        return response()->json(['message' => 'Գրառումն ապաակտիվացվեց։']);
    }

    public function createRole(StoreRoleRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->createRole(
                $request->user(),
                (string) $request->ip(),
                $request->validated(),
            ),
        ], 201);
    }

    public function rolePermissions(UpdateRolePermissionsRequest $request, string $record): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->updateRolePermissions(
                $request->user(),
                (string) $request->ip(),
                (int) $record,
                $request->validated(),
            ),
        ]);
    }

    public function createCategory(StoreCategoryRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->createCategory(
                $request->user(),
                (string) $request->ip(),
                $request->validated(),
            ),
        ], 201);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->categories()]);
    }

    public function deleteCategory(Request $request, string $category): JsonResponse
    {
        $this->catalog->deleteCategory($request->user(), (string) $request->ip(), (int) $category);

        return response()->json(['message' => 'Ապրանքային խումբը ջնջվեց։']);
    }
}
