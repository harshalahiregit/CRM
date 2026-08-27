<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Performance › Referral — a company a TPV vendor referred to us. */
class VendorReferral extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_referrals';

    public const STATUSES = ['New', 'Contacted', 'Converted', 'Declined'];

    protected $fillable = [
        'tenant_id', 'referred_by_vendor_id', 'referred_by_purchase_vendor_id', 'company_name', 'contact_name',
        'contact_email', 'contact_phone', 'note', 'status',
    ];

    public function referredByVendor()
    {
        return $this->belongsTo(Vendor::class, 'referred_by_vendor_id');
    }
}
