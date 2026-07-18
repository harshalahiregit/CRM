<?php

namespace App\Models\Tpv;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Tpv\StrikeSeverity as Severity;
use Illuminate\Database\Eloquent\Model;

class TpvSafetyStrike extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'tpv_safety_strikes';

    protected $fillable = [
        'tenant_id','tpv_worker_id','issued_by','severity','reason','notes',
        'occurred_at','location','voided_at','voided_by','void_reason','triggered_termination',
    ];

    protected $casts = [
        'occurred_at'           => 'datetime',
        'voided_at'             => 'datetime',
        'triggered_termination' => 'boolean',
    ];

    protected $appends = ['severity_label', 'is_active'];

    public function worker()
    {
        return $this->belongsTo(TpvWorker::class, 'tpv_worker_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function getSeverityLabelAttribute(): string
    {
        return Severity::label($this->severity);
    }

    /** A voided strike stays on the ledger but stops counting. */
    public function getIsActiveAttribute(): bool
    {
        return $this->voided_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }
}
