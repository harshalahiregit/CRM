<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\InspectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A TPV inspection/audit (Sangoe TPV §22). Carries findings. */
class TpvInspection extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_inspections';

    public const STATUSES = ['Planned', 'In_Progress', 'Completed', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'project_id', 'work_package_id', 'type', 'title',
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
        static::creating(function (TpvInspection $i) {
            if (empty($i->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $i->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $i->reference = 'INS-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function getTypeLabelAttribute(): string
    {
        return InspectionType::label($this->type);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_by');
    }

    public function findings()
    {
        return $this->hasMany(TpvInspectionFinding::class, 'inspection_id')->latest('id');
    }
}
