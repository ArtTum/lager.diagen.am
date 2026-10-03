<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventorySession;
use App\Models\Movement;
use App\Models\Product;
use App\Models\ProductReturn;
use App\Models\PurchaseOrder;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class PageDataRepository
{
    /** @return array{0: array, 1: Builder, 2: array} */
    public function definition(string $page, int $location, array $filters = [], bool $showCosts = false): array
    {
        $branches = Branch::query()->select('branches.id', 'branches.name', 'branches.code', 'branches.address', 'branches.manager', 'branches.phone', 'branches.active')->orderBy('branches.name');
        $products = Product::query()->where('products.active', true)->leftJoin('categories as c', 'c.id', '=', 'products.category_id')->leftJoin('suppliers as s', 's.id', '=', 'products.supplier_id')
            ->select('products.id', 'products.code', 'products.name', 'c.name as category', 'products.unit', 'products.min_qty', 'products.active')->orderBy('products.name');
        $stock = Product::query()->leftJoin('stock_lots as l', function ($join) use ($location): void {
            $join->on('l.product_id', '=', 'products.id')->where('l.location_id', '=', $location);
        })->where('products.active', true)->groupBy('products.id', 'products.code', 'products.name', 'products.unit', 'products.min_qty')
            ->select('products.id', 'products.code', 'products.name', 'products.unit', 'products.min_qty')
            ->selectRaw('COALESCE(SUM(l.qty),0) as quantity')
            ->when($showCosts, fn ($query) => $query->selectRaw('COALESCE(SUM(l.qty*l.unit_cost),0) as stock_value'))
            ->orderBy('products.name');
        $movements = Movement::query()->join('products as p', 'p.id', '=', 'movements.product_id')
            ->leftJoin('branches as f', 'f.id', '=', 'movements.from_location')->leftJoin('branches as t', 't.id', '=', 'movements.to_location')
            ->when($location > 0, fn ($query) => $query->where(fn ($where) => $where->where('movements.from_location', $location)->orWhere('movements.to_location', $location)))
            ->select('movements.id', 'movements.movement_no', 'movements.type', 'p.name as product', 'movements.qty')
            ->when($showCosts, fn ($query) => $query->addSelect('movements.unit_cost'))
            ->selectRaw("CASE WHEN movements.from_location = 0 THEN 'Կենտրոնական պահեստ' WHEN movements.from_location IS NULL THEN 'Դրսից' ELSE COALESCE(f.name, 'Անհայտ պահեստ') END as from_branch")
            ->selectRaw("CASE WHEN movements.to_location = 0 THEN 'Կենտրոնական պահեստ' WHEN movements.to_location IS NULL THEN 'Դուրս' ELSE COALESCE(t.name, 'Անհայտ պահեստ') END as to_branch")
            ->addSelect(
                'movements.reference', 'movements.happened_at')->orderByDesc('movements.id');

        $definitions = [
            'branches' => [['name' => 'Մասնաճյուղ', 'code' => 'Կոդ', 'address' => 'Հասցե', 'manager' => 'Պատասխանատու', 'phone' => 'Հեռախոս', 'active' => 'Կարգավիճակ'], $branches->when($location > 0, fn ($query) => $query->where('branches.id', $location)), ['branches.name', 'branches.code', 'branches.address', 'branches.manager', 'branches.phone']],
            'products' => [['code' => 'Կոդ', 'name' => 'Ապրանք', 'category' => 'Ապրանքի տեսակ', 'unit' => 'Միավոր', 'min_qty' => 'MIN', 'active' => 'Կարգավիճակ'],
                $products->when(trim((string) ($filters['barcode'] ?? '')) !== '', function ($query) use ($filters): void {
                    $barcode = trim((string) $filters['barcode']);
                    $query->where(fn ($match) => $match->where('products.barcode', $barcode)->orWhere('products.code', $barcode));
                }), ['products.code', 'products.barcode', 'products.name', 'c.name']],
            'stock' => [['code' => 'Կոդ', 'name' => 'Ապրանք', 'quantity' => 'Մնացորդ', 'unit' => 'Միավոր', 'min_qty' => 'MIN', ...($showCosts ? ['stock_value' => 'Արժեք'] : [])], $stock, ['products.code', 'products.name']],
            'movements' => [['movement_no' => 'Փաստաթուղթ', 'type' => 'Տեսակ', 'product' => 'Ապրանք', 'qty' => 'Քանակ', ...($showCosts ? ['unit_cost' => 'Միավորի արժեք'] : []), 'from_branch' => 'Ումից', 'to_branch' => 'Ուր', 'reference' => 'Հղում', 'happened_at' => 'Ամսաթիվ'], $movements, ['movements.movement_no', 'p.name', 'movements.reference', 'movements.type']],
            'expiry' => [['code' => 'Կոդ', 'product' => 'Ապրանք', 'lot_no' => 'LOT', 'expires_on' => 'Պիտանի է մինչև', 'days_left' => 'Մնացել է', 'location' => 'Պահեստ', 'supplier' => 'Մատակարար', 'qty' => 'Քանակ', 'unit' => 'Միավոր'],
                StockLot::query()->join('products as p', 'p.id', '=', 'stock_lots.product_id')->leftJoin('branches as b', 'b.id', '=', 'stock_lots.location_id')->leftJoin('suppliers as s', 's.id', '=', 'stock_lots.supplier_id')
                    ->where('p.expiry_control', true)->where('stock_lots.qty', '>', 0)->whereNotNull('stock_lots.expires_on')
                    ->when($location > 0, fn ($query) => $query->where('stock_lots.location_id', $location))
                    ->when(($filters['threshold'] ?? '') === 'expired', fn ($query) => $query->whereDate('stock_lots.expires_on', '<', now()->toDateString()))
                    ->when(is_numeric($filters['threshold'] ?? null), fn ($query) => $query->whereDate('stock_lots.expires_on', '>=', now()->toDateString())->whereDate('stock_lots.expires_on', '<=', now()->addDays((int) $filters['threshold'])->toDateString()))
                    ->select('stock_lots.id', 'p.code', 'p.name as product', 'stock_lots.lot_no', 'stock_lots.expires_on', 's.name as supplier', 'stock_lots.qty', 'p.unit')
                    ->selectRaw("CASE WHEN stock_lots.location_id = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(b.name, 'Անհայտ պահեստ') END as location")
                    ->selectRaw('DATEDIFF(stock_lots.expires_on, ?) as days_left', [now()->toDateString()])->orderBy('stock_lots.expires_on'), ['p.code', 'p.name', 'stock_lots.lot_no', 'b.name', 's.name']],
            'purchases' => [['order_no' => 'Պատվեր', 'supplier' => 'Մատակարար', 'status' => 'Կարգավիճակ', 'ordered_on' => 'Պատվերի օր', 'expected_on' => 'Սպասվող օր', 'created_at' => 'Ստեղծվել է'],
                PurchaseOrder::query()->join('suppliers as s', 's.id', '=', 'purchase_orders.supplier_id')->select('purchase_orders.id', 'purchase_orders.order_no', 's.name as supplier', 'purchase_orders.status', 'purchase_orders.ordered_on', 'purchase_orders.expected_on', 'purchase_orders.created_at')
                    ->withExists(['items as has_remaining_items' => fn ($items) => $items->whereColumn('purchase_order_items.received_qty', '<', 'purchase_order_items.ordered_qty')])
                    ->orderByDesc('purchase_orders.id'), ['purchase_orders.order_no', 's.name', 'purchase_orders.status']],
            'receipts' => [['receipt_no' => 'Մուտք', 'supplier' => 'Մատակարար', 'invoice_no' => 'Հաշիվ', 'received_on' => 'Ստացման օր', 'note' => 'Նշում'],
                Receipt::query()->join('suppliers as s', 's.id', '=', 'receipts.supplier_id')->select('receipts.id', 'receipts.receipt_no', 's.name as supplier', 'receipts.invoice_no', 'receipts.received_on', 'receipts.note')->orderByDesc('receipts.id'), ['receipts.receipt_no', 's.name', 'receipts.invoice_no']],
            'requests' => [['request_no' => 'Պահանջագիր', 'branch' => 'Մասնաճյուղ', 'status' => 'Կարգավիճակ', 'urgency' => 'Հրատապություն', 'created_at' => 'Ստեղծվել է'],
                StockRequest::query()->join('branches as b', 'b.id', '=', 'stock_requests.branch_id')->when($location > 0, fn ($query) => $query->where('stock_requests.branch_id', $location))
                    ->select('stock_requests.id', 'stock_requests.branch_id', 'stock_requests.requested_by', 'stock_requests.request_no', 'b.name as branch', 'stock_requests.status', 'stock_requests.urgency', 'stock_requests.created_at')->orderByDesc('stock_requests.id'),
                ['stock_requests.request_no', 'b.name', 'stock_requests.status']],
            'transfers' => [['transfer_no' => 'Տեղափոխում', 'from_branch' => 'Ումից', 'to_branch' => 'Ուր', 'status' => 'Կարգավիճակ', 'reason' => 'Պատճառ', 'created_at' => 'Ստեղծվել է'],
                Transfer::query()->join('branches as f', 'f.id', '=', 'transfers.from_branch')->join('branches as b', 'b.id', '=', 'transfers.to_branch')
                    ->when($location > 0, fn ($query) => $query->where(fn ($where) => $where->where('transfers.from_branch', $location)->orWhere('transfers.to_branch', $location)))
                    ->select('transfers.id', 'transfers.from_branch as from_branch_id', 'transfers.to_branch as to_branch_id', 'transfers.transfer_no', 'f.name as from_branch', 'b.name as to_branch', 'transfers.status', 'transfers.reason', 'transfers.created_at')->orderByDesc('transfers.id'),
                ['transfers.transfer_no', 'f.name', 'b.name', 'transfers.status']],
            'returns' => [['return_no' => 'Վերադարձ', 'direction' => 'Ուղղություն', 'product' => 'Ապրանք', 'qty' => 'Քանակ', 'reason' => 'Պատճառ', 'created_at' => 'Ամսաթիվ'],
                ProductReturn::query()->join('products as p', 'p.id', '=', 'returns.product_id')->when($location > 0, fn ($query) => $query->where('returns.from_location', $location))
                    ->select('returns.id', 'returns.return_no', 'returns.direction', 'p.name as product', 'returns.qty', 'returns.reason', 'returns.created_at')->orderByDesc('returns.id'), ['returns.return_no', 'p.name', 'returns.reason']],
            'inventory' => [['inventory_no' => 'Գույքագրում', 'location' => 'Պահեստ', 'status' => 'Կարգավիճակ', 'started_at' => 'Սկսվել է', 'closed_at' => 'Փակվել է'],
                InventorySession::query()->leftJoin('branches as b', 'b.id', '=', 'inventory_sessions.location_id')->when($location > 0, fn ($query) => $query->where('inventory_sessions.location_id', $location))
                    ->select('inventory_sessions.id', 'inventory_sessions.inventory_no', 'inventory_sessions.location_id', 'inventory_sessions.status', 'inventory_sessions.started_at', 'inventory_sessions.closed_at')
                    ->selectRaw("CASE WHEN inventory_sessions.location_id = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(b.name, 'Անհայտ պահեստ') END as location")
                    ->orderByDesc('inventory_sessions.id'),
                ['inventory_sessions.inventory_no', 'b.name', 'inventory_sessions.status']],
            'users' => [['name' => 'Օգտատեր', 'email' => 'Էլ. փոստ', 'role' => 'Դեր', 'branch' => 'Մասնաճյուղ', 'active' => 'Կարգավիճակ'],
                User::query()->join('roles as r', 'r.id', '=', 'users.role_id')->leftJoin('branches as b', 'b.id', '=', 'users.branch_id')
                    ->when($location > 0, fn ($query) => $query->where('users.branch_id', $location))
                    ->select('users.id', 'users.name', 'users.email', 'r.title as role', 'b.name as branch', 'users.active')->orderBy('users.name'), ['users.name', 'users.email', 'r.title', 'b.name']],
            'roles' => [['title' => 'Դեր', 'name' => 'Ներքին անուն', 'permissions_count' => 'Իրավունքների քանակ'],
                Role::query()->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'roles.id')->select('roles.id', 'roles.title', 'roles.name')
                    ->selectRaw('COUNT(rp.permission_id) as permissions_count')->groupBy('roles.id', 'roles.title', 'roles.name')->orderBy('roles.title'), ['roles.title', 'roles.name']],
            'audit' => [['created_at' => 'Ամսաթիվ', 'actor' => 'Օգտատեր', 'action' => 'Գործողություն', 'entity' => 'Բաժին', 'entity_id' => 'Գրառում', 'before_data' => 'Նախկին տվյալներ', 'after_data' => 'Նոր տվյալներ', 'ip_address' => 'Ցանցային հասցե'],
                AuditLog::query()->leftJoin('users as u', 'u.id', '=', 'audit_logs.actor_id')->select('audit_logs.id', 'audit_logs.created_at', 'u.name as actor', 'audit_logs.action', 'audit_logs.entity', 'audit_logs.entity_id', 'audit_logs.before_data', 'audit_logs.after_data', 'audit_logs.ip_address')->orderByDesc('audit_logs.id'),
                ['u.name', 'audit_logs.action', 'audit_logs.entity', 'audit_logs.entity_id']],
        ];

        abort_unless(isset($definitions[$page]), 404);

        return $definitions[$page];
    }
}
