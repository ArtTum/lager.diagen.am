<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_branch' => ['required', 'integer', 'exists:branches,id'],
            'to_branch' => ['required', 'integer', 'different:from_branch', 'exists:branches,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999'],
        ];
    }
}
