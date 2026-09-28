<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\CollectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DB-013 `trip_collections` — what a customer still owes on a trip.
 * SNG-TRN-016.
 *
 * Tracking, not cash. Accounts posts the money (EVT-011's producer, CTR-014's
 * "posting event generated"); this row records the due date, the balance, why
 * it is stuck and who chased it. FORBID-002 / LOCK-004.
 *
 * ── `status` IS NOT FILLABLE ────────────────────────────────────────────
 * It is derived from amount_due and amount_received by
 * TripCollectionService::recompute(), and nothing else writes it. A receivable
 * whose status disagrees with its own arithmetic is worse than one with no
 * status — and the ageing index is built on that column, so a wrong value is a
 * wrong report rather than a wrong screen.
 *
 * @property int    $tenant_id
 * @property int    $trip_id
 * @property string $amount_due
 * @property string $amount_received
 * @property string $status
 */
class TripCollection extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_collections';

    protected $fillable = [
        'tenant_id', 'trip_id', 'bill_id', 'amount_due', 'currency',
        'due_date', 'next_follow_up_on', 'notes', 'created_by', 'updated_by',
        // amount_received, status, settled_at, blocker_reason and the
        // follow-up stamps are NOT fillable — every one of them is the result
        // of an act the service records and audits, not a field a caller sets.
    ];

    protected $casts = [
        'trip_id'             => 'integer',
        'bill_id'             => 'integer',
        // Decimal strings, never floats. FIN-06 — these are compared with
        // bccomp to decide whether a receivable is settled.
        'amount_due'          => 'decimal:2',
        'amount_received'     => 'decimal:2',
        'due_date'            => 'date',
        'next_follow_up_on'   => 'date',
        'blocked_at'          => 'datetime',
        'last_followed_up_at' => 'datetime',
        'settled_at'          => 'datetime',
    ];

    // days_overdue is appended rather than left as a method: it is the single
    // most useful signal on a receivable, the ageing report buckets on it, and
    // a screen recomputing it from due_date would drift from the report the
    // moment either side changed its idea of "today".
    protected $appends = ['status_label', 'outstanding', 'days_overdue'];

    /* ── Relations ────────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(TripBill::class, 'bill_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    /** Still owes money — what the ageing report reads. IDX-009. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', CollectionStatus::OUTSTANDING);
    }

    /** Past its due date and still owing. */
    public function scopeOverdue(Builder $query, ?string $asOf = null): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $asOf ?? now()->toDateString());
    }

    /** Stuck for a stated reason — the "blockers" half of the criterion. */
    public function scopeBlocked(Builder $query): Builder
    {
        return $query->whereNotNull('blocker_reason');
    }

    /** Due to be chased on or before a date. */
    public function scopeDueForFollowUp(Builder $query, ?string $asOf = null): Builder
    {
        return $query->outstanding()
            ->whereNotNull('next_follow_up_on')
            ->whereDate('next_follow_up_on', '<=', $asOf ?? now()->toDateString());
    }

    /* ── Derived ──────────────────────────────────────────────────────── */

    /** What is still owed, as a decimal string. Never negative. */
    public function getOutstandingAttribute(): string
    {
        $balance = bcsub((string) $this->amount_due, (string) $this->amount_received, 2);

        return bccomp($balance, '0.00', 2) === 1 ? $balance : '0.00';
    }

    public function isSettled(): bool
    {
        return $this->status === CollectionStatus::SETTLED;
    }

    public function isBlocked(): bool
    {
        return $this->blocker_reason !== null;
    }

    /** Days past due. Negative means not yet due; null means no due date. */
    public function daysOverdue(?string $asOf = null): ?int
    {
        if ($this->due_date === null) {
            return null;
        }

        return (int) $this->due_date->diffInDays($asOf ?? now(), false);
    }

    public function getDaysOverdueAttribute(): ?int
    {
        return $this->daysOverdue();
    }

    public function getStatusLabelAttribute(): string
    {
        return CollectionStatus::label((string) $this->status);
    }
}
