<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Purchase-side HSSE incident — the vendor reports; the HSSE team investigates. */
class PurchaseHsseIncident extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_hsse_incidents';

    public const TYPES = ['Injury', 'First_Aid', 'Medical_Treatment', 'LTI', 'Near_Miss', 'Property_Damage', 'Environmental', 'Fire', 'Security', 'Unsafe_Act', 'Unsafe_Condition', 'Fatality', 'Other'];
    public const SEVERITIES = ['Minor', 'Moderate', 'Serious', 'Fatal'];
    public const STATUSES = ['Reported', 'Investigating', 'Closed'];

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'reference', 'title', 'type', 'severity',
        'status', 'occurred_at', 'location', 'description', 'immediate_action',
    ];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseHsseIncident $i) {
            if (empty($i->reference)) {
                $year = date('Y');
                $count = static::where('tenant_id', $i->tenant_id)->whereYear('created_at', $year)->count() + 1;
                $i->reference = 'PINC-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }
}
