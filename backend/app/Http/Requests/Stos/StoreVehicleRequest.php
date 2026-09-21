<?php

namespace App\Http\Requests\Stos;

use App\Domains\Fleet\Models\Vehicle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding a truck (Step 1). The static, physical facts only.
 *
 * `status` is absent on purpose: a vehicle's operational state is decided by
 * its job cards, not typed into a form.
 */
class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the plate BEFORE the unique rule runs.
     *
     * The service normalises too (it is the authority), but without this the
     * field-level rule compares "MH 20 GH 7799" against the stored
     * "MH20GH7799", misses the clash, and the duplicate surfaces as a generic
     * error banner instead of a message attached to the field the user typed.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('registration_number')) {
            $this->merge([
                'registration_number' => preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($this->input('registration_number')))),
            ]);
        }
    }

    public function rules(): array
    {
        $companyId = (int) ($this->user()->company_id ?? $this->user()->tenant_id);
        $id = $this->route('vehicle');

        return [
            // Uniqueness is re-checked in the service against soft-deleted rows
            // too, and on the NORMALISED plate — this rule catches the common
            // case early with a field-level error.
            'registration_number' => [
                'required', 'string', 'max:40',
                Rule::unique('vehicles', 'registration_number')
                    ->where('company_id', $companyId)
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'vehicle_type'   => ['required', Rule::in(Vehicle::TYPES)],
            'ownership_type' => ['required', Rule::in(Vehicle::OWNERSHIPS)],

            // A chassis number is the vehicle's real identity — two trucks
            // sharing one is a data-entry error, not a fleet.
            'chassis_number' => [
                'nullable', 'string', 'max:50',
                Rule::unique('vehicles', 'chassis_number')
                    ->where('company_id', $companyId)->ignore($id)->whereNull('deleted_at'),
            ],
            'engine_number' => 'nullable|string|max:50',

            // T-01 — identity and payload. `capacity_tonnes` is the one that
            // matters beyond the passport: Operations' eligibility engine
            // matches it against an order's required payload, so a blank one
            // makes the vehicle invisible to capacity-based allocation.
            'fleet_number'       => 'nullable|string|max:40',
            'manufacturer'       => 'nullable|string|max:100',
            'model'              => 'nullable|string|max:100',
            'variant'            => 'nullable|string|max:100',
            // Bounded rather than free: a year outside this is a typo, and a
            // typo here silently ages the fleet in every report that uses it.
            'manufacturing_year' => 'nullable|integer|min:1950|max:'.(date('Y') + 1),
            'purchase_date'      => 'nullable|date|before_or_equal:today',
            'fuel_type'          => ['nullable', Rule::in(Vehicle::FUEL_TYPES)],
            'branch'             => 'nullable|string|max:100',
            'capacity_tonnes'    => 'nullable|numeric|min:0|max:999999',

            // T-04 — the service schedule. Either clock, neither or both:
            // trucks are serviced on distance, trailers often on time, and some
            // fleets use both and take whichever comes first. No defaults —
            // inventing a schedule nobody set would flag the whole fleet.
            'service_interval_km'   => 'nullable|integer|min:100|max:1000000',
            'service_interval_days' => 'nullable|integer|min:1|max:3650',
            'last_service_odometer' => 'nullable|numeric|min:0|max:9999999',
            'last_service_on'       => 'nullable|date|before_or_equal:today',

            'gps_device_id' => [
                'nullable', 'string', 'max:64',
                Rule::unique('vehicles', 'gps_device_id')
                    ->where('company_id', $companyId)->ignore($id)->whereNull('deleted_at'),
            ],

            // `compliance_status` is NOT accepted: it is derived from the dates
            // below by ComplianceService, so that one truth cannot be typed
            // over. What a person CAN set is a manual hold.
            'registration_expiry' => 'nullable|date',
            'insurance_expiry'    => 'nullable|date',
            'fitness_expiry'      => 'nullable|date',
            'permit_expiry'       => 'nullable|date',
            'puc_expiry'          => 'nullable|date',

            'compliance_hold'        => 'nullable|boolean',
            'compliance_hold_reason' => 'nullable|string|max:255|required_if:compliance_hold,true,1',
        ];
    }

    public function messages(): array
    {
        return [
            'registration_number.unique' => 'That number plate is already in your fleet.',
            'gps_device_id.unique'       => 'That device is already fitted to another vehicle.',
            'chassis_number.unique'      => 'That chassis number is already registered to another vehicle.',
            'compliance_hold_reason.required_if' => 'A compliance hold needs a reason — it overrides every expiry date.',
        ];
    }
}
