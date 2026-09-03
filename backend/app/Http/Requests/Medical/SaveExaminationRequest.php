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

            /* ── Legal capture ───────────────────────────────────────────── */
            // Drawn signature and camera capture arrive as base64 data URLs and
            // are decoded to stored files by the service.
            'signature_data' => 'nullable|string',
            'capture_photo'  => 'nullable|string',
            'geo_location'   => 'nullable|string|max:120',

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
        ];
    }
}
