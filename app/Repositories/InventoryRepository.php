<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\InventoryLine;
use App\Models\InventorySession;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InventoryRepository
{
    public function listQuery(int $location, string $search = ''): Builder
    {
        return InventorySession::query()->with(['location:id,name', 'starter:id,name', 'approver:id,name'])
            ->withCount(['lines', 'lines as counted_lines_count' => fn ($lines) => $lines->whereNotNull('counted_qty')])
            ->when($location > 0, fn ($query) => $query->where('location_id', $location))
            ->when($search !== '', fn ($query) => $query->where(fn ($where) => $where->where('inventory_no', 'like', "%{$search}%")
                ->orWhereHas('location', fn ($branch) => $branch->where('name', 'like', "%{$search}%")))
            )->orderByDesc('id');
    }

    public function findWithLines(int $id): InventorySession
    {
        return InventorySession::query()->with([
            'location:id,name', 'starter:id,name', 'approver:id,name',
            'lines.product:id,code,name,unit,lot_control,expiry_control,purchase_price',
            'lines.lot:id,lot_no,expires_on,qty', 'lines.countedSupplier:id,name',
        ])->findOrFail($id);
    }

    public function findForAct(int $id): InventorySession
    {
        return InventorySession::query()->with([
            'location:id,name', 'starter:id,name', 'approver:id,name',
            'lines.product:id,code,name,unit', 'lines.lot:id,lot_no',
        ])->findOrFail($id);
    }

    public function activeSuppliers(): Collection
    {
        return Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function quantityForProductAtLocation(int $productId, int $locationId): float
    {
        return (float) StockLot::query()->where('product_id', $productId)->where('location_id', $locationId)->sum('qty');
    }

    public function activeLocation(int $location): bool
    {
        return $location === 0
            ? Branch::query()->where('code', 'CENTRAL')->where('active', true)->exists()
            : Branch::query()->whereKey($location)->where('active', true)->exists();
    }

    public function activeLocations(int $actorLocation): Collection
    {
        if ($actorLocation > 0) {
            return Branch::query()->whereKey($actorLocation)->where('active', true)->get(['id', 'name', 'code']);
        }
        $branches = Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')->orderBy('name')->get(['id', 'name', 'code']);
        $branches->prepend((object) ['id' => 0, 'name' => 'Կենտրոնական պահեստ', 'code' => 'CENTRAL']);

        return $branches;
    }

    public function createSession(array $attributes): InventorySession
    {
        return InventorySession::query()->create($attributes);
    }

    public function sessionWithLineCount(InventorySession $session): InventorySession
    {
        return $session->fresh()->loadCount('lines');
    }

    public function snapshotLots(int $location, bool $lock = false): Collection
    {
        $query = StockLot::query()->where('location_id', $location)->where('qty', '<>', 0)
            ->orderBy('product_id')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['id', 'product_id', 'qty']);
    }

    public function activeProductsWithoutStock(int $location): Collection
    {
        return Product::query()->where('active', true)->whereDoesntHave('lots', fn ($query) => $query->where('location_id', $location)->where('qty', '>', 0))
            ->orderBy('id')->get(['id']);
    }

    public function createLines(array $lines): void
    {
        InventoryLine::query()->insert($lines);
    }

    public function lockSession(int $id, array $statuses): ?InventorySession
    {
        return InventorySession::query()->whereKey($id)->whereIn('status', $statuses)->lockForUpdate()->first();
    }

    public function lockLines(int $sessionId): Collection
    {
        return InventoryLine::query()->with(['product', 'lot'])->where('session_id', $sessionId)->orderBy('id')->lockForUpdate()->get();
    }

    public function saveCount(InventoryLine $line, array $attributes): void
    {
        $line->fill($attributes)->save();
    }

    public function linkLineToLot(InventoryLine $line, int $lotId): void
    {
        $line->forceFill(['lot_id' => $lotId])->save();
    }

    public function setLotQuantity(StockLot $lot, float $quantity): StockLot
    {
        $lot->forceFill(['qty' => $quantity])->save();

        return $lot;
    }

    public function updateSession(InventorySession $session, array $attributes): void
    {
        $session->forceFill($attributes)->save();
    }

    public function lockedLot(int $id): ?StockLot
    {
        return StockLot::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function createLot(array $attributes): StockLot
    {
        return StockLot::query()->create($attributes);
    }

    public function activeSupplier(int $id): bool
    {
        return Supplier::query()->whereKey($id)->where('active', true)->exists();
    }
}
