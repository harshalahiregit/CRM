<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an Indian bank IFSC code: 4 letters, a mandatory 0, then 6
 * alphanumerics (e.g. HDFC0001234).
 */
class Ifsc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper((string) $value))) {
            $fail('The :attribute must be a valid IFSC code.');
        }
    }
}
