<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'urgency' => ['required', 'in:normal,high,urgent'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'submit_mode' => ['required', 'in:draft,send'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
