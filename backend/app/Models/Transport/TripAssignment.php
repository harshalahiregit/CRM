<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\AssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vehicle/driver allocation against one trip — DB-003.
 *
 * STOS-DB §46: "This is a critical business-control entity." The whole point of
 * it existing as a table rather than as two columns on the trip is history —
 * SEC §103 lists "vehicle assignment history" and "driver allocation history"
 * among its fraud controls.
 *
 * ── DELIBERATELY NO SoftDeletes ───────────────────────────────────────────
 * Unlike every other transport model. Step 5's mandatory conventions: "Soft
 * delete — only for masters where legal/business safe. Transactional records are
 * reversed/cancelled, not destroyed." An assignment ends by being RELEASED. If
 * it could be deleted, the history §46 exists to preserve would be optional.
 *
 * ── THE GENERATED COLUMNS ARE NOT YOURS TO SET ────────────────────────────
 * active_vehicle_id / active_driver_id / active_trip_id are MySQL STORED
 * generated columns that mirror their source column only while the row is
 * active. They exist to carry the unique indexes that enforce BR-P0-003 in the
 * database. They are absent from $fillable and $guarded is irrelevant to them —
 * MySQL rejects any attempt to write one.
 *
 * @property int         $tenant_id
 * @property int         $trip_id
 * @property int|null    $vehicle_id
 * @property int|null    $driver_id
 * @property string      $status
 * @property bool        $allocation_override
 */
class TripAssignment extends Model
{
    use HasFactory, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_assignments';

    protected $fillable = [
        'tenant_id', 'trip_id', 'vehicle_id', 'driver_id',
        'assigned_at', 'reason', 'allocation_type', 'approved_by',
        'previous_assignment_id', 'created_by', 'updated_by',
        // `status` and `released_at` are NOT mass-assignable: an assignment is
        // released through the service so the act is audited, never by a caller
        // setting a field. `allocation_override` and `override_reason` are
        // likewise withheld — PLN-007 is P1 and nothing may set an override
        // before the ticket that authorises and audits one exists.
    ];

    protected $casts = [
        'assigned_at'         => 'datetime',
        'released_at'         => 'datetime',
        'allocation_override' => 'boolean',
        'vehicle_id'          => 'integer',
        'driver_id'           => 'integer',
        'trip_id'             => 'integer',
    ];

    protected $attributes = [
        'status'              => AssignmentStatus::INITIAL,
        'allocation_override' => false,
    ];

    /* ── Relations ──────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(TransportDriver::class, 'driver_id');
    }

    /** OPS §123 — the old→new link a transfer records. */
    public function previousAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_assignment_id');
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    /** The states in which a vehicle and driver are occupied (BR-P0-003). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AssignmentStatus::ACTIVE_STATES);
    }

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    public function scopeForVehicle(Builder $query, int $vehicleId): Builder
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    /* ── State ──────────────────────────────────────────────────────── */

    public function isActive(): bool
    {
        return AssignmentStatus::isActive($this->status);
    }

    public function hasVehicle(): bool
    {
        return $this->vehicle_id !== null;
    }

    public function hasDriver(): bool
    {
        return $this->driver_id !== null;
    }

    /**
     * Both resources present.
     *
     * CTR-007 and CTR-008 both mark their field required on API-004, so a trip is
     * only fully crewed once this is true. It is NOT a precondition for the row
     * existing — TRP-P0-004's trigger is "Vehicle allocated", so a vehicle-only
     * assignment is a legitimate intermediate state.
     */
    public function isComplete(): bool
    {
        return $this->hasVehicle() && $this->hasDriver();
    }

    public function canTransitionTo(string $status): bool
    {
        return AssignmentStatus::canTransition($this->status, $status);
    }

    public function statusLabel(): string
    {
        return AssignmentStatus::label($this->status);
    }
}
