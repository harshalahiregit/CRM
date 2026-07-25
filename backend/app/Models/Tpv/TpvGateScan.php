<?php

namespace App\Models\Tpv;

use App\Models\Traits\BelongsToTenant;
use App\Support\Tpv\GateDecision;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only log of every badge presented at the gate — the Gate Log. Never
 * updated or deleted; it is the record of who was let in and who was refused.
 */
class TpvGateScan extends Model
{
    use BelongsToTenant;

    protected $table = 'tpv_gate_scans';

    protected $fillable = [
        'tenant_id','tpv_worker_id','decision','reasons','action','gate','ip','user_agent','scanned_at',
    ];

    protected $casts = [
        'reasons'    => 'array',
        'scanned_at' => 'datetime',
    ];

    protected $appends = ['decision_label'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function getDecisionLabelAttribute(): string
    {
        return GateDecision::label($this->decision);
    }

    public function scopeDenied($query)
    {
        return $query->where('decision', GateDecision::DENY);
    }
}
