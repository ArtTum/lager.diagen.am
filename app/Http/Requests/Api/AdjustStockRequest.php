<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'location_id' => ['nullable', 'integer', 'min:0'],
            'lot_id' => ['nullable', 'integer', 'exists:stock_lots,id'],
            'delta_qty' => ['required', 'numeric', 'not_in:0', 'between:-999999999,999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lot_no' => ['required_without:lot_id', 'nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'bin_location' => ['nullable', 'string', 'max:100'],
        ];
    }
}
