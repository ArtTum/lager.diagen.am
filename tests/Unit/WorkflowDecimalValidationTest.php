<?php

namespace Tests\Unit;

use App\Http\Requests\Api\AdjustStockRequest;
use App\Http\Requests\Api\ConsumeStockRequest;
use App\Http\Requests\Api\CountInventorySessionRequest;
use App\Http\Requests\Api\ReviewStockRequest;
use App\Http\Requests\Api\StorePurchaseOrderRequest;
use App\Http\Requests\Api\StoreReceiptRequest;
use App\Http\Requests\Api\StoreReturnRequest;
use App\Http\Requests\Api\StoreStockRequest;
use App\Http\Requests\Api\StoreTransferRequest;
use App\Http\Requests\Api\UpdateStockRequestDraft;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowDecimalValidationTest extends TestCase
{
    #[DataProvider('quantityFields')]
    public function test_workflow_quantities_accept_supported_precision_and_reject_rounding_or_overflow(string $request, string $field, bool $zeroAllowed, bool $negativeAllowed): void
    {
        $rules = (new $request)->rules()[$field];

        foreach ([1, '1.125', '0.001', '999999999'] as $value) {
            self::assertFalse(Validator::make(['value' => $value], ['value' => $rules])->fails(), 'Valid quantity: '.$value);
        }
        foreach (['0.000001', '1.2345', '1000000000'] as $value) {
            self::assertTrue(Validator::make(['value' => $value], ['value' => $rules])->fails(), 'Unrepresentable quantity: '.$value);
        }
        foreach (['0', '0.000', '-0.000', '+0.00'] as $value) {
            self::assertSame(! $zeroAllowed, Validator::make(['value' => $value], ['value' => $rules])->fails(), 'Zero quantity: '.$value);
        }
        self::assertSame(! $negativeAllowed, Validator::make(['value' => '-0.001'], ['value' => $rules])->fails());
    }

    public static function quantityFields(): array
    {
        return [
            'consumption' => [ConsumeStockRequest::class, 'qty', false, false],
            'transfer' => [StoreTransferRequest::class, 'items.*.qty', false, false],
            'return' => [StoreReturnRequest::class, 'items.*.qty', false, false],
            'stock request' => [StoreStockRequest::class, 'items.*.qty', false, false],
            'request draft' => [UpdateStockRequestDraft::class, 'items.*.qty', false, false],
            'request approval' => [ReviewStockRequest::class, 'approved.*', true, false],
            'purchase order' => [StorePurchaseOrderRequest::class, 'items.*.qty', false, false],
            'receipt' => [StoreReceiptRequest::class, 'items.*.qty', false, false],
            'inventory count' => [CountInventorySessionRequest::class, 'counts.*.counted_qty', true, false],
            'stock adjustment' => [AdjustStockRequest::class, 'delta_qty', false, true],
        ];
    }

    #[DataProvider('costFields')]
    public function test_workflow_costs_use_currency_precision_and_reject_database_overflow(string $request, string $field): void
    {
        $rules = (new $request)->rules()[$field];

        foreach ([0, '0.01', '12.34', '999999999'] as $value) {
            self::assertFalse(Validator::make(['value' => $value], ['value' => $rules])->fails(), 'Valid cost: '.$value);
        }
        foreach (['0.001', '12.345', '-0.01', '1000000000000'] as $value) {
            self::assertTrue(Validator::make(['value' => $value], ['value' => $rules])->fails(), 'Unrepresentable cost: '.$value);
        }
    }

    public static function costFields(): array
    {
        return [
            'purchase order' => [StorePurchaseOrderRequest::class, 'items.*.unit_cost'],
            'inventory count' => [CountInventorySessionRequest::class, 'counts.*.unit_cost'],
            'stock adjustment' => [AdjustStockRequest::class, 'unit_cost'],
        ];
    }
}
