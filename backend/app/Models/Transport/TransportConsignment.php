<?php

namespace App\Models\Transport;

use App\Models\Customer\Client;
use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Sql\SqlDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The commercial shipment — STOS-CTD §8's "consignment", as distinct from the
 * physical container that carries it.
 *
 * Exists by explicit architecture approval (D-39): Step 9's canonical
 * Domain_Model has no Consignment, and its header requires approval by name
 * before a new business object is created. Granted 2026-09-12.
 *
 * BelongsToTenant from the first commit, as every Transport model has been.
 * RecordsTransportAudit plugs it into the SNG-TRN-027 trail; auditing stays an
 * explicit act in the service layer, where the actor and the reason are known.
 *
 * ── THERE IS NO `status`, AND NO ACCESSOR PRETENDING THERE IS ─────────────
 * STOS-CTD §11: "Status must come from the lifecycle engine." That engine does
 * not exist (D-44) and its twenty values span at least five lifecycles across
 * two owners. So there is no status column here, and this model deliberately
 * offers no `status()` helper either — a method that guessed would be worse
 * than the absence, because callers would believe it.
 *
 * ── STOS-CTD §8's RELATIONSHIP ───────────────────────────────────────────
 * `containerAttachments()` is a HasMany, not a BelongsToMany: §8 allows a
 * consignment to carry one container, several, or none at all ("other cargo
 * references" — break-bulk), and §7 requires each attachment's history to
 * survive its detachment. A pivot would hide `attached_at` / `detached_at`,
 * which are the record rather than metadata about it.
 *
 * @property int         $tenant_id
 * @property string      $consignment_number
 * @property int         $order_id
 * @property int|null    $customer_id
 * @property string|null $customer_reference
 */
class TransportConsignment extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_consignments';

    protected $fillable = [
        'tenant_id', 'consignment_number', 'order_id', 'customer_id',
        'customer_reference', 'cargo_description', 'service_type',
        'special_handling', 'package_count', 'gross_weight_kg', 'volume_cbm',
        'created_by', 'updated_by',
        // No `status` — see the class docblock and D-44.
    ];

    protected $casts = [
        'order_id'        => 'integer',
        'customer_id'     => 'integer',
        'package_count'   => 'integer',
        // decimal:3 because part-tonne and part-cbm are ordinary in this trade.
        'gross_weight_kg' => 'decimal:3',
        'volume_cbm'      => 'decimal:3',
    ];

    /* ── Relations ──────────────────────────────────────────────────── */

    /** ORD-004 / CTD-003. The link direction is order → consignment. */
    public function order(): BelongsTo
    {
        return $this->belongsTo(TransportOrder::class, 'order_id');
    }

    /** CTD-002. The Customer module owns this entity; Transport consumes it. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    /**
     * STOS-CTD §8 — every container attachment, current and historical.
     *
     * Newest first: the usual question is "what is on this consignment now?".
     */
    public function containerAttachments(): HasMany
    {
        return $this->hasMany(ConsignmentContainer::class, 'consignment_id')
            ->orderByDesc('attached_at');
    }

    /**
     * STOS-CTD §6 "Trip ID". hasMany, not hasOne: §8 allows one consignment to
     * be carried across more than one movement.
     */
    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class, 'consignment_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForOrder(Builder $query, int $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * STOS-CTD §4 makes Customer Reference a search path in its own right.
     *
     * Exact match, not LIKE: a customer reference is an identifier the customer
     * gave us, and a partial match on it would return someone else's shipment.
     * Free-text search across several fields is Block 5's job, not a scope's.
     */
    public function scopeWithCustomerReference(Builder $query, string $reference): Builder
    {
        return $query->where('customer_reference', $reference);
    }

    /* ── Numbering fallback ─────────────────────────────────────────── */

    /**
     * CNM-YYYY-NNNNNN from the highest number already issued this year.
     *
     * Used only when the tenant has not switched the central Document Numbering
     * Engine on for `transport_consignment`. Same shape and the same reasoning
     * as TransportOrder::nextLocalNumber and TransportTrip::nextLocalNumber:
     *
     *   MAX(number), not COUNT(*) — counting rows means deleting a consignment
     *   makes the next one reuse a live number, and two concurrent creates both
     *   read the same count. withTrashed() so a soft-deleted row keeps its
     *   number out of circulation.
     *
     *   SqlDate::intCast because MySQL spells the cast SIGNED and rejects
     *   INTEGER outright — the defect that once made employee, proposal and
     *   estimate creation impossible on MySQL while passing every SQLite test.
     *
     * Uniqueness is guaranteed by the unique index, not by this method: two
     * simultaneous creates can still read the same MAX, and the caller retries
     * on the violation.
     */
    public static function nextLocalNumber(int $tenantId): string
    {
        $prefix = 'CNM-'.date('Y').'-';

        $highest = static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('consignment_number', 'like', $prefix.'%')
            ->selectRaw('MAX('.SqlDate::intCast('SUBSTR(consignment_number, ?)').') AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) (((int) $highest) + 1), 6, '0', STR_PAD_LEFT);
    }

    /* ── Derived state ──────────────────────────────────────────────── */

    /**
     * Is anything actually declared about the cargo?
     *
     * STOS-CTD §8 allows "other cargo references" — a consignment with no
     * container is break-bulk, not an error. But one with no container AND no
     * cargo description describes nothing at all, and a list page should be
     * able to say so without inventing a status (D-44).
     */
    public function hasCargoDetail(): bool
    {
        return filled($this->cargo_description)
            || $this->package_count !== null
            || $this->gross_weight_kg !== null
            || $this->volume_cbm !== null;
    }
}
