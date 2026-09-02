<?php

namespace App\Http\Requests\Tpv;

use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWorkerMedicalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_type'           => 'nullable|in:internal,external',
            'exam_date'           => 'nullable|date',
            // Certificate currency window. Optional — defaults to exam_date + 1yr
            // server-side when omitted. Cannot predate the examination.
            'valid_until'         => 'nullable|date|after_or_equal:exam_date',
            'examiner_name'       => 'nullable|string|max:120',
            'clinic_name'         => 'nullable|string|max:160',

            'height_cm'           => 'nullable|numeric|min:50|max:260',
            'weight_kg'           => 'nullable|numeric|min:20|max:300',
            'bp_systolic'         => 'nullable|integer|min:50|max:260',
            'bp_diastolic'        => 'nullable|integer|min:30|max:200',
            'vision'              => 'nullable|string|max:60',

            'screening_responses' => 'nullable|array',
            // The band is derived from the score server-side — not accepted here.
            'screening_score'     => 'nullable|integer|min:0|max:60',

            'fitness_status'      => ['required', Rule::in(TpvMedicalFitness::ALL)],
            'restrictions'        => 'nullable|string',

            // §16 — sign-off is distinct from the clerk who recorded the exam.
            'approved_by'         => 'nullable|integer',
            'approved_at'         => 'nullable|date',
            // §16 — fitness certificate + a general supporting document, stored as paths.
            'certificate_path'    => 'nullable|string|max:255',
            'document_path'       => 'nullable|string|max:255',
            // External-doctor exam: the uploaded prescription/report file. Stored
            // privately and its path saved to document_path (previously the file
            // was captured in the UI but never sent — the evidence was lost).
            'report_file'         => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',

            // Examiner's signature captured in-browser as a base64 PNG data URL.
            // Decoded to a stored file (signature_path) in the service.
            'signature_data'      => 'nullable|string',

            // §16 legal capture — geolocation (lat,long) + an optional photo, both
            // sent by the client; system_ip is stamped server-side, not accepted here.
            'geo_location'        => 'nullable|string|max:120',
            'capture_photo'       => 'nullable|string',
        ];
    }
}
