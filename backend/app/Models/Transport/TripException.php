<?php

namespace App\Models\Transport;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionSlaState;
use App\Support\Transport\ExceptionStatus;
use App\Support\Sql\SqlDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DB-010 `trip_exceptions` — something went wrong, and somebody owns it.
 * SNG-TRN-013.
 *
 * ── THIS TABLE HAS EXISTED SINCE 2026-09-10 WITH NO MODEL ────────────────
 * SNG-TRN-013 shipped the schema, the severity and category vocabularies and
 * the SLA state machine, then stopped: the exception LIFECYCLE was blocked on
 * D-29 (six different state lists across the documents, Step 11 contradicting
 * itself) and D-30 (`waived`, required by FRS TRP-P0-012 and present in no
 * enum). Both were ruled on 2026-09-18 and neither needed a new decision —
 * D-29 dissolved under the standing Step 9 / Step 11 rule already in
 * TEAM-CONTRACTS, and D-30 is deferred the way BR-P0-017's waiver is.
 *
 * It is step 9 of MS-001 §14's fourteen, and was the only one of that walk's
 * eight gaps that belonged to P1.
 *
 * ── NO SOFT DELETE, DELIBERATELY ────────────────────────────────────────
 * Every other Transport record soft-deletes. This one does not, and STOS-DB §20
 * is why: "Do not delete critical financial, compliance or operational
 * records." OPS §154 is blunter — an exception "must never disappear". An
 * exception that can be removed is a register nobody can trust, because the
 * interesting question is always the one somebody wanted gone.
 *
 * An exception raised in error is RESOLVED with a note saying so.
 *
 * ── `status` IS NOT FILLABLE ────────────────────────────────────────────
 * It moves through TripExceptionService, which checks the edge and audits it.
 * Same reason trip status is not fillable: a state that any caller can set is
 * not a state machine.
 *
 * @property int         $tenant_id
 * @property string      $exception_number
 * @property int|null    $trip_id
 * @property string      $category
 * @property string      $severity
 * @property string      $status
 */
class TripException extends Model
{
    use HasFactory, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_exceptions';

    protected $fillable = [
        'tenant_id', 'exception_number', 'trip_id', 'vehicle_id', 'driver_id',
        'category', 'severity', 'source', 'cause', 'owner_id',
        'raised_by', 'raised_at', 'due_at', 'sla_minutes',
        'created_by', 'updated_by',
        // status, acknowledged_* and resolved_* are NOT here. They are the
        // result of an act the service records and audits, never a field a
        // caller sets.
    ];

    protected $casts = [
        'trip_id'         => 'integer',
        'raised_at'       => 'datetime',
        'due_at'          => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at'     => 'datetime',
        'sla_minutes'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (TripException $e) {
            if ($e->isDirty('exception_number')) {
                throw new \RuntimeException(
                    'exception_number is immutable once issued. Correcting one is an admin action, not an update.'
                );
            }
        });

        // OPS §154 — an exception must never disappear.
        static::deleting(function () {
            throw new \RuntimeException(
                'Transport exceptions cannot be deleted (STOS-DB §20, OPS §154). Resolve it with a note instead.'
            );
        });
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_id');
    }

    /* ── Scopes ─────────────────────────────────────────────────────── */

    public function scopeForTrip(Builder $q, int $tripId): Builder
    {
        return $q->where('trip_id', $tripId);
    }

    /** Still needing somebody's attention — ExceptionStatus::ACTIVE. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ExceptionStatus::ACTIVE);
    }

    public function scopeCritical(Builder $q): Builder
    {
        return $q->where('severity', ExceptionSeverity::CRITICAL);
    }

    /** Past its due time and not yet finished. IDX-008's query. */
    public function scopeOverdue(Builder $q, ?string $asOf = null): Builder
    {
        return $q->whereIn('status', ExceptionStatus::ACTIVE)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $asOf ?: now());
    }

    /* ── Derived ────────────────────────────────────────────────────── */

    public function statusLabel(): string
    {
        return ExceptionStatus::label($this->status);
    }

    public function canTransitionTo(string $status): bool
    {
        return ExceptionStatus::canTransition($this->status, $status);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ExceptionStatus::ACTIVE, true);
    }

    /**
     * Where the SLA clock stands — Q5's wall-clock reading, and only that.
     *
     * BRWM §58 wants seven elements for an SLA, one of them a business
     * calendar; §59 wants that calendar to model working hours, weekends,
     * holidays, branch and customer. None has an entity (D-34). So this is
     * elapsed wall-clock, which is the part that can be computed honestly, and
     * the screen says so rather than implying a working-hours calculation.
     */
    public function slaState(?string $asOf = null): string
    {
        if (! $this->isOpen()) {
            return ExceptionSlaState::STOPPED;
        }

        if (! $this->due_at) {
            return ExceptionSlaState::NONE;
        }

        $now = $asOf ? \Illuminate\Support\Carbon::parse($asOf) : now();

        if ($now->greaterThan($this->due_at)) {
            return ExceptionSlaState::OVERDUE;
        }

        // "At risk" once three quarters of the window has gone. Derived from
        // the window itself rather than a fixed number of minutes, so a
        // fifteen-minute critical and a three-day low both warn proportionally.
        if ($this->sla_minutes && $this->raised_at) {
            $elapsed = $this->raised_at->diffInMinutes($now);
            if ($elapsed >= $this->sla_minutes * 0.75) {
                return ExceptionSlaState::AT_RISK;
            }
        }

        return ExceptionSlaState::ON_TRACK;
    }

    public function minutesRemaining(?string $asOf = null): ?int
    {
        if (! $this->due_at || ! $this->isOpen()) {
            return null;
        }

        return (int) ($asOf ? \Illuminate\Support\Carbon::parse($asOf) : now())
            ->diffInMinutes($this->due_at, false);
    }

    /** BR-P0-001 — unique within tenant and year. */
    public static function nextLocalNumber(int $tenantId): string
    {
        $prefix = 'EXC-'.date('Y').'-';

        $highest = static::query()
            ->where('tenant_id', $tenantId)
            ->where('exception_number', 'like', $prefix.'%')
            ->selectRaw('MAX('.SqlDate::intCast('SUBSTR(exception_number, ?)').') AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) (((int) $highest) + 1), 6, '0', STR_PAD_LEFT);
    }
}
