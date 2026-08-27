<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** One package within a vendor shipment. */
class VendorShipmentPackage extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_shipment_packages';

    protected $fillable = [
        'tenant_id', 'vendor_shipment_id', 'description', 'qty', 'weight', 'dimensions',
    ];

    public function shipment()
    {
        return $this->belongsTo(VendorShipment::class, 'vendor_shipment_id');
    }
}
