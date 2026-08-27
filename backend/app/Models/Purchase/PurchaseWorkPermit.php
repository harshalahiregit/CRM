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
    ];

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date'];

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
