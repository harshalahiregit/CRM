<?php

namespace App\Domains\Fleet\Integration;

use App\Models\Transport\TransportTrip;
use App\Services\Transport\TripCostService;
use App\Support\Transport\CostSource;
use Illuminate\Support\Facades\Log;

/**
 * C-06 — Fleet's operating costs reach the trip's P&L.
 *
 * Person 3 built `trip_costs` and documented exactly how Fleet should write to
 * it, then waited. This is Fleet's half: every fuel fill, urea top-up, toll
 * crossing and workshop job that names a `trip_id` is published to the trip so
 * Finance can compute contribution without querying our tables.
 *
 * ── Three rules taken straight from Person 3's contract ───────────────────
 *
 * **`source_ref` is mandatory and it is what makes retries safe.** There is a
 * unique index on `(tenant_id, source, source_ref, cost_type)`. Sending the same
 * row twice returns the first row instead of erroring, so a re-run import or a
 * corrected entry cannot double a trip's cost. Our `source_ref` is the id of the
 * Fleet row that caused it, which is stable forever.
 *
 * **The actor is always null.** A request carrying a real user is treated as
 * hand-entered and may only claim `manual` or `import`. These rows are the
 * system reporting a measurement, not a person claiming one — and the
 * deduplication trusts the source, so wearing telemetry's identity by hand is
 * exactly what must not be possible.
 *
 * **Publishing never breaks the thing that caused it.** A fuel entry is a fact
 * about a vehicle whether or not Finance heard about it. If this fails, the fill
 * is still saved and the failure is logged for the backfill to pick up —
 * refusing to record a driver's diesel because a downstream table was busy would
 * be the wrong trade every time.
 */
class TripCostPublisher
{
    /** Fleet's cost types, in the spellings Person 3's `CostType::KNOWN` suggests. */
    public const TYPE_FUEL        = 'fuel';
    public const TYPE_UREA        = 'urea';
    public const TYPE_TOLL        = 'toll';
    public const TYPE_MAINTENANCE = 'maintenance';

    public function __construct(private ?TripCostService $costs = null)
    {
    }

    /**
     * Publish one Fleet cost to its trip.
     *
     * @param  int|null  $tripId      no trip means fleet overhead, not a trip cost
     * @param  string    $sourceRef   the Fleet row id — stable, so retries dedupe
     */
    public function publish(
        ?int $tripId,
        int $companyId,
        string $costType,
        string $amount,
        string $sourceRef,
        ?string $incurredOn = null,
    ): bool {
        // No trip: routine servicing, a yard top-up, a toll on a private run.
        // Real costs, but fleet overhead — apportioning them across trips is a
        // costing policy for Finance to set, not for Fleet to invent.
        if (! $tripId) {
            return false;
        }

        if (! $this->available()) {
            return false;   // Transport module not installed (standalone mode)
        }

        try {
            $trip = TransportTrip::find($tripId);

            if (! $trip || (int) $trip->tenant_id !== $companyId) {
                // A trip id that resolves to nothing, or to another company's
                // trip, is a data problem worth seeing — not worth throwing over.
                Log::channel('stos')->warning('Fleet cost names a trip it cannot publish to', [
                    'trip_id' => $tripId, 'company_id' => $companyId,
                    'cost_type' => $costType, 'source_ref' => $sourceRef,
                ]);

                return false;
            }

            app(TripCostService::class)->record($trip, [
                'cost_type'   => $costType,
                // A string, never a float — the amount crosses the boundary
                // exactly as it is stored.
                'amount'      => $amount,
                'source'      => CostSource::TELEMETRY,
                'source_ref'  => $sourceRef,
                'incurred_on' => $incurredOn ?? now()->toDateString(),
            ], $companyId, actor: null);

            return true;
        } catch (\Throwable $e) {
            Log::channel('stos')->error('Could not publish a Fleet cost to its trip', [
                'trip_id' => $tripId, 'company_id' => $companyId,
                'cost_type' => $costType, 'source_ref' => $sourceRef,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** Is the Transport module present? Fleet also runs without it. */
    public function available(): bool
    {
        return class_exists(TripCostService::class) && class_exists(TransportTrip::class);
    }

    /* ── The four Fleet cost types ──────────────────────────────── */

    public function publishFuel(int $companyId, $fuel): bool
    {
        return $this->publish(
            $fuel->trip_id, $companyId, self::TYPE_FUEL,
            (string) $fuel->amount, 'fuel:'.$fuel->id,
            optional($fuel->created_at)->toDateString(),
        );
    }

    public function publishUrea(int $companyId, $urea): bool
    {
        return $this->publish(
            $urea->trip_id, $companyId, self::TYPE_UREA,
            (string) $urea->amount, 'urea:'.$urea->id,
            optional($urea->created_at)->toDateString(),
        );
    }

    public function publishToll(int $companyId, $toll): bool
    {
        return $this->publish(
            $toll->trip_id, $companyId, self::TYPE_TOLL,
            (string) $toll->amount, 'toll:'.$toll->id,
            optional($toll->transaction_timestamp)->toDateString(),
        );
    }

    /**
     * A workshop job attributable to a trip — a breakdown on the road.
     *
     * Published on CLOSURE, not on opening: the cost is not known until the
     * card is closed, and publishing a zero would put a wrong number in the
     * trip's P&L that the dedupe key would then refuse to correct.
     */
    public function publishMaintenance(int $companyId, $job): bool
    {
        return $this->publish(
            $job->trip_id, $companyId, self::TYPE_MAINTENANCE,
            (string) $job->total_cost, 'maintenance:'.$job->id,
            optional($job->closed_at)->toDateString(),
        );
    }
}
