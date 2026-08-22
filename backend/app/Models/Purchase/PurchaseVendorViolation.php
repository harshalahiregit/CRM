<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Support\Purchase\PurchaseViolationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vendor-level Purchase violation — mirror of TpvVendorViolation (parity). Points accumulate into escalation. */
class PurchaseVendorViolation extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_violations';

    public const SEVERITIES = ['Minor', 'Major', 'Critical'];

    public const STATUSES = ['Open', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'type', 'severity', 'description',
        'occurred_at', 'points', 'source_type', 'source_id', 'status', 'recorded_by', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'date',
        'points' => 'integer',
    ];

    protected $appends = ['type_label'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseVendorViolation $v) {
            if (empty($v->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $v->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $v->reference = 'PVIO-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
            if (empty($v->points)) {
                $v->points = PurchaseViolationType::pointsFor($v->severity);
            }
        });
    }

    public function getTypeLabelAttribute(): string
    {
        return PurchaseViolationType::typeLabel($this->type);
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }
}
