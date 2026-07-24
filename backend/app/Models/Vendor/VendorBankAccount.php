<?php

namespace App\Models\Vendor;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Normalized bank account for a vendor, mirrored from the Step-2 onboarding
 * profile. Kept separate from the profile JSON so the bank details can be
 * verified/reported on without parsing the blob.
 */
class VendorBankAccount extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'vendor_bank_accounts';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'account_holder', 'bank_name',
        'account_number', 'ifsc', 'branch', 'account_type',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
