<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\StockLot;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReturnRepository
{
    public function query(): Builder
    {
        return ProductReturn::query()->with(['product:id,code,name,unit', 'supplier:id,name', 'branch:id,name', 'lot:id,lot_no,expires_on']);
    }

    public function branches(): Collection
    {
        return Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')->orderBy('name')->get(['id', 'name', 'code']);
    }

    public function products(): Collection
    {
        return Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit']);
    }

    public function suppliers(): Collection
    {
        return Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function activeProduct(int $id): bool
    {
        return Product::query()->whereKey($id)->where('active', true)->exists();
    }

    public function activeBranch(int $id): bool
    {
        return Branch::query()->whereKey($id)->where('active', true)->where('code', '<>', 'CENTRAL')->exists();
    }

    public function activeSupplier(int $id): bool
    {
        return Supplier::query()->whereKey($id)->where('active', true)->exists();
    }

    public function eligibleLots(int $productId, int $location, ?int $supplierId): Collection
    {
        return StockLot::query()->where('product_id', $productId)->where('location_id', $location)->where('qty', '>', 0)
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
    }

    public function findCentralLot(int $productId, string $lotNo, ?string $expiresOn): ?StockLot
    {
        return StockLot::query()->where('product_id', $productId)->where('location_id', 0)->where('lot_no', $lotNo)
            ->when(
                $expiresOn === null,
                fn ($query) => $query->whereNull('expires_on'),
                fn ($query) => $query->whereDate('expires_on', $expiresOn),
            )->lockForUpdate()->first();
    }

    public function addToLot(int $id, float $quantity): void
    {
        StockLot::query()->whereKey($id)->increment('qty', $quantity);
    }

    public function createReturn(array $attributes): ProductReturn
    {
        return ProductReturn::query()->create($attributes);
    }

    public function loadCreatedRelations(ProductReturn $productReturn): ProductReturn
    {
        return $productReturn->load(['product:id,code,name,unit', 'supplier:id,name', 'lot:id,lot_no,expires_on']);
    }
}
