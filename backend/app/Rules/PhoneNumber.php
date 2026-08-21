<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number that could actually be dialled.
 *
 * Before this, `phone` was validated seven different ways across the codebase
 * (max:20, 30, 40, 50, none, and one min:7) and none of them checked the shape,
 * so "aaaaaaa" was a valid phone number everywhere.
 *
 * Separators are cosmetic — "+91 98200-45678", "(0982) 004 5678" and
 * "9820045678" are the same number — so they are stripped before counting.
 * What remains must be either:
 *
 *   • exactly 10 digits            → a domestic Indian number, or
 *   • 11–15 digits with a leading + → international, per E.164's 15-digit ceiling
 *
 * A bare 11–15 digit string with no `+` is rejected on purpose: it is far more
 * often a typo'd Indian number than an unprefixed foreign one, and silently
 * accepting it produces numbers nobody can ring.
 */
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return;   // emptiness is `nullable`/`required`'s job, not this rule's
        }

        $international = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw);

        // A single leading 0 is the domestic STD prefix ("098200 45678") and is
        // not part of the number. Stripping it here means the same subscriber
        // number validates whether or not the caller typed the trunk code.
        if (! $international && strlen((string) $digits) === 11 && str_starts_with((string) $digits, '0')) {
            $digits = substr((string) $digits, 1);
        }

        $len = strlen((string) $digits);

        if ($international) {
            if ($len < 8 || $len > 15) {
                $fail('The :attribute must be 8 to 15 digits including the country code.');
            }

            return;
        }

        if ($len !== 10) {
            $fail('The :attribute must be a 10-digit number, or start with + and the country code.');
        }
    }
}
