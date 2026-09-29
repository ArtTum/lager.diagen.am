<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ReviewStockRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:start_review,approve,reject'],
            'rejection_reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:3', 'max:2000'],
            'approved' => ['required_if:decision,approve', 'array'],
            'approved.*' => ['required_if:decision,approve', 'numeric', 'min:0'],
        ];
    }
}
