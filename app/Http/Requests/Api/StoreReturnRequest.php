<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:branch_to_central,central_to_supplier'],
            'from_location' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => ['required_if:direction,central_to_supplier', 'nullable', 'integer', 'exists:suppliers,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ];
    }
}
