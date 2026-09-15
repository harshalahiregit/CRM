<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attachment of a container to a consignment — STOS-CTD §8's "controlled
 * relationship", as a record rather than a foreign key.
 *
 * §8: "A consignment may contain one container; contain multiple containers;
 * have other cargo references." Many-to-many, and §7 adds the time dimension:
 * "reuse across different trips is allowed HISTORICALLY but not
 * SIMULTANEOUSLY".
 *
 * ── THIS ROW IS THE HISTORY, SO IT IS NEVER DELETED ─────────────────────
 * Detaching sets `detached_at`. There are no soft deletes and no hard delete
 * path: an attachment that happened is a fact about where a box has been, and
 * §7 requires that record to survive. `detached_at` already expresses "not
 * current", which is the only thing a delete would have communicated.
 *
 * ── THE SIMULTANEITY RULE IS THE DATABASE'S, NOT THIS CLASS'S ───────────
 * `active_container_key` is a STORED generated column — container_id while
 * attached, NULL once detached — under UNIQUE(tenant_id, active_container_key).
 * It is not fillable, not castable and not settable: the database computes it.
 *
 * A service-level check could not do this. Two concurrent attach requests both
 * read "nothing active", both pass, and both insert. The constraint was probed
 * against MySQL 8.0.46 and sqlite 3.45.1 before the migration was written and
 * behaves identically on both — see ContainerAttachmentGuaranteeTest and
 * ContainerAttachmentMysqlTest, and D-51 for why both exist.
 *
 * ── NO seal_number ──────────────────────────────────────────────────────
 * It was in an earlier schema proposal of mine. STOS-CMP §76/§77 specify seal
 * control and make a mismatch a Security/Quality Incident — Person 3's under
 * TM-001 §3. Requested from them, not built here.
 *
 * @property int         $tenant_id
 * @property int         $consignment_id
 * @property int         $container_id
 * @property string|null $detached_at
 */
class ConsignmentContainer extends Model
{
    use HasFactory, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_consignment_containers';

    protected $fillable = [
        'tenant_id', 'consignment_id', 'container_id',
        'attached_at', 'attached_by', 'detached_at', 'detached_by',
        'created_by', 'updated_by',
        // active_container_key is absent on purpose — the DATABASE generates it.
        // Listing it would let a caller set a value the engine then overwrites,
        // which is a bug that reads as working code.
    ];

    protected $casts = [
        'consignment_id' => 'integer',
        'container_id'   => 'integer',
        'attached_at'    => 'datetime',
        'detached_at'    => 'datetime',
    ];

    /* ── Relations ──────────────────────────────────────────────────── */

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(TransportConsignment::class, 'consignment_id');
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(TransportContainer::class, 'container_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    /** The attachments that are live. At most one per container, per tenant. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('detached_at');
    }

    public function scopeForConsignment(Builder $query, int $consignmentId): Builder
    {
        return $query->where('consignment_id', $consignmentId);
    }

    public function scopeForContainer(Builder $query, int $containerId): Builder
    {
        return $query->where('container_id', $containerId);
    }

    /* ── Derived state ──────────────────────────────────────────────── */

    public function isActive(): bool
    {
        return $this->detached_at === null;
    }
}
