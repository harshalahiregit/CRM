<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/** A gate vehicle-register entry (Doc_4 Phase 5/6). */
class SiteVehicle extends Model
{
    use BelongsToTenant;

    protected $table = 'site_vehicles';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'vehicle_number', 'vehicle_type', 'driver_name',
        'purpose', 'fitness_valid', 'check_in_at', 'check_out_at',
    ];

    protected $casts = ['fitness_valid' => 'boolean', 'check_in_at' => 'datetime', 'check_out_at' => 'datetime'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
