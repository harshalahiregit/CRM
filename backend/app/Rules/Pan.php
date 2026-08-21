<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Indian Permanent Account Number — five letters, four digits, one letter.
 *
 * The fourth character encodes the holder type (P individual, C company,
 * H HUF, F firm, …) and the fifth is the first letter of the surname or entity
 * name, but neither is checked here: a valid-format PAN for the wrong entity is
 * a data-entry question, not a format one.
 *
 * Stored and compared upper-case; input is folded so "abcde1234f" is accepted.
 */
class Pan implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pan = strtoupper(trim((string) $value));
        if ($pan === '') {
            return;
        }

        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            $fail('The :attribute must be a valid PAN, for example ABCDE1234F.');
        }
    }
}
