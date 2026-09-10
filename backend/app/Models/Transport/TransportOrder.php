<?php

namespace App\Models\Transport;

use App\Models\Customer\Client;
use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\OrderPriority;
use App\Support\Sql\SqlDate;
use App\Support\Transport\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's approved requirement to perform transportation (DB-001).
 *
 * BelongsToTenant from the first commit, as every Transport model does — the
 * module has no legacy to carry, so it never inherits HR's split where 85 of
 * 131 models lack the trait and must filter by hand.
 *
 * RecordsTransportAudit plugs it into the SNG-TRN-027 trail. Auditing stays an
 * explicit act in the service layer, where the actor and the reason are known.
 *
 * @property int    $tenant_id
 * @property string $order_number
 * @property string $order_status
 * @property string $priority
 */
class TransportOrder extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'transport_orders';

    protected $fillable = [
        'tenant_id', 'order_number', 'customer_id', 'customer_reference',
        'pickup_location', 'delivery_location', 'required_at', 'service_type',
        'order_status', 'priority', 'source', 'rate_reference', 'route',
        'special_requirements', 'billing_requirements', 'required_capacity_tonnes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        // PLN-001 — the order side of the capacity comparison. Nullable: an
        // order that states no requirement is not an order requiring zero.
        'required_capacity_tonnes' => 'decimal:3',
        'pickup_location'   => 'array',
        'delivery_location' => 'array',
        'required_at'       => 'datetime',
    ];

    /* ── Relations ──────────────────────────────────────────────────── */

    /** The Customer module owns this entity; Transport only consumes it. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class, 'order_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('order_status', $status);
    }

    public function scopeTripEligible(Builder $query): Builder
    {
        return $query->where('order_status', OrderStatus::APPROVED);
    }

    /* ── Numbering fallback ─────────────────────────────────────────── */

    /**
     * TO-YYYY-NNNNNN from the highest number already issued this year.
     *
     * Used only when the tenant has not switched the central engine on for
     * `transport_order`; see TransportDocumentNumber.
     *
     * Derived from MAX(order_number), not COUNT(*): counting rows means deleting
     * an order makes the next one reuse a live number, and two concurrent
     * creates both read the same count. withTrashed() so a soft-deleted order
     * still holds its number.
     *
     * The integer cast goes through SqlDate::intCast because MySQL spells it
     * SIGNED and rejects INTEGER outright — the exact defect that made employee,
     * proposal and estimate creation impossible on MySQL while passing every
     * SQLite test.
     */
    public static function nextLocalNumber(int $tenantId): string
    {
        $prefix = 'TO-'.date('Y').'-';

        $highest = static::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('order_number', 'like', $prefix.'%')
            ->selectRaw('MAX('.SqlDate::intCast('SUBSTR(order_number, ?)').') AS seq', [strlen($prefix) + 1])
            ->value('seq');

        return $prefix.str_pad((string) (((int) $highest) + 1), 6, '0', STR_PAD_LEFT);
    }

    /* ── Derived state ──────────────────────────────────────────────── */

    public function isTripEligible(): bool
    {
        return OrderStatus::isTripEligible($this->order_status);
    }

    public function statusLabel(): string
    {
        return OrderStatus::LABELS[$this->order_status] ?? $this->order_status;
    }

    public function priorityRank(): int
    {
        return OrderPriority::rank($this->priority);
    }
}
