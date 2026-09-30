<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['key' => ['required', 'regex:/^[a-f0-9]{40}$/']];
    }
}
