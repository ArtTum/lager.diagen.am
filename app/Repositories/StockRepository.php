<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Movement;
use App\Models\Product;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockRepository
{
    public function matrixLocations(int $location): Collection
    {
        $branches = Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')
            ->when($location > 0, fn ($query) => $query->whereKey($location))
            ->orderBy('name')->get(['id', 'name', 'code']);
        if ($location === 0) {
            $branches->prepend((object) ['id' => 0, 'name' => 'Կենտրոնական պահեստ', 'code' => 'CENTRAL']);
        }

        return $branches;
    }

    public function matrixProductsQuery(string $search = ''): Builder
    {
        return Product::query()->where('active', true)
            ->when($search !== '', fn ($query) => $query->where(fn ($where) => $where->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('name')->orderBy('id');
    }

    public function matrixQuantities(array $productIds, array $locationIds): array
    {
        if (! $productIds || ! $locationIds) {
            return [];
        }

        return StockLot::query()->whereIn('product_id', $productIds)->whereIn('location_id', $locationIds)
            ->where('qty', '>', 0)->select('product_id', 'location_id')->selectRaw('SUM(qty) as quantity')
            ->groupBy('product_id', 'location_id')->get()
            ->mapWithKeys(fn ($row) => [$row->product_id.':'.$row->location_id => (float) $row->quantity])->all();
    }

    public function lotsForProduct(int $productId, int $location, bool $includeCosts = false): Collection
    {
        return StockLot::query()->where('product_id', $productId)->where('location_id', $location)
            ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')
            ->get($includeCosts
                ? ['id', 'lot_no', 'expires_on', 'qty', 'unit_cost', 'bin_location']
                : ['id', 'lot_no', 'expires_on', 'qty', 'bin_location']);
    }

    public function activeProduct(int $id): ?Product
    {
        return Product::query()->whereKey($id)->where('active', true)->first();
    }

    public function branchIsActive(int $location): bool
    {
        return Branch::query()->whereKey($location)->where('active', true)->where('code', '<>', 'CENTRAL')->exists();
    }

    public function branchIdForLocation(int $location): int
    {
        if ($location === 0) {
            return (int) Branch::query()->where('code', 'CENTRAL')->value('id');
        }

        return $location;
    }

    public function availableLots(int $product, int $location): Collection
    {
        return StockLot::query()->where('product_id', $product)->where('location_id', $location)->where('qty', '>', 0)
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', now()->toDateString()))
            ->orderByRaw('(expires_on IS NULL), expires_on, received_on, id')->lockForUpdate()->get();
    }

    public function expiredLots(int $product, int $location): Collection
    {
        return StockLot::query()->where('product_id', $product)->where('location_id', $location)->where('qty', '>', 0)
            ->whereNotNull('expires_on')->where('expires_on', '<', now()->toDateString())
            ->orderBy('expires_on')->orderBy('received_on')->orderBy('id')->lockForUpdate()->get();
    }

    public function lockedLot(int $id, int $product, int $location): ?StockLot
    {
        return StockLot::query()->whereKey($id)->where('product_id', $product)->where('location_id', $location)->lockForUpdate()->first();
    }

    public function matchingLot(int $product, int $location, string $lotNo, ?string $expiresOn): ?StockLot
    {
        return StockLot::query()->where('product_id', $product)->where('location_id', $location)->where('lot_no', $lotNo)
            ->when(
                $expiresOn === null,
                fn ($query) => $query->whereNull('expires_on'),
                fn ($query) => $query->whereDate('expires_on', $expiresOn),
            )->lockForUpdate()->first();
    }

    public function lotExists(int $product, int $location, string $lotNo, ?string $expiresOn): bool
    {
        return $this->matchingLot($product, $location, $lotNo, $expiresOn) !== null;
    }

    public function createLot(array $attributes): StockLot
    {
        return StockLot::query()->create($attributes);
    }

    public function increaseLot(int $id, float $qty): int
    {
        return StockLot::query()->whereKey($id)->increment('qty', $qty);
    }

    public function decreaseLot(int $id, float $qty): int
    {
        return StockLot::query()->whereKey($id)->where('qty', '>=', $qty)->decrement('qty', $qty);
    }

    public function movement(int $actor, string $type, int $product, ?int $lot, ?int $from, ?int $to, float $qty, float $cost, string $reference, string $reason): void
    {
        Movement::query()->create(['movement_no' => 'ՇԱՐԺ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(2))), 'type' => $type,
            'product_id' => $product, 'lot_id' => $lot, 'from_location' => $from, 'to_location' => $to, 'qty' => $qty, 'unit_cost' => $cost,
            'reference' => $reference, 'reason' => $reason, 'actor_id' => $actor, 'happened_at' => now(), 'created_at' => now()]);
    }

    public function audit(int $actor, string $action, string $entity, ?int $id, ?array $before, ?array $after, ?string $ip): void
    {
        AuditLog::query()->create(['actor_id' => $actor, 'action' => $action, 'entity' => $entity, 'entity_id' => $id,
            'before_data' => $before, 'after_data' => $after, 'ip_address' => $ip, 'created_at' => now()]);
    }
}
