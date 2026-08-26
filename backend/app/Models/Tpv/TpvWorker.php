<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvWorkerStatus as Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TpvWorker extends Model
{
    use Auditable, SoftDeletes, BelongsToTenant;

    protected $table = 'tpv_workers';

    protected $fillable = [
        'tenant_id','vendor_id','created_by','worker_code','work_package_id','activity_id',
        'name','dob','age','age_reason','gender','designation','skill_category','trade','experience_years','joining_date','exit_date','project','site','department','aadhar_number','mobile','email',
        'blood_group','address','emergency_contact','emergency_phone','photo_path',
        'current_step','status','lifecycle_state','training_status','is_active',
        // Step 2
        'medical_status','medical_type','doctor_name','organization_name','doctor_registration','doctor_designation',
        'eyesight','height','weight','blood_pressure','height_phobia','heart_disease','habits','handicapped',
        'system_ip','geo_location','mh_version','mh_q1','mh_q2','mh_q3','mh_q4','mh_q5','mh_q6','mh_score','mh_risk','mh_flag',
        'doctor_comments','physical_score','medical_result','signature_file','external_doctor_name','external_doctor_regid','external_pdf',
        // Step 3
        'induction_status','induction_type','trainer','induction_location','induction_start','induction_end','induction_duration',
        'induction_device','induction_photo','induction_signature','induction_thumb','induction_proof_file',
        // Step 4
        'ppe_status','ppe_items','ppe_issued_to','ppe_remarks','ppe_issued_at',
        // Step 5
        'card_status','card_issued_at','punch_count','punch_1_at','punch_1_reason','punch_2_at','punch_2_reason','punch_3_at','punch_3_reason','punch_log',
        'approval_status','approval_remarks','approved_at','approved_by',
        'badge_number','qr_token','badge_issued_at','badge_issued_by','badge_valid_until',
        'remarks',
        // Recognition + statutory health card surfaced on the digital card (§12).
        'awards','bocw_number',
    ];

    protected $casts = [
        'dob'               => 'date',
        'joining_date'      => 'date',
        'exit_date'         => 'date',
        'experience_years'  => 'decimal:1',
        'current_step'      => 'integer',
        'is_active'         => 'boolean',
        'punch_log'         => 'array',
        'induction_start'   => 'datetime',
        'induction_end'     => 'datetime',
        'ppe_issued_at'     => 'datetime',
        'punch_1_at'        => 'datetime',
        'punch_2_at'        => 'datetime',
        'punch_3_at'        => 'datetime',
        'card_issued_at'    => 'datetime',
        'approved_at'       => 'datetime',
        'badge_issued_at'   => 'datetime',
        'badge_valid_until' => 'date',
    ];

    protected $appends = ['status_label', 'age', 'badge_valid', 'aadhar_masked'];

    // The QR token is a bearer credential for the gate — never serialise it in
    // list/detail payloads. The badge endpoint returns it explicitly.
    // Aadhaar is PII (§7). The raw number is hidden from every default
    // serialization — lists, cards, the public scan — and only the last four
    // digits leak via `aadhar_masked`. The single-worker edit view re-reveals it
    // with ->makeVisible('aadhar_number') for the managing staff/owner who key it.
    protected $hidden = ['qr_token', 'aadhar_number'];

    /* ── Code auto-generation ─────────────────────── */
    protected static function booted(): void
    {
        static::creating(function (TpvWorker $w) {
            if (empty($w->worker_code)) {
                $year  = date('Y');
                $count = static::withTrashed()
                               ->where('tenant_id', $w->tenant_id)
                               ->whereYear('created_at', $year)
                               ->count() + 1;
                $w->worker_code = 'WRK-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    /* ── Relationships ──────────────────────────────────────────────────── */

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** The work package this worker is deployed on (§13 — Vendor→Project→WP→Worker). */
    public function workPackage()
    {
        return $this->belongsTo(TpvWorkPackage::class, 'work_package_id');
    }

    /** The specific activity within the work package this worker is assigned to (§13). */
    public function activity()
    {
        return $this->belongsTo(TpvActivity::class, 'activity_id');
    }

    public function medical()
    {
        return $this->hasOne(TpvWorkerMedical::class, 'tpv_worker_id');
    }

    public function induction()
    {
        return $this->hasOne(TpvWorkerInduction::class, 'tpv_worker_id');
    }

    public function ppeIssues()
    {
        return $this->hasMany(TpvWorkerPpeIssue::class, 'tpv_worker_id')->latest('issued_date');
    }

    public function strikes()
    {
        return $this->hasMany(TpvSafetyStrike::class, 'tpv_worker_id')->latest('occurred_at');
    }

    /** Competency records (§15) — qualification/trade-cert/licence/certification/skill. */
    public function competencies()
    {
        return $this->hasMany(TpvWorkerCompetency::class, 'tpv_worker_id')->latest('id');
    }

    /** Typed training records (§15). */
    public function trainings()
    {
        return $this->hasMany(TpvWorkerTraining::class, 'tpv_worker_id')->latest('id');
    }

    public function attendances()
    {
        return $this->hasMany(TpvGateAttendance::class, 'tpv_worker_id')->latest('work_date');
    }

    public function gateScans()
    {
        return $this->hasMany(TpvGateScan::class, 'tpv_worker_id')->latest('scanned_at');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->dob ? $this->dob->age : null;
    }

    /**
     * Privacy-safe Aadhaar (§7): all but the last four digits masked, e.g.
     * "XXXX XXXX 1234". Null stays null; short/odd values still mask everything
     * but the trailing four so nothing over-exposes.
     */
    public function getAadharMaskedAttribute(): ?string
    {
        $raw = preg_replace('/\D/', '', (string) $this->aadhar_number);
        if ($raw === '' || $raw === null) {
            return null;
        }
        $last4 = substr($raw, -4);
        $hiddenLen = max(0, strlen($raw) - 4);

        return trim(chunk_split(str_repeat('X', $hiddenLen).$last4, 4, ' '));
    }

    /** Badge is only meaningful while Active and within its validity window. */
    public function getBadgeValidAttribute(): bool
    {
        return $this->status === Status::ACTIVE
            && $this->badge_issued_at !== null
            && (! $this->badge_valid_until || ! $this->badge_valid_until->isPast());
    }

    public function isEditable(): bool
    {
        return Status::isEditable($this->status);
    }

    /* ── Scopes ─────────────────────────────────────────────────────────── */

    public function scopeActive($query)
    {
        return $query->where('status', Status::ACTIVE);
    }
}
