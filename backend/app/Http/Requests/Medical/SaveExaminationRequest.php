<?php

namespace App\Http\Requests\Medical;

use App\Support\Medical\HealthScore;
use App\Support\Tpv\TpvMedicalFitness;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The detailed Medical Examination Form.
 *
 * Serves both sides — the fitness vocabulary is identical on TPV and Purchase,
 * so one request class validates both rather than two that must be kept in step.
 *
 * What is deliberately NOT accepted: storage paths, the certificate number, the
 * quality-check verdict, the iteration counter and the system IP. Those are the
 * server's to write; a client that could set them could forge a cleared
 * certificate.
 */
class SaveExaminationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware owns access; this owns shape
    }

    public function rules(): array
    {
        return [
            /* ── The examination ─────────────────────────────────────────── */
            'exam_date'   => 'nullable|date|before_or_equal:today',
            'valid_until' => 'nullable|date|after_or_equal:exam_date',
            'fitness_status' => ['required', Rule::in(TpvMedicalFitness::ALL)],
            'restrictions'   => 'nullable|string|max:2000',
            'doctor_remarks' => 'nullable|string|max:5000',
            'is_reexam'      => 'sometimes|boolean',

            /* ── Vitals ──────────────────────────────────────────────────── */
            'height_cm'        => 'nullable|numeric|min:50|max:260',
            'weight_kg'        => 'nullable|numeric|min:20|max:300',
            'bp_systolic'      => 'nullable|integer|min:50|max:300',
            'bp_diastolic'     => 'nullable|integer|min:30|max:200',
            'pulse_bpm'        => 'nullable|integer|min:20|max:250',
            'spo2'             => 'nullable|integer|min:50|max:100',
            'temperature_c'    => 'nullable|numeric|min:30|max:45',
            'respiratory_rate' => 'nullable|integer|min:5|max:80',
            'blood_group'      => 'nullable|string|max:8',

            /* ── Sensory ─────────────────────────────────────────────────── */
            'vision'        => 'nullable|string|max:60',
            'vision_left'   => 'nullable|string|max:20',
            'vision_right'  => 'nullable|string|max:20',
            'colour_vision' => 'nullable|in:Normal,Deficient',
            'hearing'       => 'nullable|in:Normal,Impaired',

            /* ── Investigations — [{name, result, remarks}] ───────────────── */
            'investigations'           => 'nullable|array|max:40',
            'investigations.*.name'    => 'required|string|max:120',
            'investigations.*.result'  => 'nullable|string|max:120',
            'investigations.*.remarks' => 'nullable|string|max:255',

            /* ── Declared history ────────────────────────────────────────── */
            'medical_history'              => 'nullable|array',
            'medical_history.conditions'   => 'nullable|array|max:40',
            'medical_history.conditions.*' => 'string|max:120',
            'medical_history.surgeries'    => 'nullable|array|max:40',
            'medical_history.surgeries.*'  => 'string|max:160',
            'medical_history.habits'       => 'nullable|array|max:20',
            'medical_history.habits.*'     => 'string|max:60',
            'medical_history.questionnaire' => 'nullable|array',
            'allergies'          => 'nullable|string|max:1000',
            'current_medication' => 'nullable|string|max:1000',

            /* ── Mental-health screening ─────────────────────────────────── */
            // The band is derived from the score server-side — not accepted here.
            'screening_responses' => 'nullable|array',
            'screening_score'     => 'nullable|integer|min:0|max:60',

            /* ── Health score override ───────────────────────────────────── */
            // Omit it and the score is computed from the examination. Sending
            // one records that a doctor stated it instead.
            'health_score'      => 'nullable|numeric|min:'.HealthScore::MIN.'|max:'.HealthScore::MAX,
            'health_score_note' => 'nullable|string|max:255',

            /* ── Legal capture — REQUIRED ────────────────────────────────── */
            //
            // These three are what make the certificate evidence rather than an
            // assertion: WHERE the examination happened, WHO signed it, and a
            // photograph taken at the time. A certificate missing any of them
            // cannot be stood behind afterwards, so the submission is refused
            // rather than filed with a gap nobody notices until it matters.
            //
            // Enforced HERE and not only in the browser: a form can be bypassed,
            // and this endpoint is the only thing that actually issues.
            //
            // The exception is a re-record of an examination already on file
            // (see withValidator) — the capture belongs to the original visit.
            'signature_data' => 'required|string',
            'capture_photo'  => 'required|string',
            // "lat,long" as the browser reports it. The format is checked because
            // an unparseable value would silently become no location at all.
            'geo_location'   => ['required', 'string', 'max:120', 'regex:/^-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/'],

            /* ── Evidence ────────────────────────────────────────────────── */
            'report_file'  => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'examiner_name' => 'nullable|string|max:120',
            'clinic_name'   => 'nullable|string|max:160',
        ];
    }

    public function messages(): array
    {
        return [
            'fitness_status.required' => 'Record a fitness outcome — an examination without an opinion is not a certificate.',
            'exam_date.before_or_equal' => 'An examination cannot be dated in the future.',
            'signature_data.required' => 'Sign the examination before submitting — an unsigned certificate cannot be issued.',
            'capture_photo.required'  => 'Take the camera photo before submitting — it is part of the certificate.',
            'geo_location.required'   => 'Location is required. Allow location access in your browser, then try again.',
            'geo_location.regex'      => 'That location could not be read. Allow location access in your browser and let it refresh.',
        ];
    }
}
