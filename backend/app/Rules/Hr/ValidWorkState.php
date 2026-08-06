<?php

namespace App\Rules\Hr;

use App\Support\Hr\WorkStates;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts any spelling WorkStates::normalize() recognises — "MH", "maharashtra",
 * "Orissa" — and rejects anything that is not a state.
 *
 * Deliberately not `Rule::in(WorkStates::names())`: that would reject the codes and
 * historic names real data is full of, and push people back to free text.
 */
class ValidWorkState implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;   // optional — the "not set" case is handled by payroll, not here
        }

        if (! WorkStates::isValid(is_string($value) ? $value : '')) {
            $fail('The :attribute must be an Indian state or union territory. '
                .'A city (for example "Pune") is not a valid work state — use the office city field instead.');
        }
    }
}
