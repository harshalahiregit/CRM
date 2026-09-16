<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverComplianceStatus;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\TransportDocumentEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The Driver master — SNG-TRN-004, table DB-005.
 *
 * ── PLACEHOLDER. THIS FILE IS SCHEDULED FOR DELETION. ────────────────────
 * Held by Person 1 only so the Trip and Order demo has something to allocate.
 * The domain is Person 2's under TM-001 §8; when their Fleet module merges,
 * this is REMOVED, not merged with. Do not add features or refactor it — see
 * TEAM-CONTRACTS.md §1a for the file list and the seam that survives.
 *
 * Step 9 places the Driver at the "Transport/HR interface": HR owns employment,
 * Transport owns operational assignment and compliance context (STOS-MAM §29).
 * This model is Transport's half. It links to an HR employee where one exists and
 * never reaches into HR for anything.
 *
 * Like TransportVehicle, it holds facts and answers no eligibility question of its
 * own. BRW-028 ("only drivers with AVAILABLE status may be recommended") and
 * BR-P0-004 ("driver unavailable or critical document expired blocks assignment")
 * are enforced by DriverEligibilityService in SNG-TRN-009 step 5, which composes
 * the two state columns with licence validity and required documents.
 *
 * @property int         $tenant_id
 * @property string      $name
 * @property string|null $licence_number
 * @property string|null $licence_normalized
 * @property string      $status
 * @property string      $availability
 */
