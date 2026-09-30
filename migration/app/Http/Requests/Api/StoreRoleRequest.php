<?php

namespace App\Http\Requests\Api;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120', Rule::unique('roles', 'title')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::codes()), 'exists:permissions,code'],
        ];
    }
}
