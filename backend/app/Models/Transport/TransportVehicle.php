<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\VehicleOwnership;
use App\Support\Transport\VehicleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Vehicle master — SNG-TRN-003, table DB-004.
 *
 * ── PLACEHOLDER. THIS FILE IS SCHEDULED FOR DELETION. ────────────────────
 * Held by Person 1 only so the Trip and Order demo has something to allocate.
 * The domain is Person 2's under TM-001 §8; when their Fleet module merges,
 * this is REMOVED, not merged with. Do not add features or refactor it — see
 * TEAM-CONTRACTS.md §1a for the file list and the seam that survives.
 *
 * Fleet's half of the OPS↔FLEET contract (STOS-FLEET §4):
 *   OPS asks   "Which vehicle can execute this trip?"
 *   FLEET says "Which vehicles are eligible, available, compliant, maintained
 *               and operationally fit?"
 * This model holds the facts. It deliberately answers NONE of that question
 * itself — eligibility is VehicleEligibilityService (SNG-TRN-009 step 5), because
 * FLEET §16 draws a distinction a model cannot honour on its own:
 *   Available = the asset is free.  Eligible = it meets requirements.
 *   Ready     = it has passed readiness checks (pre-trip, SNG-TRN-010).
 * A vehicle whose `status` is 'available' may still be ineligible for a specific
 * trip. Anything that treats this column as permission to dispatch is a bug.
 *
 * @property int         $tenant_id
 * @property string      $registration_number
 * @property string      $registration_normalized
 * @property string      $status
 * @property string      $ownership_type
 * @property string|null $capacity_tonnes
 */
class TransportVehicle extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_vehicles';

    protected $fillable = [
        'tenant_id', 'registration_number', 'fleet_number', 'chassis_number',
        'engine_number', 'gps_device_id', 'vehicle_type', 'manufacturer', 'model',
        'variant', 'manufacturing_year', 'purchase_date', 'fuel_type', 'branch',
        'capacity_tonnes', 'ownership_type', 'created_by', 'updated_by',
        // `status` is NOT mass-assignable. FLEET §8: "Vehicle status must be driven
        // by business events. Users should not freely type 'Available' without
        // satisfying required conditions." It moves through transitionTo() only.
        // `registration_normalized` is NOT mass-assignable either — it is derived
        // below, and letting a caller set it would break the uniqueness guarantee
        // it exists to provide.
    ];

    protected $casts = [
        'purchase_date'      => 'date',
        'manufacturing_year' => 'integer',
        'capacity_tonnes'    => 'decimal:3',
    ];

    /**
     * In-memory defaults mirroring the column defaults.
     *
     * The migration defaults status to 'new' and ownership to 'owned', but a
     * database default only fires on INSERT — Eloquent knows nothing about it, so
     * a just-created model carries null for both until it is refreshed. Since
     * `status` is deliberately not fillable, that null is unavoidable without
     * this. Declaring the same defaults here keeps the object and the row saying
     * the same thing from the first moment the model exists.
     */
    protected $attributes = [
        'status'         => VehicleStatus::INITIAL,
        'ownership_type' => VehicleOwnership::DEFAULT,
    ];

    protected static function booted(): void
    {
        // Derive the normalized form on every write, so it can never drift from
        // the number it normalizes. FLEET §9 requires registration to be "unique
        // within organization; searchable; normalized" — this is what makes the
        // unique index mean what §9 intends rather than merely what was typed.
        static::saving(function (TransportVehicle $vehicle) {
            $vehicle->registration_normalized = self::normalizeRegistration(
                (string) $vehicle->registration_number
            );
        });
    }

    /**
     * "RJ 14 XX 1234", "rj-14-xx-1234" and "RJ14XX1234" are one vehicle.
     *
     * Uppercase, then drop everything that is not a letter or digit. Deliberately
     * not a format validator: Indian registration formats vary by state and era
     * (BH-series, older two-letter districts, temporary numbers), and a regex
     * tight enough to be useful would reject legitimate vehicles.
     */
    public static function normalizeRegistration(string $registration): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($registration))) ?? '';
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    /**
     * This vehicle's documents in the shared DB-019 table.
     *
     * NO tenant clause here, deliberately, and it is worth saying why given how
     * strict the rest of this module is about tenant scoping.
     *
     * A constraint like where('tenant_id', $this->tenant_id) reads as belt-and-
     * braces but is actively broken: Laravel builds an eager-loaded relation from
     * a NEW model instance, so $this->tenant_id is null during with('documents')
     * and the clause silently matches nothing. A tenant filter that returns an
     * empty set on the happy path is worse than no filter at all.
     *
     * It is safe without one. transport_vehicles.id is a global autoincrement, so
     * entity_id identifies exactly one vehicle across the whole table, and that
     * vehicle was itself reached through a forTenant() query. Cross-tenant reach
     * would require loading another tenant's vehicle first, which is the thing
     * every repository in this module already prevents.
     *
     * Queries that START from documents are a different matter and must always
     * call forTenant() — see TransportDocument's scopes.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TransportDocument::class, 'entity_id')
            ->where('transport_documents.entity_type', TransportDocumentEntity::VEHICLE);
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    /** Statuses that make a vehicle a candidate at all (PLN-002, first filter). */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->whereIn('status', VehicleStatus::ALLOCATABLE);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Registration search that works however the user types it (FLEET §9). */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! filled($term)) {
            return $query;
        }

        $normalized = self::normalizeRegistration($term);

        return $query->where(function (Builder $q) use ($term, $normalized) {
            $q->where('registration_normalized', 'like', '%'.$normalized.'%')
                ->orWhere('fleet_number', 'like', '%'.$term.'%')
                ->orWhere('chassis_number', 'like', '%'.$term.'%');
        });
    }

    /* ── State ──────────────────────────────────────────────────────── */

    public function canTransitionTo(string $status): bool
    {
        return VehicleStatus::canTransition($this->status, $status);
    }

    public function statusLabel(): string
    {
        return VehicleStatus::label($this->status);
    }

    public function ownershipLabel(): string
    {
        return VehicleOwnership::label($this->ownership_type);
    }

    /** A human reference for audit entries and error messages. */
    public function displayName(): string
    {
        return $this->registration_number
            .($this->fleet_number ? ' ('.$this->fleet_number.')' : '');
    }
}
