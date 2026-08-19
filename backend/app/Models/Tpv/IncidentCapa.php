<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A corrective or preventive action raised from an incident. An incident cannot
 * be closed until every CAPA is Verified.
 */
class IncidentCapa extends Model
{
    use BelongsToTenant;

    protected $table = 'incident_capas';

    public const TYPES    = ['Corrective', 'Preventive'];
    public const STATUSES = ['Open', 'In_Progress', 'Done', 'Verified'];

    protected $fillable = [
        'tenant_id', 'incident_id', 'type', 'description', 'assigned_to',
        'due_date', 'status', 'completed_at', 'verified_at', 'verified_by', 'notes',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function incident()
    {
        return $this->belongsTo(HsseIncident::class, 'incident_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function isVerified(): bool
    {
        return $this->status === 'Verified';
    }
}
