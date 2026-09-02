<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Purchase-side Permit To Work — the vendor requests, the admin approves. */
class PurchaseWorkPermit extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_work_permits';

    public const TYPES = ['Hot_Work', 'Work_At_Height', 'Confined_Space', 'Electrical', 'Excavation', 'Lifting', 'Isolation', 'Shutdown', 'Critical_Work', 'Other'];
    public const STATUSES = ['Requested', 'Approved', 'Active', 'Closed', 'Rejected', 'Expired'];

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'reference', 'type', 'title', 'location',
        'description', 'hazards', 'precautions', 'valid_from', 'valid_to', 'status',
        // The approval trail. A permit to work IS its approval trail: without
        // these the row records an intention, not a permit.
        'requested_by', 'approved_by', 'approved_at', 'decision_remarks',
        'closed_at', 'closed_by',
    ];

    protected $casts = [
        'valid_from'  => 'date',
        'valid_to'    => 'date',
        'approved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    public function jsaSteps()
    {
        return $this->hasMany(PurchasePermitJsaStep::class, 'permit_id')->orderBy('step_no');
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    /**
     * Past its validity window. Read as an attribute rather than stored, so a
     * permit cannot sit in the table claiming to be valid after its own end
     * date simply because no scheduled job has run yet.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_to !== null && $this->valid_to->endOfDay()->isPast();
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseWorkPermit $p) {
            if (empty($p->reference)) {
                $year = date('Y');
                $count = static::where('tenant_id', $p->tenant_id)->whereYear('created_at', $year)->count() + 1;
                $p->reference = 'PPTW-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }
}
