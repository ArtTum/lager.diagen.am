<?php

namespace App\Http\Requests\Api;

use App\Models\Branch;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['nullable', Rule::in(array_keys(ReportService::types()))],
            'format' => ['nullable', 'in:csv,xlsx,pdf'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ((int) $value !== 0 && ! Branch::query()->whereKey((int) $value)->exists()) {
                        $fail('Ընտրված մասնաճյուղը գոյություն չունի։');
                    }
                },
            ],
            'product_id' => ['nullable', 'integer', Rule::exists((new Product)->getTable(), 'id')],
            'supplier_id' => ['nullable', 'integer', Rule::exists((new Supplier)->getTable(), 'id')],
            'actor_id' => ['nullable', 'integer', Rule::exists((new User)->getTable(), 'id')],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lot_no' => ['nullable', 'string', 'max:100'],
            'movement_type' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ];
    }
}
