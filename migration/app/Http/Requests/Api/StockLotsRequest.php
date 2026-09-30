<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StockLotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['product_id' => ['required', 'integer', 'exists:products,id'], 'location_id' => ['nullable', 'integer', 'min:0']];
    }
}
