<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A driver carries two independent state axes (BO-009 lifecycle, STOS-DB §44
 * availability), so one endpoint moves one axis at a time and says which.
 * Sending both at once would make the audit trail ambiguous about what the user
 * actually did.
 */
class TransitionTransportDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'axis'   => ['required', 'string', Rule::in(['status', 'availability'])],
            'value'  => ['required', 'string', 'max:30'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $axis = $this->input('axis');
            $value = $this->input('value');
            $allowed = $axis === 'status' ? DriverStatus::ALL : DriverAvailability::ALL;

            if ($axis && $value && ! in_array($value, $allowed, true)) {
                $v->errors()->add('value', "That is not a valid driver {$axis}.");
            }
        });
    }
}
