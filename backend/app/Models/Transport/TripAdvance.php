<?php

namespace App\Models\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\AdvanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DB-007 `trip_advances` — money advanced against a trip, before it earns any.
 *
 * Step 9 calls an Advance "pre-trip/operational funding" and notes that
 * "approval policy applies". The policy is BR-P0-005: a request plus everything
 * already committed on the same trip may not exceed the configured exposure.
 * That rule is what makes this a control rather than a form, and it is enforced
 * in TripAdvanceService, never here — a model that decided its own approvals
 * would be bypassed by the first caller that used ::create().
 *
 * ── NO SOFT DELETES ──────────────────────────────────────────────────────
 * A request that was refused is `rejected` and stays. Deleting one erases the
 * evidence that somebody asked for money and was told no, which is exactly the
 * record BRW-008 and Step 13's FIN-01 exist to keep.
 *
 * ── amount_approved MAY BE LESS THAN amount_requested ────────────────────
 * TRP-P0-007 wants the system to show "the permitted amount and the reason for
 * exception". Storing only one figure would lose the difference between "asked
 * for 20,000 and got it" and "asked for 20,000 and got the 15,000 policy
 * allows", and the second is the case the control exists to produce.
 */
class TripAdvance extends Model
{
    use HasFactory, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_advances';

    protected $fillable = [
        'tenant_id', 'trip_id', 'driver_id', 'supplier_id',
        'amount_requested', 'amount_approved', 'currency',
        'purpose', 'payment_method', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_reason',
        'limit_overridden', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'trip_id'          => 'integer',
        'driver_id'        => 'integer',
        'supplier_id'      => 'integer',
        // Cast as string, not float. FIN-06 blocks a release on float drift, and
        // these are compared against approved_freight to decide whether money
        // may leave — a comparison that must not round.
        'amount_requested' => 'decimal:2',
        'amount_approved'  => 'decimal:2',
        'limit_overridden' => 'boolean',
        'decided_at'       => 'datetime',
    ];

    protected $appends = ['status_label'];

    /* ── Relations ────────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Advances whose money is still committed to the trip.
     *
     * Approved-but-unpaid counts. BR-P0-005 is about exposure, and a second
     * approval issued while the first is awaiting payment funds the same driver
     * twice for the same journey.
     */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereIn('status', AdvanceStatus::COMMITTED);
    }

    /* ── Derived ──────────────────────────────────────────────────────── */

    /**
     * What this advance holds against the trip.
     *
     * The approved figure once there is one, the requested figure before that.
     * A request awaiting a decision is not yet exposure — scopeCommitted()
     * excludes it — but once approved for less than was asked, the smaller
     * number is what was actually committed.
     */
    public function committedAmount(): string
    {
        return (string) ($this->amount_approved ?? $this->amount_requested);
    }

    public function getStatusLabelAttribute(): string
    {
        return AdvanceStatus::label((string) $this->status);
    }
}
