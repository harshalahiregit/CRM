<?php

namespace App\Http\Requests\Transport;

use App\Models\Transport\TransportDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SNG-TRN-004 — driver create. Acceptance: "Required fields, validity dates,
 * audit." Test note: "CRUD + expiry tests."
 *
 * `name` is the one genuinely required field. The licence is nullable on purpose
 * — a driver being onboarded is a real person whose licence is still being
 * collected, and a missing licence bites at allocation (BR-P0-004), not at data
 * entry. `status` and `availability` are absent: both move through their own
 * transition endpoints.
 */
class StoreTransportDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('licence_number')) {
            $this->merge([
                'licence_normalized_probe' => TransportDriver::normalizeLicence((string) $this->input('licence_number')),
            ]);
        }
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name'        => ['required', 'string', 'max:150'],
            'driver_code' => [
                'nullable', 'string', 'max:40',
                Rule::unique('transport_drivers', 'driver_code')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'mobile'           => ['nullable', 'string', 'max:20'],
            'alternate_mobile' => ['nullable', 'string', 'max:20'],

            // STOS-DB §42 prefers a link to the HR employee record; INT §76
            // requires one profile per person, which the unique index enforces.
            // No cross-module write — this is a reference only.
            'hr_employee_id' => [
                'nullable', 'integer',
                Rule::unique('transport_drivers', 'hr_employee_id')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            // BO-009 — a driver may belong to a supplier rather than the company.
            // No exists rule: DB-018 transport_suppliers is not built.
            'supplier_id' => ['nullable', 'integer'],

            'licence_number' => ['nullable', 'string', 'max:40'],
            'licence_normalized_probe' => [
                Rule::unique('transport_drivers', 'licence_normalized')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'licence_class'       => ['nullable', 'string', 'max:30'],
            'licence_valid_from'  => ['nullable', 'date'],
            'licence_valid_until' => ['nullable', 'date', 'after_or_equal:licence_valid_from'],
        ];
    }

    public function attributes(): array
    {
        return ['licence_normalized_probe' => 'licence number'];
    }

    public function messages(): array
    {
        return [
            'licence_normalized_probe.unique' => 'A driver with that licence number already exists in this workspace.',
            'hr_employee_id.unique' => 'That employee already has a driver profile.',
            'licence_valid_until.after_or_equal' => 'The licence cannot expire before it becomes valid.',
        ];
    }
}
