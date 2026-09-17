<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripCost;
use App\Models\User;
use App\Support\Transport\CostSource;
use App\Support\Transport\CostType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SNG-TRN-012 — trip cost capture.
 *
 * The acceptance criterion is one sentence: "Every cost is linked to trip and
 * source." Both halves are enforced here, and the listed edge cases —
 * "duplicate callbacks", "boundary dates, amounts, concurrency, partial
 * completion" — are what most of this file is about.
 *
 * ── DEP-004 ──────────────────────────────────────────────────────────────
 * "Costs require trip", blocking. A cost is recorded against a trip that exists
 * in this tenant or it is not recorded. There is no orphan path, because an
 * orphan cost is money that never reaches SNG-TRN-018's margin and never gets
 * noticed.
 *
 * ── DOUBLE COUNTING IS THE WHOLE RISK ────────────────────────────────────
 * QA-005 requires duplicates to be "detected/handled per rule" and states no
 * rule. The rule this ticket sets, in two layers:
 *
 *   SYSTEM SOURCES  a unique index on (tenant_id, source, source_ref,
 *                   cost_type). A re-delivered webhook or a re-run import
 *                   collides and is absorbed as a no-op rather than counted
 *                   again. This is the idempotency the edge-case list asks for.
 *
 *   MANUAL ENTRY    no source_ref exists to key on, and a driver can genuinely
 *                   buy fuel twice in one day, so an identical-looking row is
 *                   NOT refused. It is reported back, and the caller must say
 *                   `confirm_duplicate` to proceed. Refusing outright would
 *                   make correct data unenterable; accepting silently is how a
 *                   margin quietly drifts.
 *
 * ── ARITHMETIC ───────────────────────────────────────────────────────────
 * bcmath throughout, never floats. Step 13's FIN-06 blocks a release on float
 * drift and these figures are subtracted from revenue to report a margin.
 *
 * ── WHAT THIS SERVICE DELIBERATELY DOES NOT DO ───────────────────────────
 * No approval workflow. Approval belongs to DB-008 `trip_expenses` (PERM-008
 * submit, PERM-009 approve, ENUM-005 approval_status) — a separate LOCKED
 * table. The boundary between the two is un-ruled (D-58) so this service builds
 * only the fact table its own ticket names, and leaves `CostSource::EXPENSE`
 * declared but unreachable for whoever settles that question.
 *
 * No event is emitted. Step 11 has no event for a cost being recorded — the
 * EV-008 the ticket cites is `TripExceptionRaised`, another domain entirely.
 * Inventing one would be FORBID-001.
 */
class TripCostService
{
    /** Look-alike window for hand-keyed rows, in days either side of incurred_on. */
    private const DUPLICATE_WINDOW_DAYS = 0;

