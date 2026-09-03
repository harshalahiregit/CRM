<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Medical\HealthScore;
use App\Support\Medical\MedicalQcStatus;
use App\Support\Medical\MedicalWorkflow;
use App\Support\Tpv\TpvMedicalFitness as Fitness;
use Illuminate\Database\Eloquent\Model;

class TpvWorkerMedical extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_worker_medicals';

    protected $fillable = [
        'tenant_id','tpv_worker_id','recorded_by','approved_by','approved_at','exam_type','exam_date','valid_until','examiner_name','clinic_name',
        'height_cm','weight_kg','bp_systolic','bp_diastolic','vision',
        'screening_responses','screening_score','screening_band',
        'fitness_status','restrictions','signature_path','certificate_path','document_path',
        'system_ip','geo_location','capture_photo_path',
        // Medical module — identity of the examination and where it came from.
        'certificate_no','attempt_no','origin',
        // The examining doctor, snapshotted so a later profile edit cannot
        // change what an already-issued certificate claims.
        'doctor_user_id','doctor_license_no','doctor_council','doctor_qualification','doctor_remarks',
        // Detailed examination form.
        'pulse_bpm','spo2','temperature_c','respiratory_rate','blood_group',
        'vision_left','vision_right','colour_vision','hearing',
        'investigations','medical_history','allergies','current_medication',
        // Health score (out of 10) and how it was arrived at.
        'health_score','health_score_source','health_score_note',
        // Quality check + the back-and-forth counter.
        'qc_status','qc_by','qc_at','qc_reason_code','qc_note','iteration_count',
        // Re-examination chain.
        'previous_medical_id','is_reexam',
        // Legal capture + generated prescription.
        'geo_place','pdf_path',
    ];

    protected $casts = [
        'exam_date'           => 'date',
        'valid_until'         => 'date',
        'approved_at'         => 'datetime',
        'qc_at'               => 'datetime',
        'height_cm'           => 'decimal:1',
        'weight_kg'           => 'decimal:1',
        'temperature_c'       => 'decimal:1',
        'health_score'        => 'float',
        'screening_responses' => 'array',
        'investigations'      => 'array',
        'medical_history'     => 'array',
        'screening_score'     => 'integer',
        'attempt_no'          => 'integer',
        'iteration_count'     => 'integer',
        'is_reexam'           => 'boolean',
    ];

    protected $appends = [
        'fitness_label', 'bmi', 'is_expired',
        'qc_label', 'origin_label', 'health_band', 'is_cleared',
    ];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The medical officer who signed off the fitness verdict (§16) — distinct
     *  from the clerk who keyed the exam in (recorded_by). */
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
        return $this->hasMany(TpvMedicalMessage::class, 'medical_id')->orderBy('id');
    }

    public function getFitnessLabelAttribute(): string
    {
        return Fitness::label($this->fitness_status);
    }

    /** Derived from the recorded measurements — never stored. */
    public function getBmiAttribute(): ?float
    {
        return HealthScore::bmi(
            $this->height_cm !== null ? (float) $this->height_cm : null,
            $this->weight_kg !== null ? (float) $this->weight_kg : null,
        );
    }

    public function isPassing(): bool
    {
        return Fitness::isPassing($this->fitness_status);
    }

    /** The certificate has lapsed — its currency window closed. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Serialized flag so the UI can badge a lapsed medical without recomputing. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    /**
     * Fit, still within its currency window, AND accepted by the quality team.
     *
     * The QC leg is what changed with the Medical module: a doctor's signature
     * starts the process, the reviewer's approval ends it, and only the latter
     * is clearance. Records made before the module existed carry no verdict, so
     * a null qc_status is read as approved rather than retroactively blocking
     * every worker already on site.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired() && $this->isQcCleared();
    }

    /** True while the certificate is still waiting on, or has failed, review. */
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
}
