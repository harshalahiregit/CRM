<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/** A pre-work toolbox talk / micro-training record (Doc_4 Phase 5/6). */
class ToolboxTalk extends Model
{
    use BelongsToTenant;

    protected $table = 'toolbox_talks';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'purchase_vendor_id', 'conducted_by', 'topic', 'held_at',
        'location', 'attendee_count', 'duration_minutes', 'notes',
    ];

    protected $casts = [
        'held_at'          => 'datetime',
        'attendee_count'   => 'integer',
        'duration_minutes' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conducted_by');
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
