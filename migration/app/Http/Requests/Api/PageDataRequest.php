<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class PageDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:120'], 'barcode' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'threshold' => ['nullable', 'in:expired,7,30,60,90,180'], 'format' => ['nullable', 'in:csv,xlsx'],
            'status' => ['nullable', 'string', 'max:32'], 'urgency' => ['nullable', 'in:normal,high,urgent'],
            'branch_id' => ['nullable', 'integer', 'min:0'], 'from_branch' => ['nullable', 'integer', 'min:1'],
            'to_branch' => ['nullable', 'integer', 'min:1'], 'supplier_id' => ['nullable', 'integer', 'min:1'],
            'direction' => ['nullable', 'in:branch_to_central,central_to_supplier'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']];
    }
}
