<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A compliance or evidence document — DB-019 `transport_documents`.
 *
 * Serves vehicles (SNG-TRN-003) and drivers (SNG-TRN-004) from one table, keyed
 * by entity_type + entity_id, exactly as IDX-010 specifies.
 *
 * ── THE VALIDITY CONTRACT ─────────────────────────────────────────────────
 * isPassing / isExpired / isCurrentlyValid mirror TpvWorkerMedical, which the TPV
 * module already uses for the same job (does this certificate still authorise
 * anything?). Copying the existing idiom rather than inventing a fourth one means
 * a reviewer who knows TPV reads this correctly on sight.
 *
 * The one judgement here is what a NULL valid_until means. It is treated as
 * "does not expire", not as "expired": an RC book has no expiry, and defaulting
 * an unset date to invalid would block a fleet the day this ships. The compliance
 * gate's job is to fail on a date that has PASSED, not on a date nobody entered —
 * absence of a required document is a different check, and it belongs to the
 * configurable required-set in SNG-TRN-009 step 5.
 *
 * @property int         $tenant_id
 * @property string      $entity_type
 * @property int         $entity_id
 * @property string      $document_type
 * @property int         $version
 */
class TransportDocument extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_documents';

    public const STATUS_ACTIVE     = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'tenant_id', 'entity_type', 'entity_id', 'document_type', 'version',
        'document_number', 'issued_on', 'valid_from', 'valid_until',
        'file_path', 'file_name', 'file_hash', 'source', 'status', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'issued_on'   => 'date',
        'valid_from'  => 'date',
        'valid_until' => 'date',
        'version'     => 'integer',
        'entity_id'   => 'integer',
    ];

    /** Mirrors the column defaults so a new instance reads the same as its row. */
    protected $attributes = [
        'status'  => self::STATUS_ACTIVE,
        'version' => 1,
    ];

    /* ── Validity ───────────────────────────────────────────────────── */

    /** Superseded rows are history, not evidence. */
    public function isPassing(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Its currency window has closed. A NULL valid_until never expires. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /** Not yet in force — issued but dated to start later. */
    public function isNotYetValid(): bool
    {
        return $this->valid_from !== null && $this->valid_from->isFuture();
    }

    /** The real gate: this document authorises something right now. */
    public function isCurrentlyValid(): bool
    {
        return $this->isPassing() && ! $this->isExpired() && ! $this->isNotYetValid();
    }

    /**
     * Days until expiry — negative once lapsed, null when it never expires.
     *
     * Feeds FLEET §13's configurable warning windows (60/30/15/7) without this
     * model deciding what those windows are; the thresholds are per-organization
     * and belong in policy.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->valid_until === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false);
    }

    /** Serialised so a list can badge a lapsed document without recomputing. */
    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    public function scopeForEntity(Builder $query, string $entityType, int $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    public function scopeForVehicle(Builder $query, int $vehicleId): Builder
    {
        return $query->forEntity(TransportDocumentEntity::VEHICLE, $vehicleId);
    }

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->forEntity(TransportDocumentEntity::DRIVER, $driverId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('document_type', $type);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('valid_until')->whereDate('valid_until', '<', now());
    }

    /* ── Versioning (STOS-DOC §26) ──────────────────────────────────── */

    /**
     * The next version number for this entity + document type.
     *
     * Tenant-scoped explicitly. The unique index is what actually guarantees
     * correctness under a race; this only picks the next number politely.
     */
    public static function nextVersion(int $tenantId, string $entityType, int $entityId, string $documentType): int
    {
        return (int) static::withTrashed()
            ->forTenant($tenantId)
            ->forEntity($entityType, $entityId)
            ->ofType($documentType)
            ->max('version') + 1;
    }

    public function typeLabel(): string
    {
        return TransportDocumentType::label($this->document_type);
    }
}
