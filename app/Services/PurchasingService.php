<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\PurchasingRepository;
use App\Support\WorkflowStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchasingService
{
    public function __construct(private readonly PurchasingRepository $purchasing) {}

    public function options(string $kind): array
    {
        abort_unless(in_array($kind, ['purchases', 'receipts'], true), 404);

        return $this->purchasing->options($kind === 'receipts');
    }

    public function document(int $orderId): array
    {
        $order = $this->purchasing->orderForDocument($orderId);
        $total = BigDecimal::zero()->toScale(2);
        $lines = [];
        foreach ($order->items as $item) {
            // Sum the displayed, rounded line amounts using exact decimal arithmetic.
            $amount = BigDecimal::of($item->ordered_qty)->multipliedBy($item->unit_cost)->toScale(2, RoundingMode::HalfUp);
            $total = $total->plus($amount);
            $lines[] = [
                'code' => $item->product?->code,
                'name' => $item->product?->name ?? 'Ապրանքը հասանելի չէ',
                'unit' => $item->product?->unit ?? '',
                'qty' => (string) $item->ordered_qty,
                'unit_cost' => (string) $item->unit_cost,
                'amount' => (string) $amount,
            ];
        }

        return [
            'id' => (int) $order->id, 'order_no' => $order->order_no,
            'status' => WorkflowStatus::label('purchases', $order->status),
            'ordered_on' => $order->ordered_on?->format('d.m.Y'),
            'expected_on' => $order->expected_on?->format('d.m.Y'),
            'approved_at' => $order->approved_at?->format('d.m.Y H:i'),
            'creator' => $order->creator?->name,
            'supplier' => $order->supplier?->only(['name', 'tax_id', 'address', 'contact_name', 'phone', 'email', 'contract_no']) ?? [],
            'note' => $order->note, 'lines' => $lines, 'total' => (string) $total,
        ];
    }

    public function createOrder(User $actor, string $ip, array $data): array
    {
        abort_unless($this->purchasing->activeSupplier((int) $data['supplier_id']), 422, 'Ընտրեք ակտիվ մատակարար։');
        foreach ($data['items'] as $line) {
            abort_unless($this->purchasing->activeProduct((int) $line['product_id']), 422, 'Ընտրված ապրանքներից մեկն ակտիվ չէ։');
        }

        return DB::transaction(function () use ($actor, $ip, $data): array {
            $number = 'ՊԱՏ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $order = $this->purchasing->createOrder([
                'order_no' => $number, 'supplier_id' => $data['supplier_id'], 'status' => 'pending', 'ordered_on' => $data['ordered_on'],
                'expected_on' => $data['expected_on'] ?? null, 'created_by' => $actor->id, 'note' => trim($data['note'] ?? ''), 'created_at' => now(),
            ]);
            $this->purchasing->createOrderItems((int) $order->id, $data['items']);
            $this->purchasing->audit((int) $actor->id, 'Գնման պատվերը ստեղծվեց', 'purchase_orders', (int) $order->id, null,
                ['order_no' => $number, 'supplier_id' => (int) $data['supplier_id'], 'line_count' => count($data['items'])], $ip);

            return $this->purchasing->order((int) $order->id)?->toArray() ?? [];
        });
    }

    public function approve(int $orderId, User $actor, string $ip): void
    {
        abort_unless($actor->currentLocationId() === 0, 403, 'Գնման պատվերները հաստատվում են կենտրոնական պահեստում։');
        DB::transaction(function () use ($orderId, $actor, $ip): void {
            $order = $this->purchasing->lockOrder($orderId);
            abort_unless($order && $order->status === 'pending', 409, 'Հաստատման սպասող պատվերը չի գտնվել։');
            $this->purchasing->updateOrder($orderId, ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->purchasing->audit((int) $actor->id, 'Գնման պատվերը հաստատվեց', 'purchase_orders', $orderId, ['status' => 'pending'], ['status' => 'approved'], $ip);
        });
    }

    public function receive(User $actor, string $ip, array $data): array
    {
        abort_unless($actor->currentLocationId() === 0, 403, 'Գնումների մուտքը գրանցվում է կենտրոնական պահեստում։');
        foreach ($data['items'] as $line) {
            if (! empty($line['expires_on']) && $line['expires_on'] < $data['received_on']) {
                throw ValidationException::withMessages(['items' => ['LOT-ի պիտանելիության ժամկետը չի կարող նախորդել մուտքի ամսաթվին։']]);
            }
        }

        return DB::transaction(function () use ($actor, $ip, $data): array {
            $order = $this->purchasing->lockOrder((int) $data['purchase_order_id']);
            abort_unless($order && $order->status === 'approved', 409, 'Մուտքի համար անհրաժեշտ է հաստատված գնման պատվեր։');
            $totals = [];
            foreach ($data['items'] as $line) {
                $itemId = (int) $line['purchase_order_item_id'];
                $totals[$itemId] = ($totals[$itemId] ?? 0.0) + (float) $line['qty'];
            }

            $locked = [];
            foreach ($totals as $itemId => $quantity) {
                $item = $this->purchasing->orderItemForUpdate((int) $itemId, (int) $order->id);
                abort_unless($item, 422, 'Ընտրված տողը այս պատվերին չի պատկանում։');
                if (! $item->product?->active) {
                    throw ValidationException::withMessages(['items' => ['Պատվերի ապրանքն անջատված է․ մուտք գրանցելուց առաջ ակտիվացրեք այն։']]);
                }
                if ($quantity > (float) $item->ordered_qty - (float) $item->received_qty + 0.00001) {
                    throw ValidationException::withMessages(['items' => ['Մուտքի քանակը գերազանցում է պատվերի չստացված մնացորդը։']]);
                }
                $locked[$itemId] = $item;
            }
            foreach ($data['items'] as $line) {
                $item = $locked[(int) $line['purchase_order_item_id']];
                if ($item->product?->expiry_control && empty($line['expires_on'])) {
                    throw ValidationException::withMessages(['items' => ['Ժամկետով վերահսկվող ապրանքի համար LOT-ի պիտանելիության ժամկետը պարտադիր է։']]);
                }
            }

            $number = 'ՄՈՒՏ-'.now()->format('ymd-His').'-'.strtoupper(bin2hex(random_bytes(3)));
            $supplierId = (int) $order->supplier_id;
            $receipt = $this->purchasing->createReceipt([
                'receipt_no' => $number, 'purchase_order_id' => $order->id, 'supplier_id' => $supplierId,
                'invoice_no' => trim($data['invoice_no'] ?? ''), 'contract_no' => trim($data['contract_no'] ?? ''),
                'received_by' => $actor->id, 'received_on' => $data['received_on'], 'note' => trim($data['note'] ?? ''), 'created_at' => now(),
            ]);
            foreach ($data['items'] as $line) {
                $item = $locked[(int) $line['purchase_order_item_id']];
                $lot = $this->purchasing->createLot([
                    'product_id' => $item->product_id, 'purchase_order_id' => $order->id, 'location_id' => 0,
                    'lot_no' => trim($line['lot_no']), 'expires_on' => $line['expires_on'] ?? null,
                    'received_on' => $data['received_on'], 'supplier_id' => $supplierId, 'unit_cost' => $item->unit_cost,
                    'bin_location' => trim($line['bin_location'] ?? ''), 'qty' => $line['qty'],
                ]);
                $this->purchasing->createReceiptItem([
                    'receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id,
                    'lot_id' => $lot->id, 'qty' => $line['qty'], 'unit_cost' => $item->unit_cost,
                ]);
                $this->purchasing->incrementReceived((int) $item->id, (float) $line['qty']);
                $this->purchasing->movement((int) $actor->id, (int) $item->product_id, (int) $lot->id, (float) $line['qty'], (float) $item->unit_cost, $number);
            }
            $this->purchasing->audit((int) $actor->id, 'Ապրանքների մուտքագրում', 'receipts', (int) $receipt->id, null,
                ['receipt_no' => $number, 'purchase_order_id' => (int) $order->id, 'line_count' => count($data['items'])], $ip);

            return ['id' => (int) $receipt->id, 'receipt_no' => $number];
        });
    }
}
