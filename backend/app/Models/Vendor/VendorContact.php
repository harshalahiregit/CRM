<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorContact extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'vendor_contacts';

    protected $fillable = [
        'tenant_id','vendor_id','name','designation','email','phone','is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
