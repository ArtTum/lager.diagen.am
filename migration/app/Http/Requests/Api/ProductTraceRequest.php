<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ProductTraceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lots_page' => ['sometimes', 'integer', 'min:1'],
            'movements_page' => ['sometimes', 'integer', 'min:1'],
            'requests_page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
