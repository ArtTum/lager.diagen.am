<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\Category;
use App\Models\InventoryLine;
use App\Models\Movement;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\PurchaseOrderItem;
use App\Models\ReceiptItem;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\Supplier;
use App\Models\TransferItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportRepository
{
    public function branches(): Collection
    {
        return Branch::query()->where('active', true)->where('code', '<>', 'CENTRAL')->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<string, Collection> */
    public function filterOptions(int $location, bool $showCosts, bool $showSuppliers): array
    {
        $options = [
            'products' => Product::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'branches' => $this->branches()->when($location > 0, fn (Collection $branches) => $branches->where('id', $location)->values()),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'movement_types' => Movement::query()->select('type')->distinct()->orderBy('type')->pluck('type'),
            'actors' => User::query()->where('active', true)
                ->when($location > 0, fn ($query) => $query->where('branch_id', $location))
                ->orderBy('name')->get(['id', 'name']),
        ];

        if ($showSuppliers && $location === 0) {
            $options['suppliers'] = Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']);
        }

        return $options;
    }

    /** @param array<string, mixed> $filters */
    public function query(string $type, string $from, string $to, ?int $branchId, array $filters = []): Builder
    {
        return match ($type) {
            'stock_by_location', 'central_stock', 'branch_stock', 'item_value', 'low_stock' => $this->stockQuery($type, $branchId, $filters),
            'receipts' => $this->receiptQuery($from, $to, $branchId, $filters),
            'issues' => $this->movementQuery($from, $to, $branchId, $filters, ['consumption', 'branch_out', 'transfer_sent', 'return_supplier']),
            'movements', 'product_movement' => $this->movementQuery($from, $to, $branchId, $filters),
            'branch_expense' => $this->usageQuery($from, $to, $branchId, $filters),
            'supplier_purchases', 'purchases_by_period' => $this->purchaseQuery($from, $to, $filters),
            'expired_lots', 'near_expiry' => $this->expiryQuery($type, $branchId, $filters),
            'returns' => $this->returnQuery($from, $to, $branchId, $filters),
            'inventory_differences' => $this->inventoryQuery($from, $to, $branchId, $filters),
            'branch_requests', 'rejected_requests' => $this->requestQuery($type, $from, $to, $branchId, $filters),
            'average_usage' => $this->averageQuery($branchId, $filters),
            default => abort(422, 'Հաշվետվության տեսակը վավեր չէ։'),
        };
    }

    /** @param array<string, mixed> $filters */
    private function stockQuery(string $type, ?int $branchId, array $filters): Builder
    {
        $reservedRequests = StockRequestItem::query()
            ->join('stock_requests as r', 'r.id', '=', 'request_items.request_id')
            ->whereIn('r.status', ['approved', 'partially_approved', 'collecting', 'ready_to_ship'])
            ->whereColumn('request_items.product_id', 'products.id')
            ->selectRaw('COALESCE(SUM(request_items.approved_qty), 0)');
        $reservedTransfers = TransferItem::query()
            ->join('transfers as t', 't.id', '=', 'transfer_items.transfer_id')
            ->join('branches as fb', 'fb.id', '=', 't.from_branch')
            ->where('t.status', 'approved')
            ->whereColumn('transfer_items.product_id', 'products.id')
            ->where(function ($query): void {
                $query->whereColumn('t.from_branch', 'l.location_id')
                    ->orWhere(function ($query): void {
                        $query->where('l.location_id', 0)->where('fb.code', 'CENTRAL');
                    });
            })
            ->selectRaw('COALESCE(SUM(transfer_items.qty), 0)');

        $query = Product::query()
            ->leftJoin('stock_lots as l', function ($join) use ($type, $branchId): void {
                $join->on('l.product_id', '=', 'products.id');

                if ($type === 'central_stock') {
                    $join->where('l.location_id', '=', 0);
                } elseif ($type === 'branch_stock') {
                    $join->where('l.location_id', '>', 0);
                    if ($branchId !== null && $branchId > 0) {
                        $join->where('l.location_id', '=', $branchId);
                    } elseif ($branchId === 0) {
                        $join->whereRaw('1 = 0');
                    }
                } elseif ($branchId === 0) {
                    $join->where('l.location_id', '=', 0);
                } elseif ($branchId !== null) {
                    $join->where('l.location_id', '=', $branchId);
                }
            })
            ->leftJoin('branches as b', 'b.id', '=', 'l.location_id')
            ->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'products.supplier_id')
            ->where('products.active', true)
            ->when($type === 'low_stock', fn ($builder) => $builder->where(function ($builder): void {
                $builder->whereNull('l.id')->orWhere('l.qty', '>', 0);
            }))
            ->when($filters['product_id'] ?? null, fn ($builder, $id) => $builder->where('products.id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($builder, $id) => $builder->where('products.supplier_id', $id))
            ->when($filters['category_id'] ?? null, fn ($builder, $id) => $builder->where('products.category_id', $id))
            ->groupBy('products.id', 'products.code', 'products.name', 'products.unit', 'products.min_qty', 'products.optimal_qty', 'products.max_qty', 'c.name', 's.name', 'l.location_id', 'b.name')
            ->selectRaw("COALESCE(b.name, CASE WHEN l.location_id = 0 THEN 'Կենտրոնական պահեստ' WHEN l.location_id IS NULL THEN 'Պահեստում չկա' ELSE 'Չնշված պահեստ' END) as branch_name")
            ->selectRaw('l.location_id, products.code, products.name as product_name, c.name as category, s.name as supplier, products.unit')
            ->selectRaw('COALESCE(SUM(l.qty), 0) as quantity, COALESCE(SUM(l.qty * l.unit_cost), 0) as value')
            ->selectRaw('products.min_qty, products.optimal_qty, products.max_qty')
            ->selectSub($reservedRequests, 'reserved_requests')
            ->selectSub($reservedTransfers, 'reserved_transfers')
            ->orderBy('branch_name')->orderBy('product_name');

        if ($type === 'low_stock') {
            $query->havingRaw('COALESCE(SUM(l.qty), 0) <= products.min_qty');
        }

        return $query;
    }

    /** @param array<string, mixed> $filters */
    private function receiptQuery(string $from, string $to, ?int $branchId, array $filters): Builder
    {
        return ReceiptItem::query()->join('receipts as r', 'r.id', '=', 'receipt_items.receipt_id')
            ->join('products as p', 'p.id', '=', 'receipt_items.product_id')
            ->join('suppliers as s', 's.id', '=', 'r.supplier_id')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'receipt_items.lot_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.location_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.received_by')
            ->whereBetween('r.received_on', [$from, $to])
            ->when($branchId !== null, fn ($query) => $query->where('l.location_id', $branchId))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('r.supplier_id', $id))
            ->when($filters['lot_no'] ?? null, fn ($query, $lot) => $query->where('l.lot_no', 'like', '%'.$lot.'%'))
            ->selectRaw("r.receipt_no as document_no, r.received_on as happened_on, COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, s.name as supplier, p.code, p.name as product_name, l.lot_no, l.expires_on, receipt_items.qty as quantity, receipt_items.unit_cost, (receipt_items.qty * receipt_items.unit_cost) as value, u.name as actor")
            ->orderByDesc('r.received_on')->orderBy('r.receipt_no')->orderBy('p.name');
    }

    /** @param array<string, mixed> $filters @param list<string>|null $types */
    private function movementQuery(string $from, string $to, ?int $branchId, array $filters, ?array $types = null): Builder
    {
        return Movement::query()->join('products as p', 'p.id', '=', 'movements.product_id')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'movements.lot_id')
            ->leftJoin('branches as f', 'f.id', '=', 'movements.from_location')
            ->leftJoin('branches as t', 't.id', '=', 'movements.to_location')
            ->leftJoin('suppliers as s', 's.id', '=', 'l.supplier_id')
            ->leftJoin('users as u', 'u.id', '=', 'movements.actor_id')
            ->whereDate('movements.happened_at', '>=', $from)->whereDate('movements.happened_at', '<=', $to)
            ->when($types !== null, fn ($query) => $query->whereIn('movements.type', $types))
            ->when($branchId !== null, fn ($query) => $query->where(function ($query) use ($branchId): void {
                $query->where('movements.from_location', $branchId)->orWhere('movements.to_location', $branchId);
            }))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('p.category_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('l.supplier_id', $id))
            ->when($filters['actor_id'] ?? null, fn ($query, $id) => $query->where('movements.actor_id', $id))
            ->when($filters['lot_no'] ?? null, fn ($query, $lot) => $query->where('l.lot_no', 'like', '%'.$lot.'%'))
            ->when($filters['movement_type'] ?? null, fn ($query, $type) => $query->where('movements.type', $type))
            ->selectRaw("movements.movement_no as document_no, movements.type as movement_type, movements.happened_at as happened_on, p.code, p.name as product_name, l.lot_no, CASE WHEN movements.from_location = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(f.name, 'Դրսից') END as from_name, CASE WHEN movements.to_location = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(t.name, 'Դուրս') END as to_name, movements.qty as quantity, movements.unit_cost, (movements.qty * movements.unit_cost) as value, movements.reference, movements.reason, s.name as supplier, u.name as actor")
            ->orderByDesc('movements.happened_at')->orderByDesc('movements.id');
    }

    /** @param array<string, mixed> $filters */
    private function usageQuery(string $from, string $to, ?int $branchId, array $filters): Builder
    {
        return Movement::query()->join('products as p', 'p.id', '=', 'movements.product_id')
            ->join('branches as b', 'b.id', '=', 'movements.from_location')
            ->where('movements.type', 'consumption')->where('movements.reason', 'Ներքին օգտագործում')
            ->whereDate('movements.happened_at', '>=', $from)->whereDate('movements.happened_at', '<=', $to)
            ->when($branchId !== null, fn ($query) => $query->where('movements.from_location', $branchId))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->groupBy('movements.from_location', 'b.id', 'b.name', 'p.id', 'p.code', 'p.name', 'p.unit')
            ->selectRaw("COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, p.code, p.name as product_name, p.unit, SUM(movements.qty) as used_qty, CASE WHEN SUM(movements.qty)>0 THEN SUM(movements.qty*movements.unit_cost)/SUM(movements.qty) ELSE 0 END as average_unit_cost, SUM(movements.qty*movements.unit_cost) as used_cost")
            ->orderBy('branch_name')->orderBy('product_name');
    }

    /** @param array<string, mixed> $filters */
    private function purchaseQuery(string $from, string $to, array $filters): Builder
    {
        return PurchaseOrderItem::query()->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')->join('products as p', 'p.id', '=', 'purchase_order_items.product_id')
            ->whereBetween('po.ordered_on', [$from, $to])
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('s.id', $id))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->selectRaw('po.order_no as document_no, po.ordered_on as happened_on, po.status, s.name as supplier, p.code, p.name as product_name, purchase_order_items.ordered_qty as ordered_quantity, purchase_order_items.received_qty as received_quantity, purchase_order_items.unit_cost, (purchase_order_items.ordered_qty * purchase_order_items.unit_cost) as value')
            ->orderByDesc('po.ordered_on')->orderBy('po.order_no')->orderBy('p.name');
    }

    /** @param array<string, mixed> $filters */
    private function expiryQuery(string $type, ?int $branchId, array $filters): Builder
    {
        $query = StockLot::query()->join('products as p', 'p.id', '=', 'stock_lots.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'stock_lots.location_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'stock_lots.supplier_id')
            ->where('p.expiry_control', true)->where('stock_lots.qty', '>', 0)->whereNotNull('stock_lots.expires_on')
            ->when($branchId !== null, fn ($builder) => $builder->where('stock_lots.location_id', $branchId))
            ->when($filters['product_id'] ?? null, fn ($builder, $id) => $builder->where('p.id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($builder, $id) => $builder->where('stock_lots.supplier_id', $id))
            ->when($filters['lot_no'] ?? null, fn ($builder, $lot) => $builder->where('stock_lots.lot_no', 'like', '%'.$lot.'%'));

        if ($type === 'expired_lots') {
            $query->whereDate('stock_lots.expires_on', '<', now()->toDateString());
        } else {
            $query->whereDate('stock_lots.expires_on', '>=', now()->toDateString())
                ->whereDate('stock_lots.expires_on', '<=', now()->addDays(180)->toDateString());
        }

        return $query->selectRaw("COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, p.code, p.name as product_name, stock_lots.lot_no, stock_lots.expires_on, stock_lots.qty as quantity, s.name as supplier, stock_lots.bin_location")
            ->orderBy('stock_lots.expires_on')->orderBy('p.name');
    }

    /** @param array<string, mixed> $filters */
    private function returnQuery(string $from, string $to, ?int $branchId, array $filters): Builder
    {
        return ProductReturn::query()->join('products as p', 'p.id', '=', 'returns.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'returns.from_location')
            ->leftJoin('suppliers as s', 's.id', '=', 'returns.supplier_id')
            ->leftJoin('stock_lots as l', 'l.id', '=', 'returns.lot_id')
            ->leftJoin('users as u', 'u.id', '=', 'returns.actor_id')
            ->whereDate('returns.created_at', '>=', $from)->whereDate('returns.created_at', '<=', $to)
            ->when($branchId !== null, fn ($query) => $query->where('returns.from_location', $branchId))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('returns.supplier_id', $id))
            ->selectRaw("returns.return_no as document_no, returns.created_at as happened_on, returns.direction, COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, s.name as supplier, p.code, p.name as product_name, l.lot_no, returns.qty as quantity, returns.reason, u.name as actor")
            ->orderByDesc('returns.created_at')->orderByDesc('returns.id');
    }

    /** @param array<string, mixed> $filters */
    private function inventoryQuery(string $from, string $to, ?int $branchId, array $filters): Builder
    {
        return InventoryLine::query()->join('inventory_sessions as i', 'i.id', '=', 'inventory_lines.session_id')
            ->join('products as p', 'p.id', '=', 'inventory_lines.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'i.location_id')->leftJoin('stock_lots as l', 'l.id', '=', 'inventory_lines.lot_id')
            ->whereDate('i.started_at', '>=', $from)->whereDate('i.started_at', '<=', $to)
            ->whereNotNull('inventory_lines.counted_qty')
            ->when($branchId !== null, fn ($query) => $query->where('i.location_id', $branchId))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->selectRaw("i.inventory_no as document_no, i.started_at as happened_on, i.status, COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, p.code, p.name as product_name, l.lot_no, inventory_lines.expected_qty, inventory_lines.counted_qty, (inventory_lines.counted_qty - inventory_lines.expected_qty) as difference, inventory_lines.difference_reason")
            ->orderByDesc('i.started_at')->orderBy('p.name');
    }

    /** @param array<string, mixed> $filters */
    private function requestQuery(string $type, string $from, string $to, ?int $branchId, array $filters): Builder
    {
        $query = StockRequestItem::query()->join('stock_requests as r', 'r.id', '=', 'request_items.request_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')->join('products as p', 'p.id', '=', 'request_items.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.requested_by')
            ->whereDate('r.created_at', '>=', $from)->whereDate('r.created_at', '<=', $to)
            ->when($branchId !== null, fn ($builder) => $builder->where('r.branch_id', $branchId))
            ->when($filters['product_id'] ?? null, fn ($builder, $id) => $builder->where('p.id', $id));

        if ($type === 'rejected_requests') {
            $query->where('r.status', 'rejected');
        }

        $receivedQuantity = Movement::query()->where('movements.type', 'branch_in')
            ->whereColumn('movements.reference', 'r.request_no')
            ->whereColumn('movements.product_id', 'request_items.product_id')
            ->selectRaw('COALESCE(SUM(movements.qty), 0)');

        return $query->selectRaw('r.request_no as document_no, r.created_at as happened_on, r.status, r.urgency, b.name as branch_name, p.code, p.name as product_name, request_items.requested_qty, request_items.approved_qty, r.rejection_reason, u.name as actor')
            ->selectSub($receivedQuantity, 'received_qty')
            ->orderByDesc('r.created_at')->orderBy('r.request_no')->orderBy('p.name');
    }

    /** @param array<string, mixed> $filters */
    private function averageQuery(?int $branchId, array $filters): Builder
    {
        return Movement::query()->join('products as p', 'p.id', '=', 'movements.product_id')
            ->join('branches as b', 'b.id', '=', 'movements.from_location')
            ->where('movements.type', 'consumption')->where('movements.reason', 'Ներքին օգտագործում')
            ->where('movements.happened_at', '>=', now()->startOfDay()->subDays(90))
            ->when($branchId !== null, fn ($query) => $query->where('movements.from_location', $branchId))
            ->when($filters['product_id'] ?? null, fn ($query, $id) => $query->where('p.id', $id))
            ->groupBy('movements.from_location', 'b.id', 'b.name', 'p.id', 'p.code', 'p.name')
            ->selectRaw("COALESCE(b.name, 'Կենտրոնական պահեստ') as branch_name, p.code, p.name as product_name, SUM(movements.qty) as consumed, SUM(movements.qty)/3 as monthly_average")
            ->orderBy('branch_name')->orderBy('product_name');
    }

    public function stockSummary(int $location): array
    {
        $totals = Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->selectRaw('COALESCE(SUM(l.qty),0) as quantity, COALESCE(SUM(l.qty*l.unit_cost),0) as value')->first();
        $belowMinimum = Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->groupBy('products.id', 'products.min_qty')
            ->havingRaw('COALESCE(SUM(l.qty),0) <= products.min_qty')->get(['products.id']);
        $requests = StockRequest::query()->whereIn('status', ['sent', 'review'])
            ->when($location > 0, fn ($query) => $query->where('branch_id', $location))->count();

        return [
            'quantity' => (float) $totals->quantity,
            'value' => (float) $totals->value,
            'below_minimum' => $belowMinimum->count(),
            'open_requests' => $requests,
        ];
    }
}
