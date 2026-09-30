<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SupplierHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipts_page' => ['sometimes', 'integer', 'min:1'],
            'lots_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
