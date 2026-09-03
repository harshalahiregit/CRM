<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase vendor's worker. Purchase-owned (purchase_workers) — completely
 * independent of the TPV tpv_workers engine. Always belongs to a purchase_vendor_id.
 */
class PurchaseWorker extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_workers';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'created_by',
        'worker_code', 'full_name', 'gender', 'dob', 'phone', 'email', 'designation',
        'id_proof_type', 'id_proof_number', 'address', 'city', 'state', 'pincode',
        'photo_path', 'status', 'notes',
        // TPV parity — identity depth and the employment facts a badge is issued
        // against. The medical/induction/PPE/training state deliberately stays in
        // its own tables rather than being mirrored here.
        'blood_group', 'skill_category', 'trade', 'age_reason',
        'emergency_contact', 'emergency_phone', 'bocw_number',
        'experience_years', 'joining_date', 'exit_date',
        'project', 'site', 'department',
        // Workforce lifecycle. current_step and the badge are written by the
        // service (forceFill), never mass-assigned from a request — a vendor must
        // not be able to post itself a badge.
        'current_step',
    ];

    protected $casts = [
        'dob'               => 'date',
        'current_step'      => 'integer',
        'badge_issued_at'   => 'datetime',
        'badge_valid_until' => 'date',
        'joining_date'      => 'date',
        'exit_date'         => 'date',
        'experience_years'  => 'float',
        'card_issued_at'    => 'datetime',
        'punch_count'       => 'integer',
        'punch_1_at'        => 'datetime',
        'punch_2_at'        => 'datetime',
        'punch_3_at'        => 'datetime',
    ];

    /** The gate scans this; it is a credential, so it never rides along in a payload. */
    protected $hidden = ['qr_token'];

    public function ppeIssues()
    {
        return $this->hasMany(PurchaseWorkerPpeIssue::class, 'purchase_worker_id');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function documents()
    {
        return $this->hasMany(PurchaseWorkerDocument::class, 'purchase_worker_id');
    }

    public function medicals()
    {
        return $this->hasMany(PurchaseWorkerMedical::class, 'purchase_worker_id');
    }

    public function trainings()
    {
        return $this->hasMany(PurchaseWorkerTraining::class, 'purchase_worker_id');
    }

    public function inductions()
    {
        return $this->hasMany(PurchaseWorkerInduction::class, 'purchase_worker_id');
    }

    public function competencies()
    {
        return $this->hasMany(PurchaseWorkerCompetency::class, 'purchase_worker_id');
    }

    /** The most recent medical / induction rows drive readiness. */
    public function latestMedical()
    {
        return $this->hasOne(PurchaseWorkerMedical::class, 'purchase_worker_id')->latestOfMany();
    }

    public function latestInduction()
    {
        return $this->hasOne(PurchaseWorkerInduction::class, 'purchase_worker_id')->latestOfMany();
    }
}
