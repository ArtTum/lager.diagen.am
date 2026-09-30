<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'tax_id' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'bank_details' => ['nullable', 'string', 'max:255'],
            'contract_no' => ['nullable', 'string', 'max:100'],
            'contract_start' => ['nullable', 'date_format:Y-m-d'],
            'contract_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:contract_start'],
            'payment_terms' => ['nullable', 'string', 'max:190'],
            'delivery_days' => ['nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
