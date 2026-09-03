<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Purchase\PurchaseMedicalFitness as Fitness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase worker's medical / fitness record. Purchase-owned. Mirrors TPV depth. */
class PurchaseWorkerMedical extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_worker_medicals';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'purchase_worker_id', 'created_by',
        'exam_date', 'expiry_date', 'fitness_status', 'blood_group', 'file_path', 'remarks',
        // Depth (TPV §16 parity) — restriction detail + sign-off + certificate.
        'restrictions', 'approved_by', 'approved_at', 'examiner_name',
        'certificate_path', 'document_path',
        // Examination depth, mirroring tpv_worker_medicals: who/what/where, the
        // vitals the fitness bands are computed from, the scored screening, and
        // the proof that ties the record to a place and a device.
        'recorded_by', 'exam_type', 'clinic_name', 'valid_until',
        'height_cm', 'weight_kg', 'bp_systolic', 'bp_diastolic', 'vision',
        'screening_responses', 'screening_score', 'screening_band',
        'signature_path', 'capture_photo_path', 'system_ip', 'geo_location',
        // Medical module — identity of the examination and where it came from.
        'certificate_no', 'attempt_no', 'origin',
        'doctor_user_id', 'doctor_license_no', 'doctor_council', 'doctor_qualification', 'doctor_remarks',
        // Detailed examination form.
        'pulse_bpm', 'spo2', 'temperature_c', 'respiratory_rate',
        'vision_left', 'vision_right', 'colour_vision', 'hearing',
        'investigations', 'medical_history', 'allergies', 'current_medication',
        // Health score (out of 10) and how it was arrived at.
        'health_score', 'health_score_source', 'health_score_note',
        // Quality check + the back-and-forth counter.
        'qc_status', 'qc_by', 'qc_at', 'qc_reason_code', 'qc_note', 'iteration_count',
        // Re-examination chain.
        'previous_medical_id', 'is_reexam',
        // Legal capture + generated prescription.
        'geo_place', 'pdf_path',
    ];

    protected $casts = [
        'exam_date'           => 'date',
        'expiry_date'         => 'date',
        'valid_until'         => 'date',
        'approved_at'         => 'datetime',
        'qc_at'               => 'datetime',
        // The raw screening answers are kept so a band can be re-derived if the
        // scoring ever changes, without rewriting the band already recorded.
        'screening_responses' => 'array',
        'investigations'      => 'array',
        'medical_history'     => 'array',
        'screening_score'     => 'integer',
        'height_cm'           => 'float',
        'weight_kg'           => 'float',
        'temperature_c'       => 'float',
        'health_score'        => 'float',
        'bp_systolic'         => 'integer',
        'bp_diastolic'        => 'integer',
        'attempt_no'          => 'integer',
        'iteration_count'     => 'integer',
        'is_reexam'           => 'boolean',
    ];

    protected $appends = [
        'fitness_label', 'is_passing', 'is_expired',
        'qc_label', 'origin_label', 'health_band', 'bmi', 'is_cleared',
    ];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
    }

    /** The medical officer who signed off the verdict (§16) — distinct from created_by. */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The doctor login that performed the examination (internal flow). */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_user_id');
    }

    /** The quality-team reviewer who approved, rejected or held it. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'qc_by');
    }

    /** The certificate this one re-examines, when it is a re-test. */
    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_medical_id');
    }

    /** The full communication history on this certificate, oldest first. */
    public function messages()
    {
        return $this->hasMany(PurchaseMedicalMessage::class, 'medical_id')->orderBy('id');
    }

    public function getFitnessLabelAttribute(): string
    {
        return Fitness::label($this->fitness_status);
    }

    /** Fit OR Fit-with-restrictions — the outcomes that allow a badge. */
    public function isPassing(): bool
    {
        return Fitness::isPassing($this->fitness_status);
    }

    public function getIsPassingAttribute(): bool
    {
        return $this->isPassing();
    }

    /** The certificate has lapsed — its currency window closed. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    /**
     * Passing, current, AND accepted by the quality team — the real "medical
     * clear" gate. Pre-module records carry no verdict; a null reads as
     * approved so existing workers are not retroactively blocked.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired() && $this->isQcCleared();
    }

    public function isQcCleared(): bool
    {
        return $this->qc_status === null || MedicalQcStatus::isCleared($this->qc_status);
    }

    public function getIsClearedAttribute(): bool
    {
        return $this->isCurrentlyValid();
    }

    public function getQcLabelAttribute(): string
    {
        return MedicalQcStatus::label($this->qc_status);
    }

    public function getOriginLabelAttribute(): string
    {
        return MedicalWorkflow::originLabel($this->origin);
    }

    public function getHealthBandAttribute(): ?string
    {
        return HealthScore::band($this->health_score);
    }

    /** Derived from the recorded measurements — never stored. */
    public function getBmiAttribute(): ?float
    {
        return HealthScore::bmi(
            $this->height_cm !== null ? (float) $this->height_cm : null,
            $this->weight_kg !== null ? (float) $this->weight_kg : null,
        );
    }
}
