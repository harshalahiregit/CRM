<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A TPV Non-Conformance Report (Sangoe TPV §24). Raised → Assigned → Response →
 * Corrective Action → Verification → Closed.
 */
class TpvNcr extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_ncrs';

    public const SEVERITIES = ['Minor', 'Major', 'Critical'];

    public const STATUSES = ['Raised', 'Assigned', 'Response', 'Corrective_Action', 'Verification', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'project_id', 'source_type', 'source_id', 'title',
        'requirement', 'finding', 'severity', 'status', 'responsible_by', 'due_date', 'response',
        'corrective_action', 'evidence_path', 'raised_by', 'verified_by', 'verified_at', 'closed_at', 'notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected $appends = ['is_overdue'];

    protected static function booted(): void
    {
        static::creating(function (TpvNcr $ncr) {
            if (empty($ncr->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $ncr->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $ncr->reference = 'NCR-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== 'Closed';
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_by');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
