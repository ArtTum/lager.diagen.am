<?php

namespace App\Http\Requests\Api;

use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

class MovementFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'branch_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value !== 0 && ! Branch::query()->whereKey((int) $value)->exists()) {
                    $fail('Ընտրված պահեստը գոյություն չունի։');
                }
            }], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'], 'lot' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:40'], 'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'in:csv,xlsx']];
    }
}
