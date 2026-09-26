<?php

namespace App\Models\Transport;

use App\Models\Transport\Concerns\RecordsTransportAudit;
use App\Models\Traits\BelongsToTenant;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pre-dispatch readiness check against one trip. SNG-TRN-010 step 2.
 *
 * See the migration for why this entity exists without a registry entry (D-15),
 * why there is no header table, and why is_critical is a stored snapshot.
 *
 * ── DELIBERATELY NO SoftDeletes ───────────────────────────────────────────
 * Same reasoning as TripAssignment. A check is a transactional fact about a
 * trip; when an assignment is released the run is INVALIDATED — reset to
 * pending — not deleted, and the audit log keeps what it said before.
 *
 * ── THE READINESS STATUS LIVES HERE, AS A PURE FUNCTION ───────────────────
 * readinessOf() is deliberately static and takes a collection rather than
 * reading the database, so the same rule can grade rows that were just built in
 * memory and rows loaded from a trip, and so it cannot accidentally answer for
 * the wrong tenant. Every caller resolves its own tenant-scoped rows first.
 *
 * @property int         $tenant_id
 * @property int         $trip_id
 * @property string      $check_key
 * @property bool        $is_critical
 * @property string      $result
 * @property string|null $detail
 * @property string|null $remarks
 */
class TripPretripCheck extends Model
{
    use HasFactory, BelongsToTenant, RecordsTransportAudit;

    protected $table = 'trip_pretrip_checks';

    protected $fillable = [
        'tenant_id', 'trip_id', 'check_key', 'is_critical',
        'result', 'detail', 'evaluated_at',
        'created_by', 'updated_by',
        // `remarks`, `completed_by` and `completed_at` are NOT mass-assignable:
        // completion is the acceptance criterion of this ticket and goes through
        // the service so the act is audited, never by a caller setting a field.
        //
        // The override columns are withheld for a stronger reason — CMP-007 /
        // BRW-049 / PLN-007 are all P1 and nothing may record an override before
        // the ticket that authorises and audits one exists.
    ];

    protected $casts = [
        'trip_id'       => 'integer',
        'is_critical'   => 'boolean',
        'evaluated_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'completed_by'  => 'integer',
        'overridden_at' => 'datetime',
    ];

    /**
     * Mirrors the column defaults, so a freshly instantiated row grades the same
     * way as one read back from the database. Without this, result is null on a
     * new model and every accessor below has to defend against it.
     */
    protected $attributes = [
        'result'      => PretripResult::PENDING,
        'is_critical' => false,
    ];