class TransportDriver extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_drivers';

    protected $fillable = [
        'tenant_id', 'driver_code', 'name', 'mobile', 'alternate_mobile',
        'hr_employee_id', 'supplier_id', 'licence_number', 'licence_class',
        'licence_valid_from', 'licence_valid_until', 'created_by', 'updated_by',
        // `status` and `availability` are NOT mass-assignable — both move only
        // through their declared transitions, the same protection transport_vehicles
        // gives its status. An allocation that could flip a driver to AVAILABLE by
        // posting a field would defeat the double-booking control outright.
        // `licence_normalized` is derived below; a caller setting it would break
        // the uniqueness guarantee it exists to provide.
    ];

    protected $casts = [
        'licence_valid_from'  => 'date',
        'licence_valid_until' => 'date',
        'hr_employee_id'      => 'integer',
        'supplier_id'         => 'integer',
    ];

    /** Mirrors the column defaults so a new instance reads the same as its row. */
    protected $attributes = [
        'status'       => DriverStatus::INITIAL,
        'availability' => DriverAvailability::INITIAL,
    ];

    protected static function booted(): void
    {
        static::saving(function (TransportDriver $driver) {
            // Derived on every write so it can never drift from the number it
            // normalizes. Null stays null — a driver may be onboarded before their
            // licence is collected, and an empty string would collide with every
            // other licence-less driver under the unique index.
            $driver->licence_normalized = filled($driver->licence_number)
                ? self::normalizeLicence((string) $driver->licence_number)
                : null;
        });
    }

    /**
     * Indian driving licences are written many ways — "RJ14 20110012345",
     * "RJ-14-2011-0012345", "rj1420110012345" are one licence.
     *
     * Same treatment as vehicle registrations, and deliberately not a format
     * validator: licence formats vary by issuing state and by decade, and a regex
     * strict enough to be useful would reject legitimate drivers.
     */
    public static function normalizeLicence(string $licence): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($licence))) ?? '';
    }

    /* ── Relations ──────────────────────────────────────────────────── */

    /**
     * This driver's documents in the shared DB-019 table — medical/fitness,
     * training, ID, and the licence SCAN. The licence DATES live on this model.
     *
     * No tenant clause, for the reason spelled out in TransportVehicle::documents():
     * Laravel builds eager-loaded relations from a fresh instance, so a
     * $this->tenant_id constraint would silently match nothing under
     * with('documents'). entity_id is a global autoincrement and the driver was
     * reached through forTenant(), so it is safe without one. Queries that START
     * from documents must always call forTenant().
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TransportDocument::class, 'entity_id')
            ->where('transport_documents.entity_type', TransportDocumentEntity::DRIVER);
    }

    /* ── Licence validity (the authoritative source, per the owner's ruling) ── */

    public function hasLicence(): bool
    {
        return filled($this->licence_number);
    }

    /** A NULL expiry never expires — same convention as TransportDocument. */
    public function licenceIsExpired(): bool
    {
        return $this->licence_valid_until !== null && $this->licence_valid_until->isPast();
    }

    public function licenceIsNotYetValid(): bool
    {
        return $this->licence_valid_from !== null && $this->licence_valid_from->isFuture();
    }

    /** CMP §22 / BR-P0-004: the check allocation actually runs. */
    public function licenceIsValid(): bool
    {
        return $this->hasLicence() && ! $this->licenceIsExpired() && ! $this->licenceIsNotYetValid();
    }

    /** Negative once lapsed, null when there is no expiry or no licence. */
    public function daysUntilLicenceExpiry(): ?int
    {
        if ($this->licence_valid_until === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->licence_valid_until->startOfDay(), false);
    }

    public function getLicenceIsExpiredAttribute(): bool
    {
        return $this->licenceIsExpired();
    }

    /* ── Compliance (CMP §23) — derived, never stored ────────────────── */

    /**
     * This driver's compliance status right now.
     *
     * Derived rather than stored, for the same reason the vehicle equivalent is:
     * a column saying COMPLIANT is wrong the morning after a certificate lapses
     * unless something swept overnight, and nothing sweeps.
     *
     * Order of precedence, and why:
     *   1. BLOCKED       an administrative bar outranks paperwork. A blocked
     *                    driver is not "expiring", they are simply barred.
     *   2. NON_COMPLIANT something has actually lapsed or is missing. This is
     *                    the state BR-P0-004 refuses assignment on.
     *   3. EXPIRING      valid today, lapses inside the warning window. A
     *                    warning, not a block — the driver can still be given a
     *                    trip, which is the entire point of warning early.
     *   4. COMPLIANT     nothing on file has lapsed.
     *
     * $windowDays defaults to the one value CMP §18 and FLEET §13 share. Step 5
     * passes the tenant's configured window instead.
     *
     * NOT YET CHECKED: whether every REQUIRED document is present. CMP §24 makes
     * the required set configurable and it has no home until step 5, so a driver
     * with a valid licence and no other documents reads COMPLIANT today. Stated
     * rather than hidden.
     */
    public function complianceStatus(?int $windowDays = null): string
    {
        $windowDays ??= DriverComplianceStatus::DEFAULT_EXPIRING_WINDOW_DAYS;

        if ($this->status === DriverStatus::BLOCKED) {
            return DriverComplianceStatus::BLOCKED;
        }

        // The licence is the one document this model owns outright.
        if (! $this->licenceIsValid()) {
            return DriverComplianceStatus::NON_COMPLIANT;
        }

        // Documents are loaded through the relation, which is already scoped to
        // this driver; `active` excludes superseded versions so a renewed
        // certificate's predecessor cannot report the driver as lapsed.
        $documents = $this->relationLoaded('documents')
            ? $this->documents->where('status', TransportDocument::STATUS_ACTIVE)
            : $this->documents()->active()->get();

        foreach ($documents as $document) {
            if (! $document->isCurrentlyValid()) {
                return DriverComplianceStatus::NON_COMPLIANT;
            }
        }

        $licenceDays = $this->daysUntilLicenceExpiry();
        if ($licenceDays !== null && $licenceDays <= $windowDays) {
            return DriverComplianceStatus::EXPIRING;
        }

        foreach ($documents as $document) {
            $days = $document->daysUntilExpiry();
            if ($days !== null && $days <= $windowDays) {
                return DriverComplianceStatus::EXPIRING;
            }
        }

        return DriverComplianceStatus::COMPLIANT;
    }

    public function complianceStatusLabel(?int $windowDays = null): string
    {
        return DriverComplianceStatus::label($this->complianceStatus($windowDays));
    }

    /** BR-P0-004 — the half of the allocation gate this model can answer alone. */
    public function complianceBlocksAssignment(?int $windowDays = null): bool
    {
        return DriverComplianceStatus::blocksAssignment($this->complianceStatus($windowDays));
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    /**
     * The first filter behind PLN-003 "only eligible drivers suggested".
     *
     * Both axes, because either one alone is wrong: an INACTIVE driver whose
     * availability was never changed still reads as 'available', and an ACTIVE
     * driver on leave is not free. This is a candidate filter only — licence and
     * document checks are DriverEligibilityService's job in step 5.
     */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->whereIn('status', DriverStatus::ALLOCATABLE)
            ->whereIn('availability', DriverAvailability::ALLOCATABLE);
    }

    public function scopeWithAvailability(Builder $query, string $availability): Builder
    {
        return $query->where('availability', $availability);
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Drivers already spoken for — used by SNG-TRN-009 to spot double-booking. */
    public function scopeEngaged(Builder $query): Builder
    {
        return $query->whereIn('availability', DriverAvailability::ENGAGED);
    }

    public function scopeLicenceExpired(Builder $query): Builder
    {
        return $query->whereNotNull('licence_valid_until')
            ->whereDate('licence_valid_until', '<', now());
    }

    /** Search however the user types it — STOS-DB §152. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! filled($term)) {
            return $query;
        }

        $normalized = self::normalizeLicence($term);

        return $query->where(function (Builder $q) use ($term, $normalized) {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('driver_code', 'like', '%'.$term.'%')
                ->orWhere('mobile', 'like', '%'.$term.'%')
                ->orWhere('licence_normalized', 'like', '%'.$normalized.'%');
        });
    }

    /* ── State ──────────────────────────────────────────────────────── */

    public function canTransitionStatusTo(string $status): bool
    {
        return DriverStatus::canTransition($this->status, $status);
    }

    public function canTransitionAvailabilityTo(string $availability): bool
    {
        return DriverAvailability::canTransition($this->availability, $availability);
    }

    public function statusLabel(): string
    {
        return DriverStatus::label($this->status);
    }

    public function availabilityLabel(): string
    {
        return DriverAvailability::label($this->availability);
    }

    /** A human reference for audit entries and error messages. */
    public function displayName(): string
    {
        return $this->name.($this->driver_code ? ' ('.$this->driver_code.')' : '');
    }
}
