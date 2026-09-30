<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'bank_details' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contract_no' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contract_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'contract_end' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:contract_start'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:190'],
            'delivery_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
