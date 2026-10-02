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
            'delta_qty' => ['required', 'numeric', 'decimal:0,3', 'between:-999999999,999999999',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_numeric($value) && (float) $value === 0.0) {
                        $fail('Ճշգրտման քանակը չի կարող զրո լինել։');
                    }
                },
            ],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'lot_no' => ['required_without:lot_id', 'nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
            'unit_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'bin_location' => ['nullable', 'string', 'max:100'],
        ];
    }
}
