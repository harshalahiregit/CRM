<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\CapaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A generalised Corrective / Preventive Action (Sangoe TPV §25). Raised from any
 * governance source (NCR, inspection/audit, meeting, violation, renewal,
 * incident) or standalone, and closed only when Verified with evidence.
 *
 * Distinct from IncidentCapa, which stays the incident-close gate; this is the
 * cross-source register the doc describes.
 */
class TpvCapa extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_capas';

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'source_kind', 'source_type', 'source_id',
        'title', 'problem_statement', 'type', 'root_cause', 'immediate_correction',
        'action', 'preventive_action', 'priority', 'status', 'assigned_to',
        'due_date', 'completed_at', 'evidence_path', 'verification_notes',
        'verified_at', 'verified_by', 'raised_by', 'notes',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    protected $appends = ['is_overdue', 'source_label'];

    protected static function booted(): void
    {
        static::creating(function (TpvCapa $capa) {
            if (empty($capa->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $capa->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $capa->reference = 'CAPA-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && $this->status !== 'Verified';
    }

    public function getSourceLabelAttribute(): string
    {
        return CapaSource::label($this->source_kind);
    }

    public function isVerified(): bool
    {
        return $this->status === 'Verified';
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function source()
    {
        return $this->morphTo();
    }
}
