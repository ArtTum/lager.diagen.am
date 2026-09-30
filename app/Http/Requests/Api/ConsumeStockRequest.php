<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ConsumeStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'location_id' => ['nullable', 'integer', 'min:0'],
            'issue_type' => ['required', 'in:usage,expired,damaged,other'],
            'reason_note' => ['required_if:issue_type,other', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
