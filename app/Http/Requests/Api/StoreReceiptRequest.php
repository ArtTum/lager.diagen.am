<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_order_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'invoice_no' => ['nullable', 'string', 'max:100'],
            'contract_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            // Repeated order-item ids are valid: a single received order line can
            // arrive in multiple LOTs with different expiry dates or locations.
            // PurchasingService aggregates their quantities before checking the
            // unreceived balance, so allowing repeated ids cannot bypass limits.
            'items.*.purchase_order_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'items.*.lot_no' => ['required', 'string', 'max:100'],
            'items.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'items.*.bin_location' => ['nullable', 'string', 'max:100'],
        ];
    }
}
