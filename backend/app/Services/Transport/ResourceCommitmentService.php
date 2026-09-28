<?php

namespace App\Services\Transport;

use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Support\Transport\TripStatus;
use Illuminate\Support\Carbon;

/**
 * What THIS module's trips say about a vehicle or a driver.
 *
 * ── WHAT THIS IS, AND WHAT IT IS CAREFUL NOT TO BE ───────────────────────
 * It answers exactly one question: *is this resource currently committed to one
 * of my trips, and when is that trip planned to arrive?* Both facts come from
 * Person 1 tables only — `trip_assignments` (the active assignment row) and
 * `transport_trips` (`trip_number`, `planned_arrival_at`).
 *
 * It does NOT compute availability, eligibility, or a recommendation. Those are
 * Person 2's allocation scoring under TM-001 §9, and this service must never
 * grow into them. Concretely, it will not:
 *
 *   - say a resource IS or IS NOT available — only that a trip holds it;
 *   - explain an absence it cannot see. Leave, maintenance, a breakdown or an
 *     expired document are Fleet facts, and a resource unavailable for one of
 *     those reasons simply does not appear here. The caller must then say
 *     "unavailable" honestly rather than inferring a reason from silence;
 *   - rank, sort by suitability, or suggest.
 *
 * The distinction matters because the two look identical on screen and are not:
 * "free in 2 days" is a fact this module owns; "the best vehicle for this trip"
 * is a judgement it does not.
 *
 * ── WHY THE ASSIGNMENT ROW AND NOT THE TRIP STATUS ───────────────────────
 * A resource is held by an ACTIVE ASSIGNMENT, not by a trip being in some list
 * of statuses. Picking a status set — is a `billed` trip still holding its
 * vehicle? — would be inventing an availability rule, which is the very thing
 * above. `TripAssignment::active()` already carries that meaning (BR-P0-003),
 * it is released explicitly, and it is Person 1's own artefact.
 */
class ResourceCommitmentService
{
    /**
     * @return array{vehicles:array<int,array<string,mixed>>,drivers:array<int,array<string,mixed>>}
     */
    public function forTenant(int $tenantId): array
    {
        $rows = TripAssignment::forTenant($tenantId)
            ->active()
            ->with(['trip:id,trip_number,status,planned_arrival_at,planned_departure_at,route'])
            ->get(['id', 'trip_id', 'vehicle_id', 'driver_id', 'assigned_at']);

        $vehicles = [];
        $drivers  = [];

        foreach ($rows as $row) {
            if (! $row->trip) {
                // An assignment whose trip is gone is not something to render a
                // confident sentence about.
                continue;
            }

            $commitment = $this->describe($row->trip);

            if ($row->vehicle_id) {
                $vehicles[(int) $row->vehicle_id] = $commitment;
            }

            if ($row->driver_id) {
                $drivers[(int) $row->driver_id] = $commitment;
            }
        }

        return ['vehicles' => $vehicles, 'drivers' => $drivers];
    }

    /**
     * One commitment, ready to render as a sentence.
     *
     * `sentence` is built here rather than in the client so that the phrasing
     * is the same everywhere it appears, and so that "no arrival date recorded"
     * is stated once instead of being guessed at by each caller.
     *
     * @return array<string,mixed>
     */
    private function describe(TransportTrip $trip): array
    {
        $arrival = $trip->planned_arrival_at;

        return [
            'trip_id'            => (int) $trip->id,
            'trip_number'        => $trip->trip_number,
            'trip_status'        => $trip->status,
            'trip_status_label'  => TripStatus::LABELS[$trip->status] ?? $trip->status,
            'route'              => $trip->route,
            'planned_arrival_at' => $arrival?->toIso8601String(),
            'free_in_days'       => $this->freeInDays($arrival),
            'sentence'           => $this->sentence($trip, $arrival),
        ];
    }

    /**
     * Whole days from today until the trip is planned to arrive.
     *
     * Null when no arrival date is recorded — NOT zero, which would read as
     * "free today". Negative is kept rather than clamped: a trip whose planned
     * arrival has passed is a real situation a dispatcher needs to see, and
     * hiding it behind a 0 would make an overdue trip look finished.
     */
    private function freeInDays(?Carbon $arrival): ?int
    {
        if (! $arrival) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($arrival->copy()->startOfDay(), absolute: false);
    }

    private function sentence(TransportTrip $trip, ?Carbon $arrival): string
    {
        $on = 'On '.$trip->trip_number;

        if (! $arrival) {
            // Honest rather than helpful: no date exists, so none is implied.
            return $on.' — no planned arrival date recorded';
        }

        $days = $this->freeInDays($arrival);
        $date = $arrival->format('j M');

        return match (true) {
            $days < 0  => $on.' — was due back '.$date,
            $days === 0 => $on.' — back today',
            $days === 1 => $on.' — back tomorrow, '.$date,
            default     => $on.' — back in '.$days.' days, '.$date,
        };
    }
}
