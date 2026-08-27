<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A dispatch notice (pre-alert) a TPV vendor sends us, with its packages. */
class VendorShipment extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_shipments';

    public const STATUSES = ['Pre-Alert', 'Dispatched', 'In-Transit', 'Delivered', 'Cancelled'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'reference', 'courier', 'tracking_number',
        'status', 'expected_date', 'dispatched_on', 'delivered_on', 'notes', 'created_by',
    ];

    protected $casts = [
        'expected_date' => 'date',
        'dispatched_on' => 'date',
        'delivered_on'  => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (VendorShipment $s) {
            if (empty($s->reference)) {
                $year = date('Y');
                $count = static::where('tenant_id', $s->tenant_id)->whereYear('created_at', $year)->count() + 1;
                $s->reference = 'SHP-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function packages()
    {
        return $this->hasMany(VendorShipmentPackage::class, 'vendor_shipment_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
