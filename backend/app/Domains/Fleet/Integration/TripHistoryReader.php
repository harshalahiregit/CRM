<?php

namespace App\Domains\Fleet\Integration;

use App\Models\Transport\TripAssignment;
use App\Support\Transport\AssignmentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * STOS-FLEET — the trip-assignment history Fleet reporting reads (T-49).
 *
 * The companion to `TripCommitmentReader`. That one answers "is this ONE asset
 * on a trip right now" for a guard; this one answers the fleet-wide, over-time
 * questions utilisation reporting asks. Both live here for the same reason
 * (D-146): `trip_assignments` is Ops' table, and every read of it from Fleet
 * goes through this package so Ops' schema never leaks into Fleet's services.
 * If the shape of an assignment changes, exactly these two files change.
 *
 * "Occupied" is Ops' meaning, not one invented here. A vehicle is working while
 * an assignment holds it — `ASSIGNED / CONFIRMED / ACTIVE` now, or `RELEASED`
 * for a trip that has ended. The earlier states (request, recommendation,
 * approval) are a booking being decided, not a truck on the road, and are left
 * out. This mirrors `TripAssignment::active()`, which Person 1 has recorded as a
 * contract between us.
 *
 * Every method degrades to "nothing" when Ops is not installed (Fleet runs
 * standalone) or a read fails — a report that shows no trips is a truthful
 * answer for a fleet with no trip module, where a crash is not.
 */
class TripHistoryReader
{
    /** Assignments in which the vehicle was actually held, ever or now. */
    private function occupyingStates(): array
    {
        return array_merge(AssignmentStatus::ACTIVE_STATES, [AssignmentStatus::RELEASED]);
    }

    /** Vehicle ids with an active assignment now — the trucks that are working. */
    public function busyVehicleIds(int $companyId): array
    {
        if (! Schema::hasTable('trip_assignments')) {
            return [];
        }

        try {
            return TripAssignment::forTenant($companyId)
                ->active()
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * The most recent time each vehicle came OFF a trip, keyed by vehicle id.
     *
     * This is "idle since" for a truck that has worked and is now free. A truck
     * that has never been on a trip is absent here — the caller falls back to
     * when it was onboarded.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function lastReleaseByVehicle(int $companyId): array
    {
        if (! Schema::hasTable('trip_assignments')) {
            return [];
        }

        try {
            $out = [];

            TripAssignment::forTenant($companyId)
                ->whereNotNull('vehicle_id')
                ->whereNotNull('released_at')
                ->get(['vehicle_id', 'released_at'])
                ->each(function ($row) use (&$out) {
                    $id = (int) $row->vehicle_id;
                    $at = Carbon::parse($row->released_at);

                    if (! isset($out[$id]) || $at->greaterThan($out[$id])) {
                        $out[$id] = $at;
                    }
                });

            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Every interval a vehicle was occupied that overlaps [$from, $to], keyed by
     * vehicle id. `end` is null for an assignment still open, which the caller
     * caps at "now" — a truck on a trip that has not ended is working up to the
     * moment the report is run.
     *
     * @return array<int, array<int, array{start: \Illuminate\Support\Carbon, end: ?\Illuminate\Support\Carbon}>>
     */
    public function workingIntervals(int $companyId, Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('trip_assignments')) {
            return [];
        }

        try {
            $out = [];

            TripAssignment::forTenant($companyId)
                ->whereNotNull('vehicle_id')
                ->whereIn('status', $this->occupyingStates())
                ->where('assigned_at', '<', $to)
                // Overlaps the window: still open, or released after it began.
                ->where(fn ($q) => $q->whereNull('released_at')->orWhere('released_at', '>', $from))
                ->get(['vehicle_id', 'assigned_at', 'released_at'])
                ->each(function ($row) use (&$out) {
                    $out[(int) $row->vehicle_id][] = [
                        'start' => Carbon::parse($row->assigned_at),
                        'end'   => $row->released_at ? Carbon::parse($row->released_at) : null,
                    ];
                });

            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}
