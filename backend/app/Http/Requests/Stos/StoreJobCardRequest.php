<?php

namespace App\Http\Requests\Stos;

use App\Domains\Fleet\Models\MaintenanceJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id'      => 'required|integer|min:1',
            // Dispatch owns trips — indexed, no FK, no existence check here.
            'trip_id'         => 'nullable|integer|min:1',
            // Blank is allowed: the service generates JC-YYYY-NNNN so nobody
            // has to invent a numbering scheme at the counter.
            'job_card_number' => 'nullable|string|max:40',
            'workshop_name'   => 'nullable|string|max:100',
            'complaint'       => 'required|string|max:5000',
            'diagnosis'       => 'nullable|string|max:5000',
            // Still accepted as scalars for a card settled at the counter with
            // one figure and no itemisation. When line rows are sent they win —
            // see MaintenanceService::applyCosts().
            'parts_cost'      => 'nullable|numeric|min:0|max:99999999',
            'labour_cost'     => 'nullable|numeric|min:0|max:99999999',
            'status'          => ['nullable', Rule::in(MaintenanceJob::OPEN_STATES)],
            'is_safety_critical' => 'nullable|boolean',
            'road_tested'     => 'nullable|boolean',

            // T-30 — the itemisation is now kept.
            'parts'                   => 'nullable|array|max:200',
            'parts.*.part_name'       => 'nullable|string|max:150',
            'parts.*.part_number'     => 'nullable|string|max:80',
            'parts.*.quantity'        => 'nullable|numeric|min:0|max:99999',
            'parts.*.unit_cost'       => 'nullable|numeric|min:0|max:99999999',
            'parts.*.supplier'        => 'nullable|string|max:150',
            'parts.*.warranty_months' => 'nullable|integer|min:0|max:600',

            'labour'                  => 'nullable|array|max:200',
            'labour.*.labour_type'    => 'nullable|string|max:150',
            'labour.*.hours'          => 'nullable|numeric|min:0|max:9999',
            'labour.*.hourly_rate'    => 'nullable|numeric|min:0|max:99999999',
            'labour.*.technician'     => 'nullable|string|max:120',
        ];
    }
}
