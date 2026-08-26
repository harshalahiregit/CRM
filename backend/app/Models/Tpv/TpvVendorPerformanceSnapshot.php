<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * §27 — a point-in-time VPI snapshot for a vendor, optionally scoped to a project,
 * so performance history persists across projects rather than only being computed
 * live from current data.
 */
class TpvVendorPerformanceSnapshot extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_vendor_performance_snapshots';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'project', 'overall_score', 'band', 'dimensions', 'captured_at',
    ];

    protected $casts = [
        'dimensions'    => 'array',
        'overall_score' => 'integer',
        'captured_at'   => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
