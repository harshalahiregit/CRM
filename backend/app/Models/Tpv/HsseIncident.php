<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * An HSSE incident — reported, investigated (RCA), and closed only once its
 * CAPAs are verified. Serious/Fatal or stop-work incidents auto-suspend the
 * responsible vendor (see IncidentService).
 */
class HsseIncident extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'hsse_incidents';

    public const SEVERITIES = ['Minor', 'Moderate', 'Serious', 'Fatal'];
    public const TYPES      = ['Injury', 'Near_Miss', 'Property_Damage', 'Environmental', 'Fire', 'Fatality', 'Other'];
    public const STATUSES   = ['Reported', 'Investigating', 'Closed'];

    /** Severities grave enough to withhold the vendor's site access on report. */
    public const SUSPENDING_SEVERITIES = ['Serious', 'Fatal'];

    protected $fillable = [
        'tenant_id', 'reference', 'vendor_id', 'tpv_worker_id', 'reported_by',
        'title', 'type', 'severity', 'status', 'occurred_at', 'location',
        'description', 'immediate_action', 'stop_work', 'triggered_suspension',
        'rca_method', 'root_cause', 'contributing_factors', 'rca_completed_at',
        'closed_at', 'closed_by',
    ];

    protected $casts = [
        'occurred_at'          => 'datetime',
        'rca_completed_at'     => 'datetime',
        'closed_at'            => 'datetime',
        'stop_work'            => 'boolean',
        'triggered_suspension' => 'boolean',
    ];

    protected $appends = ['rca_done'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function capas()
    {
        return $this->hasMany(IncidentCapa::class, 'incident_id');
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
