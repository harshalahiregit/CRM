<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * A Permit-to-Work authorising a defined piece of high-risk work for a window.
 * Carries a JSA (permit_jsa_steps). Governance lives in PermitService.
 */
class WorkPermit extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'work_permits';

    /**
     * §19 permit types offered in the pickers. 'General' was renamed to 'Other';
     * see LEGACY_TYPES for values that historical rows may still carry.
     */
    public const TYPES = [
        'Hot_Work', 'Work_At_Height', 'Confined_Space', 'Electrical', 'Excavation',
        'Lifting', 'Isolation', 'Shutdown', 'Critical_Work', 'Other',
    ];

    /** Retired type values still accepted on write so old permits validate; not offered in pickers. */
    public const LEGACY_TYPES = ['General'];

    public const STATUSES = ['Requested', 'Approved', 'Active', 'Closed', 'Rejected', 'Expired'];

    /** Types a write may carry: the current set plus retired values for backward compatibility. */
    public static function acceptedTypes(): array
    {
        return array_merge(self::TYPES, self::LEGACY_TYPES);
    }

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'requested_by', 'type', 'title',
        'location', 'description', 'hazards', 'precautions', 'valid_from', 'valid_to',
        'status', 'approved_by', 'approved_at', 'decision_remarks', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_to'    => 'datetime',
        'approved_at' => 'datetime',
        'closed_at'   => 'datetime',
    ];

    protected $appends = ['is_expired'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function jsaSteps()
    {
        return $this->hasMany(PermitJsaStep::class, 'permit_id')->orderBy('step_no');
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_to !== null
            && $this->valid_to->isPast()
            && ! in_array($this->status, ['Closed', 'Rejected', 'Expired'], true);
    }
}
