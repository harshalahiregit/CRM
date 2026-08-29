<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Purchase-side HSSE incident — the Purchase mirror of tpv HsseIncident. The
 * vendor reports; the HSSE team investigates (RCA) and drives corrective actions
 * (CAPA) to Verified before it can be closed. A Serious/Fatal or stop-work
 * incident auto-suspends the responsible vendor (see PurchaseIncidentService).
 */
class PurchaseHsseIncident extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_hsse_incidents';

    public const TYPES = ['Injury', 'First_Aid', 'Medical_Treatment', 'LTI', 'Near_Miss', 'Property_Damage', 'Environmental', 'Fire', 'Security', 'Unsafe_Act', 'Unsafe_Condition', 'Fatality', 'Other'];
    public const SEVERITIES = ['Minor', 'Moderate', 'Serious', 'Fatal'];
    public const STATUSES = ['Reported', 'Investigating', 'Closed'];

    /** Severities grave enough to withhold the vendor's site access on report. */
    public const SUSPENDING_SEVERITIES = ['Serious', 'Fatal'];

    protected $fillable = [
        'tenant_id', 'purchase_vendor_id', 'reported_by', 'reference', 'title', 'type', 'severity',
        'status', 'occurred_at', 'location', 'description', 'immediate_action', 'stop_work',
        'triggered_suspension', 'rca_method', 'root_cause', 'contributing_factors',
        'rca_completed_at', 'closed_at', 'closed_by',
    ];

    protected $casts = [
        'occurred_at'          => 'datetime',
        'rca_completed_at'     => 'datetime',
        'closed_at'            => 'datetime',
        'stop_work'            => 'boolean',
        'triggered_suspension' => 'boolean',
    ];

    protected $appends = ['rca_done'];

    protected static function booted(): void
    {
        static::creating(function (PurchaseHsseIncident $i) {
            if (empty($i->reference)) {
                $year = date('Y');
                $count = static::where('tenant_id', $i->tenant_id)->whereYear('created_at', $year)->count() + 1;
                $i->reference = 'PINC-'.$year.'-'.str_pad((string) $count, 3, '0', STR_PAD_LEFT);
            }
        });
    }

    public function vendor()
    {
        return $this->belongsTo(PurchaseVendor::class, 'purchase_vendor_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Linked corrective/preventive actions from the unified Purchase CAPA register
     * (source_kind = 'incident'). The Purchase side has no dedicated IncidentCapa
     * model — it reuses purchase_capas polymorphically, unlike the TPV mirror.
     */
    public function capas()
    {
        return $this->morphMany(PurchaseCapa::class, 'source');
    }

    public function getRcaDoneAttribute(): bool
    {
        return $this->rca_completed_at !== null;
    }

    /** Whether reporting this incident should suspend the vendor. */
    public function isSuspending(): bool
    {
        return $this->stop_work || in_array($this->severity, self::SUSPENDING_SEVERITIES, true);
    }
}
