<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Purchase Vendor category — the master behind the Vendor Category dropdown on
 * the Purchase Vendor form. Purchase-owned; replaces the previous static list.
 */
class PurchaseVendorCategory extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_categories';

    protected $fillable = ['tenant_id', 'name', 'description', 'created_by'];

    /** How many Purchase Vendors currently sit in this category. */
    public function vendorCount(): int
    {
        return PurchaseVendor::forTenant($this->tenant_id)->where('category', $this->name)->count();
    }
}
