<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PageDataController;
use App\Http\Controllers\Api\ProductLabelController;
use App\Http\Controllers\Api\ProductTraceController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RequestController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\StockController;
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
        if (! in_array($page, ['inventory', 'movements', 'returns'], true)) {
            Route::get('/pages/'.$page.'/export', [PageDataController::class, 'export'])
                ->defaults('page', $page)
                ->middleware('permission:'.$page.'.export');
        }
    }
    Route::apiResource('/suppliers', SupplierController::class)
        ->middlewareFor('index', 'permission:suppliers.view')
        ->middlewareFor('store', 'permission:suppliers.create')
        ->middlewareFor('show', 'permission:suppliers.view')
        ->middlewareFor('update', 'permission:suppliers.edit')
        ->middlewareFor('destroy', 'permission:suppliers.delete');
    Route::get('/suppliers/{supplier}/history', [SupplierController::class, 'history'])->middleware('permission:suppliers.view');

    Route::post('/transfers', [TransferController::class, 'store'])->middleware('permission:transfers.create');
    Route::get('/catalog/transfers/options', [CatalogController::class, 'options'])->defaults('kind', 'transfers')->middleware('permission:transfers.view');
    Route::post('/transfers/{transfer}/approve', [TransferController::class, 'approve'])->middleware('permission:transfers.approve');
    Route::post('/transfers/{transfer}/ship', [TransferController::class, 'ship'])->middleware('permission:transfers.edit');
    Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->middleware('permission:transfers.edit');
    Route::get('/requests/suggestions', [RequestController::class, 'suggestions'])->middleware('permission:requests.create');
    Route::get('/requests/{stockRequest}', [RequestController::class, 'show'])->middleware('permission:requests.view');
    Route::get('/requests/{stockRequest}/dispatch-document', [RequestController::class, 'dispatchDocument'])->middleware('permission:requests.view');
    Route::post('/requests', [RequestController::class, 'store'])->middleware('permission:requests.create');
    Route::put('/requests/{stockRequest}/draft', [RequestController::class, 'updateDraft'])->middleware('permission:requests.edit');
    Route::post('/requests/{stockRequest}/review', [RequestController::class, 'review'])->middleware('permission:requests.approve');
    Route::post('/requests/{stockRequest}/{action}', [RequestController::class, 'transition'])->middleware('permission:requests.edit');
    Route::get('/catalog/requests/options', [CatalogController::class, 'options'])->defaults('kind', 'requests')->middleware('permission:requests.view');
    Route::get('/purchasing/purchases/options', [PurchaseController::class, 'options'])->defaults('kind', 'purchases')->middleware('permission:purchases.view');
    Route::get('/purchasing/receipts/options', [PurchaseController::class, 'options'])->defaults('kind', 'receipts')->middleware('permission:receipts.view');
    Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.create');
    Route::post('/purchases/{order}/approve', [PurchaseController::class, 'approve'])->middleware('permission:purchases.approve');
    Route::post('/receipts', [PurchaseController::class, 'receive'])->middleware('permission:receipts.create');
    Route::get('/catalog/stock/options', [CatalogController::class, 'options'])->defaults('kind', 'stock')->middleware('permission:stock.view');
    Route::post('/categories', [CatalogController::class, 'createCategory'])->middleware('permission:products.create');
    Route::delete('/categories/{category}', [CatalogController::class, 'deleteCategory'])->middleware('permission:products.delete');
    Route::get('/products/{product}/history', [ProductTraceController::class, 'show'])->middleware('permission:products.view');
    Route::get('/products/{product}/label', [ProductLabelController::class, 'show'])->middleware('permission:products.view');
    Route::get('/stock/matrix', [StockController::class, 'matrix'])->middleware('permission:stock.view');
    Route::get('/stock/matrix/export', [StockController::class, 'exportMatrix'])->middleware('permission:stock.export');
    Route::get('/stock/lots', [StockController::class, 'lots'])->middleware('permission:stock.view');
    Route::post('/stock/consume', [StockController::class, 'consume'])->middleware('permission:stock.create');
    Route::post('/stock/adjust', [StockController::class, 'adjust'])->middleware('permission:stock.edit');
    Route::get('/inventory', [InventoryController::class, 'index'])->middleware('permission:inventory.view');
    Route::get('/inventory/export', [InventoryController::class, 'export'])->middleware('permission:inventory.export');
    Route::get('/inventory/{session}', [InventoryController::class, 'show'])->middleware('permission:inventory.view');
    Route::get('/inventory/{session}/act', [InventoryController::class, 'act'])->middleware('permission:inventory.view');
    Route::post('/inventory', [InventoryController::class, 'store'])->middleware('permission:inventory.create');
    Route::put('/inventory/{session}/count', [InventoryController::class, 'count'])->middleware('permission:inventory.edit');
    Route::post('/inventory/{session}/approve', [InventoryController::class, 'approve'])->middleware('permission:inventory.approve');
    Route::get('/returns', [ReturnController::class, 'index'])->middleware('permission:returns.view');
    Route::get('/returns/export', [ReturnController::class, 'export'])->middleware('permission:returns.export');
    Route::get('/returns/options', [ReturnController::class, 'options'])->middleware('permission:returns.view');
    Route::post('/returns', [ReturnController::class, 'store'])->middleware('permission:returns.create');
    Route::get('/movements', [MovementController::class, 'index'])->middleware('permission:movements.view');
    Route::get('/movements/export', [MovementController::class, 'export'])->middleware('permission:movements.export');
    Route::post('/movements/{movement}/reverse', [MovementController::class, 'reverse'])->middleware('permission:movements.edit');
    Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.view');
    Route::post('/notifications/read', [NotificationController::class, 'markRead'])->middleware('permission:notifications.view');
    Route::get('/reports', [ReportController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/reports/export', [ReportController::class, 'export'])->middleware('permission:reports.export');

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
