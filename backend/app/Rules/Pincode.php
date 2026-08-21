<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Indian postal PIN — exactly six digits, first digit 1-8.
 *
 * 0 and 9 are not allocated as leading digits, so rejecting them catches the
 * common case of a mistyped or placeholder code that a plain `digits:6` would
 * happily accept.
 */
class Pincode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = trim((string) $value);
        if ($pin === '') {
            return;
        }

        if (! preg_match('/^[1-8][0-9]{5}$/', $pin)) {
            $fail('The :attribute must be a valid 6-digit PIN code.');
        }
    }
}
