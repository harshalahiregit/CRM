<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a 15-character GSTIN: format plus the GSTN checksum on the 15th
 * character (derived from the first 14 using the official mod-36 algorithm).
 */
class Gstin implements ValidationRule
{
    private const CP = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $gstin = strtoupper((string) $value);

        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gstin)) {
            $fail('The :attribute must be a valid 15-character GSTIN.');

            return;
        }

        if ($gstin[14] !== self::checksumChar(substr($gstin, 0, 14))) {
            $fail('The :attribute has an invalid GSTIN checksum.');
        }
    }

    /** The GSTN check character for the first 14 characters of a GSTIN. */
    public static function checksumChar(string $first14): string
    {
        $mod = strlen(self::CP);
        $factor = 2;
        $sum = 0;

        for ($i = strlen($first14) - 1; $i >= 0; $i--) {
            $code = strpos(self::CP, $first14[$i]);
            $digit = $factor * $code;
            $digit = intdiv($digit, $mod) + ($digit % $mod);
            $sum += $digit;
            $factor = $factor === 2 ? 1 : 2;
        }

        return self::CP[($mod - ($sum % $mod)) % $mod];
    }
}