    /* ── Relations ──────────────────────────────────────────────────── */

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'trip_id');
    }

    /* ── Scopes. Composed AFTER forTenant(), never instead of it. ────── */

    public function scopeForTrip(Builder $query, int $tripId): Builder
    {
        return $query->where('trip_id', $tripId);
    }

    /** Rows nobody has confirmed yet. Drives OPS §29's IN_PROGRESS. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /** Rows that block dispatch. BRW-052, OPS §30. */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('result', PretripResult::BLOCKING);
    }

    /* ── State of one check ─────────────────────────────────────────── */

    public function isPending(): bool
    {
        return $this->result === PretripResult::PENDING;
    }

    /** BRW-052: only a critical failure blocks. */
    public function blocks(): bool
    {
        return PretripResult::blocks($this->result);
    }

    /** Passed, with or without a caveat. */
    public function satisfied(): bool
    {
        return PretripResult::satisfied($this->result);
    }

    /** Has a person stamped it? This is the acceptance criterion, as a question. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Something worth showing that is not a block. UX §36: a warning is not a
     * block, and the two must not be rendered as though they were.
     */
    public function isWarning(): bool
    {
        return in_array($this->result, PretripResult::WARNING, true);
    }

    /* ── Presentation. No colour-only signals — UX §129. ─────────────── */

    public function label(): string
    {
        return PretripCheckKey::label($this->check_key);
    }

    public function category(): ?string
    {
        return PretripCheckKey::categoryOf($this->check_key);
    }

    public function categoryLabel(): string
    {
        return PretripCheckKey::categoryLabel((string) $this->category());
    }

    public function resultLabel(): string
    {
        return PretripResult::label($this->result);
    }

    /* ── The derived checklist status ───────────────────────────────── */

    /**
     * OPS §29's readiness, computed from a trip's rows.
     *
     * The order of these tests is the rule, not a convenience:
     *
     *   no rows                     → NOT_STARTED   (§29)
     *   nothing evaluated yet       → NOT_STARTED   (§29)
     *   any critical failure        → BLOCKED       (§30, BRW-046, BRW-052)
     *   any row not yet completed   → IN_PROGRESS   (§29)
     *   otherwise                   → READY
     *
     * The second test is what an INVALIDATED run reads as. When a crew is
     * released the rows are reset to `pending` rather than deleted — deleting
     * them would orphan the audit entries written against them, which is the
     * same referential failure D-14 was raised for. A checklist whose every row
     * is pending has had nothing evaluated, and NOT_STARTED says exactly that;
     * calling it IN_PROGRESS would imply work already done.
     *
     * BLOCKED is tested BEFORE IN_PROGRESS on purpose. A checklist with one
     * critical failure and one unanswered item is blocked, not merely
     * unfinished — reporting it as in-progress would hide the failure behind
     * incompleteness and let a screen imply that finishing the remaining items
     * would release the trip.
     *
     * OVERRIDE_REQUIRED is never returned. Override is P1; see PretripReadiness.
     *
     * @param  Collection<int,self>|iterable<self>  $checks
     */
    public static function readinessOf(iterable $checks): string
    {
        $rows = $checks instanceof Collection ? $checks : collect($checks);

        if ($rows->isEmpty()) {
            return PretripReadiness::NOT_STARTED;
        }

        if ($rows->every(fn (self $c) => $c->isPending())) {
            return PretripReadiness::NOT_STARTED;
        }

        if ($rows->contains(fn (self $c) => $c->blocks())) {
            return PretripReadiness::BLOCKED;
        }

        if ($rows->contains(fn (self $c) => ! $c->isCompleted())) {
            return PretripReadiness::IN_PROGRESS;
        }

        return PretripReadiness::READY;
    }

    /**
     * The reasons a checklist is not READY, in the tone BRWM §70 requires:
     * say what is wrong and what to do, never merely that something failed.
     *
     * The same `{code, why, owner}` shape an eligibility verdict emits — D-150.
     * The passport and the pre-trip panel read these through the same
     * `reason()` as the allocation panel, which renders a bare string as EMPTY.
     * `owner` is null: no document names a desk for a pre-trip check, and
     * inventing one would be a rule nobody made.
     *
     * @param  Collection<int,self>|iterable<self>  $checks
     * @return array<int,array{code:string,why:string,owner:null}>
     */
    public static function blockersOf(iterable $checks): array
    {
        $rows = $checks instanceof Collection ? $checks : collect($checks);

        return $rows->filter(fn (self $c) => $c->blocks())
            ->map(fn (self $c) => $c->reason($c->label().': '.($c->detail ?? 'failed')))
            ->values()
            ->all();
    }

    /**
     * Caveats that do NOT block. Kept separate from blockers so a screen can
     * honour UX §36 without having to re-derive the distinction.
     *
     * @param  Collection<int,self>|iterable<self>  $checks
     * @return array<int,array{code:string,why:string,owner:null}>
     */
    public static function warningsOf(iterable $checks): array
    {
        $rows = $checks instanceof Collection ? $checks : collect($checks);

        return $rows->filter(fn (self $c) => $c->isWarning())
            ->map(fn (self $c) => $c->reason($c->label().': '.($c->detail ?? 'needs attention')))
            ->values()
            ->all();
    }

    /** @return array{code:string,why:string,owner:null} */
    private function reason(string $why): array
    {
        return ['code' => $this->check_key, 'why' => $why, 'owner' => null];
    }
}
