<?php

namespace App\Models\Purchase;

use App\Models\Traits\Auditable;
use App\Models\Traits\BelongsToTenant;
use App\Models\User;
use App\Support\Purchase\PurchaseStrikeSeverity as Severity;
use Illuminate\Database\Eloquent\Model;

/**
 * One safety strike against a Purchase vendor's worker.
 *
 * The mirror of TpvSafetyStrike. A voided strike stays on the ledger and stops
 * counting — the row is never removed, because an appeal that erases its own
 * evidence is not a record of anything.
 */
class PurchaseSafetyStrike extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'purchase_worker_strikes';

    protected $fillable = [
        'tenant_id', 'purchase_worker_id', 'issued_by', 'severity', 'reason', 'notes',
        'occurred_at', 'location', 'voided_at', 'voided_by', 'void_reason', 'triggered_termination',
    ];

    protected $casts = [
        'occurred_at'           => 'datetime',
        'voided_at'             => 'datetime',
        'triggered_termination' => 'boolean',
    ];

    protected $appends = ['severity_label', 'is_active'];

    public function worker()
    {
        return $this->belongsTo(PurchaseWorker::class, 'purchase_worker_id');
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
