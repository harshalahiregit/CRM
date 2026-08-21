<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A TPV work order (Sangoe TPV §8) — a specific package of work under a contract
 * (or standalone). TPV-owned.
 */
class TpvWorkOrder extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_work_orders';

    public const STATUSES = ['Draft', 'Issued', 'In_Progress', 'Completed', 'Closed', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'contract_id', 'project_id', 'work_package', 'title',
        'scope', 'location', 'start_date', 'end_date', 'quantity', 'manpower_requirement',
        'equipment_requirement', 'commercial_terms', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'manpower_requirement' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (TpvWorkOrder $wo) {
            if (empty($wo->reference)) {
                $wo->reference = self::nextReference((int) $wo->tenant_id);
            }
        });
    }

    /** WO-YYYY-### per tenant per year. */
    public static function nextReference(int $tenantId): string
    {
        $year = date('Y');
        $n = static::withTrashed()->where('tenant_id', $tenantId)
            ->whereYear('created_at', $year)->count() + 1;

        return 'WO-'.$year.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function contract()
    {
        return $this->belongsTo(TpvContract::class, 'contract_id');
    }
}
