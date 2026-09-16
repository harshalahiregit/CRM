<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something a Purchase vendor has been recognised for.
 *
 * Purchase-owned. TPV's vendor_awards is keyed to its own `vendors` master and
 * the two never read each other's tables.
 */
class PurchaseVendorAward extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_awards';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id',
        'title', 'category', 'description', 'awarded_on', 'granted_by',
    ];

    protected $casts = [
        'awarded_on' => 'date',
    ];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
