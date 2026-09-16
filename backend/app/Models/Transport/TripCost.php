<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\CostSource;
use App\Support\Transport\CostType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * DB-006 `trip_costs` — "Canonical trip cost facts", LOCKED.  SNG-TRN-012.
 *
 * One row is one thing the trip cost, attributed to the trip and to the source
 * that produced it. The sum of these rows is what SNG-TRN-018 subtracts from
 * revenue, which is why every rule on this table is really a rule about not
 * counting the same money twice.
 *
 * ── NORMALISATION IS ENFORCED HERE, NOT LEFT TO CALLERS ──────────────────
 * `cost_type` is free text by necessity (CST-001 is a dangling pointer — D-58),
 * so the ONLY thing standing between IDX-005 and a fragmented grouping is that
 * every write folds case and whitespace. A mutator does that on the model
 * rather than in the service, because ::create() and ::update() are reachable
 * from seeders, imports and tests that will never call the service. A rule that
 * protects an aggregate has to hold on every path that writes.
 *
 * Business rules — the duplicate check, the source contract, the trip must
 * exist — live in TripCostService and NOT here. A model that enforced those
 * would still be bypassed by the first caller using ::create(), and would make
 * legitimate bulk imports impossible.
 *
 * ── SOFT DELETES, UNLIKE TripAdvance ─────────────────────────────────────
 * TripAdvance deliberately has none: a refused request must stay as evidence.
 * A cost is different — it is a fact that can simply be wrong (keyed twice,
 * attributed to the wrong trip), and a correction must remove it from the
 * margin. SoftDeletes retracts it from every sum while keeping the row for
 * audit, which is what "reconcile to source transactions" needs. A hard delete
 * would leave SNG-TRN-018 unable to explain a figure that changed.
 *
 * @property int         $tenant_id
 * @property int         $trip_id
 * @property string      $cost_type
 * @property string      $amount
 * @property string      $source
 * @property string|null $source_ref
 */
class TripCost extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_costs';

    protected $fillable = [
        'tenant_id', 'trip_id', 'cost_type', 'amount', 'currency',
        'source', 'source_ref', 'incurred_on', 'notes',
        'recorded_by', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'trip_id'     => 'integer',
        // Cast as a decimal string, never a float. Step 13's FIN-06 blocks a
        // release on float drift, and these are summed then subtracted from
        // revenue — the one place rounding error becomes a reported margin.
        'amount'      => 'decimal:2',
        'incurred_on' => 'date',
        'recorded_by' => 'integer',
    ];

    protected $appends = ['cost_type_label'];

    /* ── Normalisation on every write path ────────────────────────────── */

    public function setCostTypeAttribute(?string $value): void
    {
        $this->attributes['cost_type'] = $value === null ? null : CostType::normalise($value);
    }

    /* ── Relations ────────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    /* ── Scopes. None filters by tenant; they compose AFTER forTenant(). ── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    public function scopeOfType(Builder $query, string $costType): Builder
    {
        return $query->where('cost_type', CostType::normalise($costType));
    }

    public function scopeFromSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /* ── Derived ──────────────────────────────────────────────────────── */

    public function getCostTypeLabelAttribute(): string
    {
        return CostType::label((string) $this->cost_type);
    }

    public function sourceLabel(): string
    {
        return CostSource::label((string) $this->source);
    }

    /**
     * Was this row produced by a system that can re-deliver it?
     *
     * True means the unique index is doing the deduplication. False means this
     * is a hand-keyed row whose only protection was the look-alike check the
     * operator confirmed past.
     */
    public function isDeduplicated(): bool
    {
        return $this->source_ref !== null;
    }
}
