<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\VehicleOwnership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SNG-TRN-003 — vehicle create. Acceptance: "Unique vehicle identity, document
 * dates, audit." Test note: "CRUD + validation + permissions."
 *
 * Shape only. Uniqueness is enforced by the database on the NORMALIZED
 * registration (FLEET §9), so a rule here would have to normalize first to be
 * meaningful — it does, so the user gets a readable message instead of a
 * constraint violation.
 *
 * `status` and `registration_normalized` are deliberately absent: FLEET §8
 * forbids typing a status, and the normalized form is derived.
 */
class StoreTransportVehicleRequest extends FormRequest
{
    /** Gating is the route group's job (transport.permission). */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('registration_number')) {
            $this->merge([
                'registration_normalized_probe' => \App\Models\Transport\TransportVehicle::normalizeRegistration(
                    (string) $this->input('registration_number')
                ),
            ]);
        }
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'registration_number' => ['required', 'string', 'max:32'],
            // FLEET §9 — "unique within organization". Checked on the normalized
            // form so "MH 12 AB 4455" cannot be added twice in two spellings.
            'registration_normalized_probe' => [
                Rule::unique('transport_vehicles', 'registration_normalized')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],

            'fleet_number'   => ['nullable', 'string', 'max:40'],
            'chassis_number' => ['nullable', 'string', 'max:60'],
            'engine_number'  => ['nullable', 'string', 'max:60'],
            'gps_device_id'  => ['nullable', 'string', 'max:80'],

            'vehicle_type'  => ['nullable', 'string', 'max:60'],
            'manufacturer'  => ['nullable', 'string', 'max:80'],
            'model'         => ['nullable', 'string', 'max:80'],
            'variant'       => ['nullable', 'string', 'max:80'],
            'manufacturing_year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'purchase_date' => ['nullable', 'date'],
            'fuel_type'     => ['nullable', 'string', 'max:30'],
            'branch'        => ['nullable', 'string', 'max:120'],

            // Metric tonnes, matching the column name on both sides of PLN-001.
            'capacity_tonnes' => ['nullable', 'numeric', 'min:0', 'max:999999'],

            // FLEET §10 — the six values, no others.
            'ownership_type' => ['nullable', 'string', Rule::in(VehicleOwnership::ALL)],
        ];
    }

    public function attributes(): array
    {
        return ['registration_normalized_probe' => 'registration number'];
    }

    public function messages(): array
    {
        return [
            'registration_normalized_probe.unique' => 'A vehicle with that registration number already exists in this workspace.',
        ];
    }
}
