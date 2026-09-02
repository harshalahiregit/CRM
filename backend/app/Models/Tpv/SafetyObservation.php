<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/** A behaviour-based safety observation — a leading indicator (Doc_4 Phase 5/6). */
class SafetyObservation extends Model
{
    use BelongsToTenant;

    protected $table = 'safety_observations';

    public const CATEGORIES = ['Unsafe_Act', 'Unsafe_Condition', 'Positive', 'Near_Miss'];
    public const SEVERITIES = ['Low', 'Medium', 'High'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'purchase_vendor_id', 'observed_by', 'category', 'severity',
        'observed_at', 'location', 'description', 'action_taken', 'status', 'closed_at',
    ];

    protected $casts = ['observed_at' => 'datetime', 'closed_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function observer()
    {
        return $this->belongsTo(User::class, 'observed_by');
    }

    /**
     * The PURCHASE vendor, when the record was filed from that module.
     *
     * Separate from vendor() because the two vendor tables are separate by
     * design — one id column cannot address both, and reusing vendor_id would
     * silently point at whichever TPV vendor held the same number.
     */
    public function purchaseVendor()
    {
        return $this->belongsTo(\App\Models\Purchase\PurchaseVendor::class, 'purchase_vendor_id');
    }
}
