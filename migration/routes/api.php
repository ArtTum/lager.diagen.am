<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\PageDataController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'active.user'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view');
    foreach ([
        'branches', 'products', 'stock', 'movements', 'expiry', 'purchases', 'receipts',
        'requests', 'transfers', 'returns', 'inventory', 'users', 'roles', 'audit',
    ] as $page) {
        Route::get('/pages/'.$page, [PageDataController::class, 'show'])
            ->defaults('page', $page)
            ->middleware('permission:'.$page.'.view');
    }
    Route::apiResource('/suppliers', SupplierController::class)
        ->middlewareFor('index', 'permission:suppliers.view')
        ->middlewareFor('store', 'permission:suppliers.create')
        ->middlewareFor('show', 'permission:suppliers.view')
        ->middlewareFor('update', 'permission:suppliers.edit')
        ->middlewareFor('destroy', 'permission:suppliers.delete');

    Route::post('/transfers', [TransferController::class, 'store'])->middleware('permission:transfers.create');
    Route::get('/catalog/transfers/options', [CatalogController::class, 'options'])->defaults('kind', 'transfers')->middleware('permission:transfers.view');
    Route::post('/transfers/{transfer}/approve', [TransferController::class, 'approve'])->middleware('permission:transfers.approve');
    Route::post('/transfers/{transfer}/ship', [TransferController::class, 'ship'])->middleware('permission:transfers.edit');
    Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('permission:transfers.edit');
    Route::get('/requests/{stockRequest}', [RequestController::class, 'show'])->middleware('permission:requests.view');
    Route::post('/requests', [RequestController::class, 'store'])->middleware('permission:requests.create');
    Route::put('/requests/{stockRequest}/draft', [RequestController::class, 'updateDraft'])->middleware('permission:requests.edit');
    Route::post('/requests/{stockRequest}/review', [RequestController::class, 'review'])->middleware('permission:requests.approve');
    Route::post('/requests/{stockRequest}/{action}', [RequestController::class, 'transition'])->middleware('permission:requests.edit');
    Route::get('/catalog/requests/options', [CatalogController::class, 'options'])->defaults('kind', 'transfers')->middleware('permission:requests.view');

    foreach (['branches', 'products', 'users'] as $kind) {
        Route::get('/catalog/'.$kind.'/options', [CatalogController::class, 'options'])
            ->defaults('kind', $kind)->middleware('permission:'.$kind.'.view');
        Route::get('/catalog/'.$kind.'/{record}', [CatalogController::class, 'show'])
            ->defaults('kind', $kind)->middleware('permission:'.$kind.'.view');
        Route::post('/catalog/'.$kind, [CatalogController::class, 'store'])
            ->defaults('kind', $kind)->middleware('permission:'.$kind.'.create');
        Route::put('/catalog/'.$kind.'/{record}', [CatalogController::class, 'update'])
            ->defaults('kind', $kind)->middleware('permission:'.$kind.'.edit');
        Route::delete('/catalog/'.$kind.'/{record}', [CatalogController::class, 'deactivate'])
            ->defaults('kind', $kind)->middleware('permission:'.$kind.'.delete');
    }
    Route::get('/catalog/roles/options', [CatalogController::class, 'options'])->defaults('kind', 'roles')->middleware('permission:roles.view');
    Route::get('/catalog/roles/{record}', [CatalogController::class, 'show'])->defaults('kind', 'roles')->middleware('permission:roles.view');
    Route::post('/roles', [CatalogController::class, 'createRole'])->middleware('permission:roles.create');
    Route::put('/roles/{record}/permissions', [CatalogController::class, 'rolePermissions'])->middleware('permission:roles.edit');
});
