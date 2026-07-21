<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class ConvertToJdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * All optional — HR may tweak the JD during conversion, otherwise the
     * fields are pre-filled from the approved Manpower Request.
     */
    public function rules(): array
    {
        return [
            'title'        => 'sometimes|nullable|string|max:200',
            'location'     => 'sometimes|nullable|string|max:150',
            'posting_type' => 'sometimes|nullable|in:Internal,External,Both',
            'description'  => 'sometimes|nullable|string',
            'requirements' => 'sometimes|nullable|string',
            'closing_date' => 'sometimes|nullable|date',
            // Provenance of the JD body — drives the audit action + jd_source column.
            'jd_source'    => 'sometimes|nullable|in:manual,ai,template',
        ];
    }
}
