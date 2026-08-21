<?php

namespace App\Models\Purchase;

use App\Models\Traits\BelongsToTenant;
use App\Support\Purchase\PurchaseComplianceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A per-vendor, per-category Purchase compliance record — the Purchase-side
 * mirror of TpvVendorCompliance (parity rule). Expiry overrides the stored
 * status so lapsed cover reads Expired/Expiring automatically (Rule 8).
 */
class PurchaseVendorCompliance extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'purchase_vendor_compliance';

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'category', 'status', 'requirement',
        'valid_until', 'evidence_path', 'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['effective_status', 'category_label'];

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function getCategoryLabelAttribute(): string
    {
        return PurchaseComplianceCatalog::label($this->category);
    }

    /**
     * Expiry drives status (Rule 8): a Compliant record whose validity has lapsed
     * reads Expired; within 30 days, Expiring. Waived/Non-Compliant are left as set.
     */
    public function getEffectiveStatusAttribute(): string
    {
        if (in_array($this->status, ['Waived', 'Non_Compliant', 'Under_Review'], true)) {
            return $this->status;
        }
        if ($this->valid_until !== null) {
            if ($this->valid_until->isPast()) {
                return 'Expired';
            }
            if ($this->valid_until->lte(now()->addDays(30))) {
                return 'Expiring';
            }
        }

        return $this->status;
    }
}
