<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A TPV work package (Sangoe TPV §13) — a scoped chunk of a vendor's engagement
 * on a project, holding activities and deployed workers.
 */
class TpvWorkPackage extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_work_packages';

    public const STATUSES = ['Planned', 'Active', 'On_Hold', 'Completed', 'Closed'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'project_id', 'contract_id', 'name', 'description',
        'scope', 'location', 'start_date', 'end_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (TpvWorkPackage $wp) {
            if (empty($wp->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $wp->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $wp->reference = 'WP-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function activities()
    {
        return $this->hasMany(TpvActivity::class, 'work_package_id')->orderBy('sort_order');
    }

    public function workers()
    {
        return $this->hasMany(TpvWorker::class, 'work_package_id');
    }
}