    /* ── Reading ──────────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): TripCost
    {
        $cost = TripCost::forTenant($tenantId)->find($id);

        if (! $cost) {
            throw new ResourceNotFoundException('Trip cost');
        }

        return $cost;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TripCost> */
    public function forTrip(int $tripId, int $tenantId)
    {
        return TripCost::forTenant($tenantId)
            ->forTrip($tripId)
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Everything this trip has cost, as a decimal string.
     *
     * Soft-deleted rows are excluded by the model's global scope, which is the
     * point of using SoftDeletes here — a retracted cost leaves the margin.
     */
    public function totalFor(int $tripId, int $tenantId): string
    {
        return TripCost::forTenant($tenantId)
            ->forTrip($tripId)
            ->get()
            ->reduce(fn (string $sum, TripCost $c) => bcadd($sum, (string) $c->amount, 2), '0.00');
    }

    /**
     * Cost grouped by type — the shape SNG-TRN-018 consumes.
     *
     * Summed in PHP with bcmath rather than by SUM() in SQL. SQLite returns a
     * float from SUM() on a DECIMAL column, and that float is precisely the
     * drift FIN-06 blocks a release for. The grouping itself is what IDX-005
     * exists to serve.
     *
     * @return array<string, string>
     */
    public function breakdownFor(int $tripId, int $tenantId): array
    {
        $out = [];

        foreach (TripCost::forTenant($tenantId)->forTrip($tripId)->get() as $cost) {
            $type       = (string) $cost->cost_type;
            $out[$type] = bcadd($out[$type] ?? '0.00', (string) $cost->amount, 2);
        }

        ksort($out);

        return $out;
    }

    /* ── Writing ──────────────────────────────────────────────────────── */

    /**
     * Record one cost against a trip.
     *
     * @param  array{cost_type:string,amount:string|float|int,source?:string,source_ref?:string|null,currency?:string,incurred_on?:string|null,notes?:string|null,confirm_duplicate?:bool}  $data
     */
    public function record(TransportTrip $trip, array $data, int $tenantId, ?User $actor = null): TripCost
    {
        $this->assertTripBelongsToTenant($trip, $tenantId);

        $costType = $this->assertCostType((string) ($data['cost_type'] ?? ''));
        $amount   = $this->assertAmount($data['amount'] ?? null);
        $source   = $this->assertSource($data, $actor);

        $sourceRef = isset($data['source_ref']) && $data['source_ref'] !== ''
            ? (string) $data['source_ref']
            : null;

        $this->assertRefPresentWhenRequired($source, $sourceRef);

        $attributes = [
            'tenant_id'   => $tenantId,
            'trip_id'     => $trip->id,
            'cost_type'   => $costType,
            'amount'      => $amount,
            'currency'    => $data['currency'] ?? $trip->currency ?? 'INR',
            'source'      => $source,
            'source_ref'  => $sourceRef,
            'incurred_on' => $data['incurred_on'] ?? null,
            'notes'       => $data['notes'] ?? null,
            'recorded_by' => $actor?->id,
            'created_by'  => $actor?->id,
        ];

        // Hand-keyed rows have no key to deduplicate on, so the operator is
        // shown the look-alike and decides. Everything else is deduplicated by
        // the unique index below.
        if ($sourceRef === null && ! ($data['confirm_duplicate'] ?? false)) {
            $this->assertNoLookAlike($attributes, $tenantId);
        }

        return DB::transaction(function () use ($attributes, $actor, $tenantId, $trip) {
            try {
                $cost = TripCost::create($attributes);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    // A duplicate callback or a re-run import. The money is
                    // already recorded; returning the existing row makes the
                    // write idempotent instead of raising an error the sender
                    // will only retry. Deliberately NOT audited as a new cost —
                    // nothing changed, and a trail that logs non-events is one
                    // nobody reads.
                    $existing = TripCost::forTenant($attributes['tenant_id'])
                        ->fromSource($attributes['source'])
                        ->where('source_ref', $attributes['source_ref'])
                        ->ofType($attributes['cost_type'])
                        ->first();

                    if ($existing) {
                        Log::channel('transport')->info('Trip cost re-delivered, ignored', [
                            'cost_id'    => $existing->id,
                            'trip_id'    => $trip->id,
                            'source'     => $attributes['source'],
                            'source_ref' => $attributes['source_ref'],
                            'tenant_id'  => $tenantId,
                        ]);

                        return $existing;
                    }
                }

                throw $e;
            }

            $cost->audit('transport.cost.recorded', $actor, new: [
                'trip_id'    => $trip->id,
                'cost_type'  => $cost->cost_type,
                'amount'     => (string) $cost->amount,
                'source'     => $cost->source,
                'source_ref' => $cost->source_ref,
            ]);

            Log::channel('transport')->info('Trip cost recorded', [
                'cost_id' => $cost->id, 'trip_id' => $trip->id,
                'cost_type' => $cost->cost_type, 'amount' => (string) $cost->amount,
                'source' => $cost->source, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $cost->fresh();
        });
    }

    /**
     * Retract a cost from the margin without losing the row.
     *
     * SNG-TRN-018 has to be able to explain why a figure changed, so the record
     * survives; the global SoftDeletes scope takes it out of every sum.
     */
    public function retract(TripCost $cost, string $reason, int $tenantId, ?User $actor = null): TripCost
    {
        if ((int) $cost->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip cost');
        }

        if (trim($reason) === '') {
            throw new BusinessException('A reason is required to retract a cost.');
        }

        $cost->forceFill([
            'notes'      => trim($reason),
            'updated_by' => $actor?->id,
        ])->save();

        // Audited BEFORE the delete. The trail is a morph relation on this row,
        // and writing it after a soft delete works only because the row is
        // still there — relying on that is the kind of ordering that survives
        // until someone switches to a hard delete.
        $cost->audit('transport.cost.retracted', $actor, old: [
            'cost_type' => $cost->cost_type,
            'amount'    => (string) $cost->amount,
        ], new: [
            'reason' => trim($reason),
        ]);

        $cost->delete();

        Log::channel('transport')->info('Trip cost retracted', [
            'cost_id' => $cost->id, 'trip_id' => $cost->trip_id,
            'amount' => (string) $cost->amount, 'reason' => trim($reason),
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $cost;
    }

    /* ── Rules ────────────────────────────────────────────────────────── */

    private function assertTripBelongsToTenant(TransportTrip $trip, int $tenantId): void
    {
        // DEP-004. Reads as "not found" rather than "forbidden" on purpose: a
        // trip in another tenant must not be distinguishable from one that does
        // not exist, or the endpoint enumerates the estate.
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }
    }

    private function assertCostType(string $raw): string
    {
        $normalised = CostType::normalise($raw);

        if ($normalised === '') {
            throw new BusinessException('A cost type is required.');
        }

        // FLD-010 is VARCHAR(40). Checked rather than truncated — a silently
        // shortened type becomes its own group in the profitability breakdown.
        if (strlen($normalised) > CostType::MAX_LENGTH) {
            throw new BusinessException(
                'A cost type may be at most '.CostType::MAX_LENGTH.' characters.'
            );
        }

        return $normalised;
    }

    /**
     * @param  mixed  $raw
     */
    private function assertAmount($raw): string
    {
        if ($raw === null || $raw === '') {
            throw new BusinessException('An amount is required.');
        }

        $amount = (string) $raw;

        if (! is_numeric($amount)) {
            throw new BusinessException('The amount must be a number.');
        }

        // bccomp, not <=. The comparison has to agree with the arithmetic that
        // sums these rows, and a float comparison here would let through values
        // the decimal column then stores differently.
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new BusinessException('A cost amount must be greater than zero.');
        }

        // DECIMAL(18,2) — sixteen digits before the point.
        if (bccomp($amount, '9999999999999999.99', 2) > 0) {
            throw new BusinessException('The amount is larger than this field can store.');
        }

        return bcadd($amount, '0.00', 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSource(array $data, ?User $actor): string
    {
        $source = (string) ($data['source'] ?? CostSource::MANUAL);

        if (! CostSource::isValid($source)) {
            throw new BusinessException(
                'Unknown cost source. Expected one of: '.implode(', ', CostSource::ALL).'.'
            );
        }

        // A person using the API may only claim to be a person. Letting a
        // request declare itself `telemetry` or `settlement` would let a hand
        // -keyed row occupy an identity the deduplication trusts, and would put
        // a fabricated transaction id into SNG-TRN-018's reconciliation.
        if ($actor !== null && ! in_array($source, CostSource::OPERATOR_WRITABLE, true)) {
            throw new BusinessException(
                CostSource::label($source).' costs are raised by the system, not entered by hand.'
            );
        }

        return $source;
    }

    private function assertRefPresentWhenRequired(string $source, ?string $sourceRef): void
    {
        if (CostSource::requiresRef($source) && $sourceRef === null) {
            throw new BusinessException(
                CostSource::label($source).' costs must name the transaction they came from.'
            );
        }
    }

    /**
     * Report a hand-keyed row that looks like one already recorded.
     *
     * Same trip, same type, same amount, same day. Not a refusal on its own —
     * the caller re-sends with confirm_duplicate and it is accepted.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoLookAlike(array $attributes, int $tenantId): void
    {
        $query = TripCost::forTenant($tenantId)
            ->forTrip((int) $attributes['trip_id'])
            ->ofType((string) $attributes['cost_type'])
            ->where('amount', $attributes['amount']);

        $query = $attributes['incurred_on'] === null
            ? $query->whereNull('incurred_on')
            : $query->whereDate('incurred_on', $attributes['incurred_on']);

        if ($query->exists()) {
            throw new BusinessException(
                'A cost of this type and amount is already recorded on this trip for that date. '
                .'Re-send with confirm_duplicate to record it anyway.'
            );
        }
    }

    /**
     * Is this the unique index refusing a repeat, or a different failure?
     *
     * Matched on the driver's own signals rather than the message text, because
     * SQLite and MySQL word it differently and the suite runs on one while
     * production runs the other (D-51).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        // MySQL 1062, SQLite 19 / SQLSTATE 23000.
        return in_array((string) ($e->errorInfo[1] ?? ''), ['1062', '19'], true)
            || ($e->getCode() === '23000');
    }
}
