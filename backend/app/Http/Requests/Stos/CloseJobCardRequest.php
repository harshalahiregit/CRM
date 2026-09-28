<?php

namespace App\Http\Requests\Stos;

use App\Domains\Fleet\Models\MaintenanceJob;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CloseJobCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'diagnosis'     => 'nullable|string|max:5000',
            'workshop_name' => 'nullable|string|max:100',
            'parts_cost'    => 'nullable|numeric|min:0|max:99999999',
            'labour_cost'   => 'nullable|numeric|min:0|max:99999999',
            // Honoured over parts+labour when present: a signed card may carry a
            // discount or a warranty credit.
            'total_cost'    => 'nullable|numeric|min:0|max:99999999',

            // T-31 — the verdict. `qc_passed` stays accepted because callers
            // built against it are still in the field; the service derives one
            // from the other so the two can never disagree in the database.
            'qc_result'     => ['nullable', Rule::in(MaintenanceJob::QC_RESULTS)],
            'qc_passed'     => 'nullable|boolean',
            'road_tested'   => 'nullable|boolean',
            // Which condemnation this card answers. Only honoured on a PASS —
            // the service drops it otherwise.
            'clears_job_id' => 'nullable|integer|min:1',

            // T-30 — itemisation may also be completed at closure, which is when
            // the workshop actually knows what it used.
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
