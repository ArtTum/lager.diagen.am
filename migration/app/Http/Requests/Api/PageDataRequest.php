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
            'threshold' => ['nullable', 'in:expired,7,30,60,90,180'], 'format' => ['nullable', 'in:csv,xlsx']];
    }
}
