<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseCapaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase Corrective / Preventive Action — the Purchase-side mirror of TpvCapa
 * (parity rule). Raised from any governance source (NCR, inspection/audit,
 * meeting, renewal) or standalone; closed only when Verified with evidence.
 */
class PurchaseCapa extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_capas';

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'source_kind', 'source_type', 'source_id',
        'title', 'type', 'root_cause', 'action', 'priority', 'status', 'assigned_to',
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
        static::creating(function (PurchaseCapa $capa) {
            if (empty($capa->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $capa->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $capa->reference = 'PCAPA-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
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
        return PurchaseCapaSource::label($this->source_kind);
    }

    public function isVerified(): bool
    {
        return $this->status === 'Verified';
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
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
