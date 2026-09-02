<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/** A central-locker compliance-evidence record with a validity window (Doc 6). */
class ComplianceEvidence extends Model
{
    use BelongsToTenant;

    protected $table = 'compliance_evidence';

    public const CATEGORIES = ['Insurance', 'License', 'Certificate', 'Audit', 'Training', 'Policy', 'Other'];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'purchase_vendor_id', 'uploaded_by', 'category', 'title',
        'description', 'file_url', 'valid_from', 'valid_until',
    ];

    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date'];

    protected $appends = ['status'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Valid | Expiring (≤30d) | Expired | Open (no expiry). */
    public function getStatusAttribute(): string
    {
        if ($this->valid_until === null) {
            return 'Open';
        }
        if ($this->valid_until->isPast()) {
            return 'Expired';
        }
        if ($this->valid_until->lte(now()->addDays(30))) {
            return 'Expiring';
        }

        return 'Valid';
    }

    /** The PURCHASE vendor, when the evidence was filed from that module. */
    public function purchaseVendor()
    {
        return $this->belongsTo(\App\Models\Purchase\PurchaseVendor::class, 'purchase_vendor_id');
    }
}
