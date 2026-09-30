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
            'name' => ['required', 'string', 'max:190'], 'tax_id' => ['required', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:255'], 'contact_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:50'], 'email' => ['required', 'email', 'max:190'],
            'bank_details' => $this->user()?->hasPermissionCode('purchases.view') ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'contract_no' => ['required', 'string', 'max:100'],
            'contract_start' => ['required', 'date_format:Y-m-d'], 'contract_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:contract_start'],
            'payment_terms' => ['required', 'string', 'max:190'], 'delivery_days' => ['required', 'integer', 'min:0'], 'active' => ['sometimes', 'boolean'],
        ];
    }
}
