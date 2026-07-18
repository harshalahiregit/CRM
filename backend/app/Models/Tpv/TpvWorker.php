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
        'tenant_id','vendor_id','created_by','worker_code',
        'name','dob','gender','designation','skill_category','aadhar_number','mobile',
        'blood_group','address','emergency_contact','emergency_phone','photo_path',
        'current_step','status',
        'badge_number','qr_token','badge_issued_at','badge_issued_by','badge_valid_until',
        'remarks',
    ];

    protected $casts = [
        'dob'               => 'date',
        'current_step'      => 'integer',
        'badge_issued_at'   => 'datetime',
        'badge_valid_until' => 'date',
    ];

    protected $appends = ['status_label', 'age', 'badge_valid'];

    // The QR token is a bearer credential for the gate — never serialise it in
    // list/detail payloads. The badge endpoint returns it explicitly.
    protected $hidden = ['qr_token'];

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
