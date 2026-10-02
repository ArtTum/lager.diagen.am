<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlainPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        if (str_contains($value, "\0")) {
            $fail('Գաղտնաբառը չի կարող պարունակել զրոյական սիմվոլ։');
        }
        if (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72) {
            $fail('Գաղտնաբառը չի կարող գերազանցել 72 բայթը։');
        }
    }
}
