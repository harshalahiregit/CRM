<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use App\Support\Purchase\RfqVendorStatus as Status;
use Illuminate\Database\Eloquent\Model;

/**
 * One vendor on an RFQ's recipient list. The invite_token is reserved for a
 * future public/portal quote-submission link; unused while quotes are staff-entered.
 */
class PurchaseRfqVendor extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_rfq_vendors';

    protected $fillable = [
        'tenant_id','purchase_rfq_id','vendor_id','invite_token','status','responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    /** The token is a bearer credential for the (future) public submission link. */
    protected $hidden = ['invite_token'];

    protected $appends = ['status_label'];

    public function rfq()
    {
        return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::label($this->status);
    }
}
