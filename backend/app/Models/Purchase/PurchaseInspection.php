<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseInspectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A Purchase inspection/audit — mirror of TpvInspection (parity). Carries findings. */
class PurchaseInspection extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_inspections';

    public const STATUSES = ['Planned', 'In_Progress', 'Completed', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'type', 'title',
        'scheduled_date', 'conducted_date', 'inspector_by', 'location', 'status', 'score', 'summary', 'created_by',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'conducted_date' => 'date',
        'score' => 'integer',
    ];

    protected $appends = ['type_label'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseInspection $i) {
            if (empty($i->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $i->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $i->reference = 'PINS-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getTypeLabelAttribute(): string
    {
        return PurchaseInspectionType::label($this->type);
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_by');
    }

    public function findings()
    {
        return $this->hasMany(PurchaseInspectionFinding::class, 'inspection_id')->latest('id');
    }
}
