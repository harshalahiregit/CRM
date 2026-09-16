<?php

namespace App\Http\Requests\Transport;

use App\Support\Transport\VehicleOwnership;
use Illuminate\Validation\Rule;

/**
 * SNG-TRN-003 — vehicle update.
 *
 * Same rules as create, except the uniqueness check must ignore the row being
 * edited — otherwise saving a vehicle without changing its registration would
 * fail against itself.
 */
class UpdateTransportVehicleRequest extends StoreTransportVehicleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $tenantId = $this->user()->tenant_id;
        $id = (int) $this->route('id');

        $rules['registration_number'] = ['sometimes', 'required', 'string', 'max:32'];
        $rules['registration_normalized_probe'] = [
            Rule::unique('transport_vehicles', 'registration_normalized')
                ->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id),
        ];
        $rules['ownership_type'] = ['sometimes', 'nullable', 'string', Rule::in(VehicleOwnership::ALL)];

        return $rules;
    }
}
