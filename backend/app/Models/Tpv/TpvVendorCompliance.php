<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\ComplianceCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A per-vendor, per-category compliance record (Sangoe TPV §21). Expiry overrides
 * the stored status so lapsed cover reads Expired/Expiring automatically (Rule 8).
 */
class TpvVendorCompliance extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'tpv_vendor_compliance';

    protected $fillable = [
        'tenant_id', 'vendor_id', 'category', 'status', 'requirement',
        'valid_until', 'evidence_path', 'reviewed_by', 'reviewed_at', 'notes',
    ];

    protected $casts = [
        'valid_until' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected $appends = ['effective_status', 'category_label'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function getCategoryLabelAttribute(): string
    {
        return ComplianceCatalog::label($this->category);
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
