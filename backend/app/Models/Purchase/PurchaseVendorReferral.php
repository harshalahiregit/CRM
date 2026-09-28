<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company one Purchase vendor has introduced.
 *
 * The subject is NOT a vendor yet — that is the point of the record. It carries
 * its own contact details rather than pointing at purchase_vendors, because
 * most referrals never become one, and the few that do are onboarded through
 * the normal route rather than by promoting this row.
 */
class PurchaseVendorReferral extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_referrals';

    /** Pending → Contacted → Onboarded / Declined. */
    public const STATUSES = ['Pending', 'Contacted', 'Onboarded', 'Declined'];

    protected $fillable = [
        'tenant_id', 'referred_by_purchase_vendor_id',
        'company_name', 'contact_name', 'contact_email', 'contact_phone',
        'note', 'status',
    ];

    public function referrer()
    {
        return $this->belongsTo(PurchaseVendor::class, 'referred_by_purchase_vendor_id');
    }

    public static function isValidStatus(?string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
