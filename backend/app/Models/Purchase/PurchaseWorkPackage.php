<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named parcel of scope a vendor is on site to deliver — the accountability
 * spine a worker, a permit and an authorisation all hang off.
 */
class PurchaseWorkPackage extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_work_packages';

    public const STATUSES = ['Planned', 'Active', 'On_Hold', 'Completed', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'reference', 'purchase_vendor_id', 'project_id', 'contract_id',
        'name', 'description', 'scope', 'location',
        'start_date', 'end_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseWorkPackage $wp) {
            if (empty($wp->reference)) {
                $year = date('Y');
                $n = static::withTrashed()->where('tenant_id', $wp->tenant_id)
                    ->whereYear('created_at', $year)->count() + 1;
                $wp->reference = 'PWP-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function activities()
    {
        return $this->hasMany(PurchaseActivity::class, 'work_package_id')->orderBy('sort_order');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function workers()
    {
        return $this->hasMany(PurchaseWorker::class, 'work_package_id');
    }
}
