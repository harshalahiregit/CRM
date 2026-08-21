<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\ViolationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A vendor-level violation (Sangoe TPV §26). Points accumulate into escalation. */
class TpvVendorViolation extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_vendor_violations';

    public const SEVERITIES = ['Minor', 'Major', 'Critical'];

    public const STATUSES = ['Open', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'project_id', 'type', 'severity', 'description',
        'occurred_at', 'points', 'source_type', 'source_id', 'status', 'recorded_by', 'notes',
    ];

    protected $casts = [
        'occurred_at' => 'date',
        'points' => 'integer',
    ];

    protected $appends = ['type_label'];

    protected static function booted(): void
    {
        static::creating(function (TpvVendorViolation $v) {
            if (empty($v->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $v->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $v->reference = 'VIO-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
            if (empty($v->points)) {
                $v->points = ViolationType::pointsFor($v->severity);
            }
        });
    }

    public function getTypeLabelAttribute(): string
    {
        return ViolationType::typeLabel($this->type);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
