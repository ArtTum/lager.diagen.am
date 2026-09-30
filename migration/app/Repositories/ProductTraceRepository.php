<?php

namespace App\Repositories;

use App\Models\Movement;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\StockRequestItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductTraceRepository
{
    public function product(int $id): Product
    {
        return Product::query()->with(['supplier:id,name'])->findOrFail($id);
    }

    public function lots(int $productId, int $location, bool $showCost, bool $showSupplier, int $page): LengthAwarePaginator
    {
        return StockLot::query()->leftJoin('branches as b', 'b.id', '=', 'stock_lots.location_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'stock_lots.supplier_id')
            ->where('stock_lots.product_id', $productId)->where('stock_lots.qty', '<>', 0)
            ->when($location > 0, fn ($query) => $query->where('stock_lots.location_id', $location))
            ->select('stock_lots.id', 'stock_lots.lot_no', 'stock_lots.expires_on', 'stock_lots.received_on', 'stock_lots.qty', 'stock_lots.bin_location')
            ->selectRaw("COALESCE(b.name, 'Կենտրոնական պահեստ') as location_name")
            ->when($showCost, fn ($query) => $query->addSelect('stock_lots.unit_cost'))
            ->when($showSupplier, fn ($query) => $query->addSelect('s.name as supplier_name'))
            ->orderBy('location_name')->orderByRaw('(stock_lots.expires_on IS NULL), stock_lots.expires_on')->orderBy('stock_lots.lot_no')
            ->paginate(10, ['*'], 'lots_page', $page);
    }

    public function movements(int $productId, int $location, int $page): LengthAwarePaginator
    {
        return Movement::query()->leftJoin('branches as f', 'f.id', '=', 'movements.from_location')
            ->leftJoin('branches as t', 't.id', '=', 'movements.to_location')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'movements.lot_id')
            ->leftJoin('users as u', 'u.id', '=', 'movements.actor_id')
            ->where('movements.product_id', $productId)
            ->when($location > 0, fn ($query) => $query->where(fn ($where) => $where->where('movements.from_location', $location)->orWhere('movements.to_location', $location)))
            ->select('movements.id', 'movements.movement_no', 'movements.type', 'movements.qty', 'movements.reference', 'movements.reason', 'movements.happened_at',
                'l.lot_no', 'u.name as actor_name')
            ->selectRaw("COALESCE(f.name, 'Կենտրոնական պահեստ') as from_name, COALESCE(t.name, 'Կենտրոնական պահեստ') as to_name")
            ->orderByDesc('movements.happened_at')->orderByDesc('movements.id')->paginate(10, ['*'], 'movements_page', $page);
    }

    public function requests(int $productId, int $location, int $page): LengthAwarePaginator
    {
        return StockRequestItem::query()->join('stock_requests as r', 'r.id', '=', 'request_items.request_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')->where('request_items.product_id', $productId)
            ->when($location > 0, fn ($query) => $query->where('r.branch_id', $location))
            ->select('r.request_no', 'r.created_at', 'r.status', 'r.urgency', 'b.name as branch_name',
                'request_items.requested_qty', 'request_items.approved_qty')
            ->orderByDesc('r.created_at')->orderByDesc('r.id')->paginate(10, ['*'], 'requests_page', $page);
    }
}
