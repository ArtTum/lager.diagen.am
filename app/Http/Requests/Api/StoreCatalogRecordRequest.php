<?php

namespace App\Http\Requests\Api;

use App\Rules\PlainPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCatalogRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->catalogRules(false);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route('kind') !== 'products' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $max = (float) $this->input('max_qty', 0);
            if ($max > 0 && ((float) $this->input('min_qty', 0) > $max || (float) $this->input('optimal_qty', 0) > $max)) {
                $validator->errors()->add('max_qty', 'MIN-ը և OPTIMAL-ը չեն կարող գերազանցել MAX-ը։');
            }
        }];
    }

    private function catalogRules(bool $updating): array
    {
        $kind = (string) $this->route('kind');

        return match ($kind) {
            'branches' => ['name' => ['required', 'string', 'max:160'], 'code' => ['required', 'string', 'max:40', Rule::unique('branches', 'code')],
                'address' => ['nullable', 'string', 'max:255'], 'manager' => ['nullable', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:50'], 'active' => ['sometimes', 'boolean']],
            'products' => ['code' => ['nullable', 'string', 'max:80', Rule::unique('products', 'code')], 'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')],
                'name' => ['required', 'string', 'max:190'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'subcategory' => ['nullable', 'string', 'max:120'],
                'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('active', true)],
                'purchase_price' => $this->user()?->hasPermissionCode('purchases.view') ? ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'] : ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
                'manufacturer' => ['nullable', 'string', 'max:160'], 'unit' => ['required', 'string', 'max:50'], 'package' => ['nullable', 'string', 'max:120'],
                'min_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'], 'optimal_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'], 'max_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
                'storage_conditions' => ['nullable', 'string', 'max:190'], 'refrigerated' => ['sometimes', 'boolean'], 'lot_control' => ['sometimes', 'boolean'],
                'expiry_control' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean']],
            'users' => ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
                'role_id' => ['required', 'integer', 'exists:roles,id'], 'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
                'password' => ['required', 'string', 'min:8', 'max:255', new PlainPassword], 'active' => ['sometimes', 'boolean']],
            default => [],
        };
    }
}
