<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Performance › Award / Reward — an admin-granted recognition for a TPV vendor. */
class VendorAward extends Model
{
    use BelongsToTenant;

    protected $table = 'vendor_awards';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'title', 'category', 'description', 'awarded_on', 'granted_by',
    ];

    protected $casts = [
        'awarded_on' => 'date',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
