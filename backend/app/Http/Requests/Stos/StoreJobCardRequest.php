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
            'complaint'       => 'required|string|max:5000',
            'diagnosis'       => 'nullable|string|max:5000',
            'parts_cost'      => 'nullable|numeric|min:0|max:99999999',
            'labour_cost'     => 'nullable|numeric|min:0|max:99999999',
            'status'          => ['nullable', Rule::in(MaintenanceJob::OPEN_STATES)],
            'is_safety_critical' => 'nullable|boolean',
        ];
    }
}
