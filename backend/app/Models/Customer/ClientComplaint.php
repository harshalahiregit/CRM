<?php

namespace App\Models\Customer;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §17 SERVICE — a complaint, or the escalation it turned into.
 *
 * One table rather than two: escalating is a change of level on the same
 * grievance, and modelling it as a separate record would mean copying a row and
 * then keeping two statuses in step.
 *
 * This is also what finally gives Customer Health a "complaint frequency"
 * signal (§8) — before this, that parameter could only ever say "No data yet".
 */
class ClientComplaint extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const KINDS      = ['Complaint', 'Escalation'];
    public const CATEGORIES = ['Service', 'Delivery', 'Billing', 'Quality', 'Conduct', 'Compliance', 'Other'];
    public const SEVERITIES = ['Low', 'Medium', 'High', 'Critical'];
    public const STATUSES   = ['Open', 'Investigating', 'Resolved', 'Closed'];

    /** Still costing the customer something. */
    public const OPEN_STATUSES = ['Open', 'Investigating'];

    protected $fillable = [
        'tenant_id', 'client_id', 'reference', 'kind', 'subject', 'description',
        'category', 'severity', 'status', 'source_type', 'source_id', 'owner_id',
        'raised_at', 'resolved_at', 'resolution', 'created_by',
    ];

    protected $casts = [
        'raised_at'   => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Hours from raised to resolved, for service-performance reporting. */
    public function getResolutionHoursAttribute(): ?float
    {
        if (! $this->resolved_at || ! $this->raised_at) {
            return null;
        }

        return round($this->raised_at->diffInMinutes($this->resolved_at) / 60, 1);
    }
}
