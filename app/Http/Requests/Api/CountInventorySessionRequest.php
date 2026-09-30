<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CountInventorySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counts' => ['required', 'array', 'min:1'],
            'counts.*.counted_qty' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'counts.*.reason' => ['nullable', 'string', 'max:255'],
            'counts.*.lot_no' => ['nullable', 'string', 'max:100'],
            'counts.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'counts.*.supplier_id' => ['nullable', 'integer', 'min:1', 'exists:suppliers,id'],
            'counts.*.bin_location' => ['nullable', 'string', 'max:100'],
            'counts.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }
}
