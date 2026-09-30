<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\StockLot;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierRepository
{
    public function createAuditEntry(array $attributes): AuditLog
    {
        return AuditLog::query()->create($attributes);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        return Supplier::query()->when(($filters['active_only'] ?? false), fn ($query) => $query->where('active', true))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn ($where) => $where->where('name', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")->orWhere('contact_name', 'like', "%{$search}%"));
            })->orderBy('name')->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));
    }

    public function find(int $id): Supplier
    {
        return Supplier::query()->findOrFail($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::query()->create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->fill($data)->save();

        return $supplier->refresh();
    }

    public function deactivate(Supplier $supplier): void
    {
        $supplier->forceFill(['active' => false])->save();
    }

    public function history(int $id, int $location, bool $showPrices, array $filters = []): array
    {
        $supplier = $this->find($id);
        $orderedProducts = PurchaseOrderItem::query()->whereHas('purchaseOrder', fn ($query) => $query->where('supplier_id', $id))->select('product_id');
        $receivedProducts = ReceiptItem::query()->whereHas('receipt', fn ($query) => $query->where('supplier_id', $id))->select('product_id');
        $lotProducts = StockLot::query()->where('supplier_id', $id)->select('product_id');
        $productQuery = Product::query()->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->where(fn ($query) => $query->where('products.supplier_id', $id)->orWhereIn('products.id', $orderedProducts)
                ->orWhereIn('products.id', $receivedProducts)->orWhereIn('products.id', $lotProducts))
            ->select('products.id', 'products.code', 'products.name', 'products.unit', 'products.active', 'c.name as category')
            ->orderBy('products.name');
        $latestSupplierReceiptLine = ReceiptItem::query()->join('receipts as supplier_receipts', 'supplier_receipts.id', '=', 'receipt_items.receipt_id')
            ->whereColumn('receipt_items.product_id', 'products.id')->where('supplier_receipts.supplier_id', $id)
            ->orderByDesc('supplier_receipts.received_on')->orderByDesc('receipt_items.id');
        $productQuery->addSelect([
            'last_delivery_on' => (clone $latestSupplierReceiptLine)->select('supplier_receipts.received_on')->limit(1),
        ]);
        if ($showPrices) {
            $productQuery->addSelect([
                'latest_unit_cost' => (clone $latestSupplierReceiptLine)->select('receipt_items.unit_cost')->limit(1),
            ]);
        }
        $products = $productQuery->get();
        $receipts = Receipt::query()->leftJoin('users as u', 'u.id', '=', 'receipts.received_by')
            ->where('receipts.supplier_id', $id)->select('receipts.id', 'receipts.receipt_no', 'receipts.purchase_order_id', 'receipts.invoice_no',
                'receipts.contract_no', 'receipts.received_on', 'receipts.created_at', 'receipts.note', 'u.name as receiver')
            ->when($location > 0, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderByDesc('receipts.received_on')->orderByDesc('receipts.id')
            ->paginate(10, ['*'], 'receipts_page', max(1, (int) ($filters['receipts_page'] ?? 1)));
        $receiptIds = $receipts->getCollection()->pluck('id');
        $receiptItems = $receiptIds->isEmpty() ? collect() : ReceiptItem::query()->with(['product:id,code,name,unit', 'lot:id,lot_no,expires_on'])
            ->whereIn('receipt_id', $receiptIds)->get()->groupBy('receipt_id');
        $receipts->getCollection()->each(fn ($receipt) => $receipt->setRelation('lines', $receiptItems->get($receipt->id, collect())->map(fn ($line) => [
            'product_code' => $line->product?->code, 'product' => $line->product?->name, 'unit' => $line->product?->unit,
            'lot_no' => $line->lot?->lot_no, 'expires_on' => $line->lot?->expires_on?->format('Y-m-d'), 'qty' => $line->qty,
            'unit_cost' => $line->unit_cost,
        ])->values()));
        $lots = StockLot::query()->join('products as p', 'p.id', '=', 'stock_lots.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'stock_lots.location_id')->where('stock_lots.supplier_id', $id)
            ->when($location > 0, fn ($query) => $query->where('stock_lots.location_id', $location))
            ->select('stock_lots.id', 'stock_lots.lot_no', 'stock_lots.received_on', 'stock_lots.expires_on', 'stock_lots.qty',
                'stock_lots.unit_cost', 'stock_lots.bin_location', 'p.code as product_code', 'p.name as product', 'p.unit')
            ->selectRaw("CASE WHEN stock_lots.location_id = 0 THEN 'Կենտրոնական պահեստ' ELSE COALESCE(b.name, 'Անհայտ պահեստ') END as branch")
            ->orderByDesc('stock_lots.received_on')->orderByDesc('stock_lots.id')
            ->paginate(10, ['*'], 'lots_page', max(1, (int) ($filters['lots_page'] ?? 1)));

        return ['supplier' => $supplier, 'products' => $products, 'receipts' => $receipts, 'lots' => $lots];
    }
}
