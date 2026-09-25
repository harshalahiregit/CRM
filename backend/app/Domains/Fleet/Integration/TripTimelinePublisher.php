<?php

namespace App\Domains\Fleet\Integration;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Transport\TransportTrip;
use App\Services\Transport\TripEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-INT → the shared trip timeline.
 *
 * Person 1 built `trip_events` as one timeline all three sections write to, and
 * registered nine event types against Fleet's name. This is Fleet's half: a
 * container can now be opened and the journey read, which is step 8 of the
 * 30 September script and was NOT REACHABLE before.
 *
 * ── THE TIMELINE IS NOT THE TRAIL ─────────────────────────────────────────
 * A tracker reports every couple of minutes. Writing each ping here would put
 * several hundred identical "position" lines on a timeline somebody is trying
 * to read a journey from, and bury the four events that matter — it departed,
 * the genset stopped, the load warmed, it arrived.
 *
 * So this emits on CHANGE and on first-of-trip only. Every ping is still kept:
 * `telemetry_records` is the complete trail and is what an investigation reads.
 * The timeline is the story.
 *
 * ── occurredAt IS THE DEVICE'S CLOCK ──────────────────────────────────────
 * Person 1's contract asks for the time it happened rather than the time we
 * heard, and it matters more on this side than on his. A unit coming out of a
 * tunnel replays an hour of buffered pings inside one second: ordered by
 * arrival they read as a stampede at 14:32, ordered by `recorded_at` they are
 * the hour they actually were.
 */
class TripTimelinePublisher
{
    /** Trip states in which a vehicle's readings belong to that trip. */
    private const LIVE_TRIP_STATES = ['dispatched', 'in_transit', 'DISPATCHED', 'IN_TRANSIT'];

    /** Schema answers that cannot change inside one request. */
    private ?bool $legacyMaster = null;
    private ?bool $ledger = null;

    /** company id => has the repoint run for it. */
    private array $switched = [];

    /**
     * Publish whatever this ping changed.
     *
     * @param  array  $reading   the validated ping
     * @param  object|null  $previous  the live row BEFORE this ping, for change detection
     */
    public function publish(Vehicle $vehicle, array $reading, $recordedAt, $previous = null): void
    {
        if (! $this->available()) {
            return;     // Transport module not installed (standalone mode)
        }

        $trip = $this->openTripFor($vehicle);

        if (! $trip) {
            // A truck idling in the yard reports GPS and genset state with no
            // trip to attach it to. Dropped deliberately rather than invented a
            // home for — the reading is still in `telemetry_records`, and a
            // vehicle-scoped timeline is Person 1's to define if he wants one.
            return;
        }

        try {
            $this->positionEvents($trip, $vehicle, $reading, $recordedAt, $previous);
            $this->gensetEvents($trip, $vehicle, $reading, $recordedAt, $previous);
            $this->temperatureEvents($trip, $vehicle, $reading, $recordedAt, $previous);
        } catch (\Throwable $e) {
            // A ping is a fact about a vehicle whether or not the timeline heard
            // it. Refusing to record a position because a timeline write failed
            // would be the wrong trade every time.
            Log::channel('stos')->warning('Could not publish telemetry to the trip timeline', [
                'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** First fix of the trip only — "the tracker is alive and we can see it". */
    private function positionEvents(TransportTrip $trip, Vehicle $vehicle, array $reading, $recordedAt, $previous): void
    {
        if ($reading['latitude'] === null || $reading['longitude'] === null) {
            return;
        }

        // Only the first position on this trip. Subsequent fixes are the trail.
        if ($previous && $previous->latitude !== null) {
            return;
        }

        $this->recorder()->record(
            type: 'gps.activated',
            trip: $trip,
            occurredAt: $recordedAt,
            summary: 'Tracking active on '.$vehicle->registration_number,
            detail: [
                'vehicle_id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'latitude' => $reading['latitude'], 'longitude' => $reading['longitude'],
            ],
        );
    }

    /** Only when it actually changes — a reefer's genset is on for days. */
    private function gensetEvents(TransportTrip $trip, Vehicle $vehicle, array $reading, $recordedAt, $previous): void
    {
        $now = $reading['generator_status'] ?? null;

        if ($now === null) {
            return;
        }

        $before = $previous->generator_status ?? null;

        if ($before === $now) {
            return;
        }

        // T-06 — the registry has two genset types and the device reports four
        // states, so the mapping is by MEANING, not by name: OFF and FAULT both
        // mean the load is not being cooled. A faulted unit published as
        // `genset.on` is how a spoiled load goes unnoticed, and inventing a
        // third event type is not ours to do — Step 11 registers the types.
        //
        // The state itself is never lost: it is in the summary a person reads
        // and in the detail a query can filter on.
        $notCooling = in_array($now, VehicleLiveStatus::GENSET_NOT_COOLING, true);

        $this->recorder()->record(
            type: $notCooling ? 'genset.off' : 'genset.on',
            trip: $trip,
            occurredAt: $recordedAt,
            summary: 'Genset '.$now.' on '.$vehicle->registration_number,
            detail: [
                'vehicle_id' => $vehicle->id,
                'from' => $before, 'to' => $now,
                'cooling' => ! $notCooling,
                'temperature' => $reading['temperature'] ?? null,
            ],
        );
    }

    /**
     * Temperature on a meaningful move only.
     *
     * A reefer drifts a few tenths constantly. Half a degree is the smallest
     * change worth a line on a timeline; anything finer is noise that hides the
     * excursion it is supposed to make visible.
     */
    private function temperatureEvents(TransportTrip $trip, Vehicle $vehicle, array $reading, $recordedAt, $previous): void
    {
        $now = $reading['temperature'] ?? null;

        if ($now === null) {
            return;
        }

        $before = $previous->temperature ?? null;

        if ($before !== null && abs((float) $before - (float) $now) < 0.5) {
            return;
        }

        $this->recorder()->record(
            type: 'temperature.reading',
            trip: $trip,
            occurredAt: $recordedAt,
            summary: number_format((float) $now, 1).'°C on '.$vehicle->registration_number,
            detail: ['vehicle_id' => $vehicle->id, 'celsius' => $now, 'previous' => $before],
        );
    }

    /** The cold chain broke — published separately so it is never filtered out as noise. */
    public function publishExcursion(Vehicle $vehicle, array $reading, $recordedAt): void
    {
        if (! $this->available()) {
            return;
        }

        $trip = $this->openTripFor($vehicle);

        if (! $trip) {
            return;
        }

        try {
            $this->recorder()->record(
                type: 'temperature.excursion',
                trip: $trip,
                occurredAt: $recordedAt,
                summary: 'Temperature excursion on '.$vehicle->registration_number
                    .' — genset off at '.number_format((float) ($reading['temperature'] ?? 0), 1).'°C',
                detail: [
                    'vehicle_id' => $vehicle->id,
                    'celsius' => $reading['temperature'] ?? null,
                    'generator_status' => $reading['generator_status'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::channel('stos')->warning('Could not publish an excursion to the trip timeline', [
                'vehicle_id' => $vehicle->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * The trip this vehicle is currently out on, if any.
     *
     * ── D-116: THE ID ALONE CANNOT BE INTERPRETED ─────────────────────────
     * Person 1 found this. `transport_trips.vehicle_id` holds a
     * `transport_vehicles` id today and will hold a `vehicles` id after the
     * repoint — two tables that issue ids independently, with no foreign key
     * and no column saying which space the number is in.
     *
     * My first version compared a Fleet id against that column directly and I
     * documented the mismatch as "finds nothing, which is correct". It was not
     * correct, it was lucky: the two ranges did not overlap on his machine. He
     * constructed the overlap and both events landed on a trip belonging to a
     * different truck — no error, no log line, a plausible-looking entry.
     *
     * ── AND THE FIRST FIX STILL CONFIRMED ITSELF ──────────────────────────
     * My second version asked the plate to decide, but resolved that plate in
     * BOTH masters and unioned the answers. When the `transport_vehicles` row
     * was missing — a dangling id, of which we have three in the demonstration
     * data — the only plate that came back was Fleet's own, read with the
     * trip's number. The check compared a vehicle's plate against itself and
     * could not fail. Person 1 re-ran the experiment instead of accepting the
     * fix, and that is how we know.
     *
     * ── SO EVERY ANSWER NOW COMES FROM THE TABLE THE NUMBER BELONGS TO ────
     * Three questions, in order, and each one is answered from data:
     *
     *   1. Did the repoint rule on this trip? Its verdict decides — including
     *      the verdict "this pointed at a row that was gone", which is a
     *      permanent NO rather than an invitation to guess.
     *   2. No verdict, and the switch has happened for this company? Then the
     *      trip was raised afterwards and the column means a Fleet id.
     *   3. Otherwise the column still means `transport_vehicles`, so the plate
     *      is read THERE and nowhere else. A row that is gone is unknown, and
     *      unknown is never a match.
     *
     * A candidate that cannot be confirmed is REFUSED and logged rather than
     * published. A wrong timeline entry naming the wrong truck is worse than a
     * missing one: the missing one gets noticed.
     */
    private function openTripFor(Vehicle $vehicle): ?TransportTrip
    {
        $ids = $this->idsThisVehicleMayBeKnownBy($vehicle);

        if ($ids === []) {
            return null;
        }

        $candidates = DB::table('transport_trips')
            ->where('tenant_id', $vehicle->company_id)
            ->whereIn('vehicle_id', $ids)
            ->whereIn('status', self::LIVE_TRIP_STATES)
            ->orderByDesc('id')
            ->get(['id', 'vehicle_id']);

        foreach ($candidates as $candidate) {
            if ($this->tripBelongsTo($candidate, $vehicle)) {
                return TransportTrip::find($candidate->id);
            }

            Log::channel('stos')->warning('Trip matched a vehicle id but not the vehicle; not published', [
                'defect' => 'D-116',
                'trip_id' => $candidate->id, 'trip_vehicle_id' => $candidate->vehicle_id,
                'fleet_vehicle_id' => $vehicle->id,
                'expected_plate' => $this->normalisePlate($vehicle->registration_number),
                'why' => 'transport_trips.vehicle_id is ambiguous between the two masters until the repoint completes',
            ]);
        }

        return null;
    }

    /**
     * Is this candidate trip out on THIS vehicle?
     *
     * The three questions from `openTripFor()`, in that order. Nothing here
     * falls back to "the number looked right".
     */
    private function tripBelongsTo(object $candidate, Vehicle $vehicle): bool
    {
        $verdict = $this->repointVerdictFor((int) $candidate->id);

        if ($verdict !== null) {
            $current = (int) $candidate->vehicle_id;

            // ── A VERDICT DESCRIBES A VALUE, NOT A ROW FOREVER ────────────
            // The ledger says what the repoint decided about the number the
            // trip held AT THAT MOMENT. It used to be applied to the trip row
            // for good, whatever the row held later — and a trip's vehicle_id
            // does change after the switch:
            //
            //   - A crew swap. Release clears the pointer and reassignment
            //     writes the replacement truck. The verdict still named the
            //     truck it replaced, so the replacement's telemetry was
            //     refused and the trip went dark — at the moment a breakdown
            //     swap makes watching it matter most.
            //   - A correction. Person 1 split unmatchable rows into
            //     unmapped_legacy and never_valid, and never_valid is fixed by
            //     somebody correcting the row. A corrected row stayed refused
            //     forever, because its old verdict still said "unmatchable".
            //
            // So the verdict governs only while the trip still holds the value
            // it was written about. Once the value has moved on, it was written
            // after the switch, which means it is a Fleet id.
            if ($verdict->to_id === null) {
                if ($current === (int) $verdict->from_id) {
                    // Still the stranded value — unmapped or never valid.
                    // Either way it names no truck we can vouch for.
                    return false;
                }
            } elseif ($current === (int) $verdict->to_id) {
                return (int) $verdict->to_id === (int) $vehicle->id;
            }

            return $current === (int) $vehicle->id;
        }

        if ($this->switchHasHappenedFor((int) $vehicle->company_id)) {
            return (int) $candidate->vehicle_id === (int) $vehicle->id;
        }

        $legacy = $this->legacyPlateFor((int) $candidate->vehicle_id, (int) $vehicle->company_id);

        return $legacy !== null
            && $legacy === $this->normalisePlate($vehicle->registration_number);
    }

    /**
     * Every id this vehicle could be referenced by, in either space.
     *
     * The Fleet id for a repointed trip, and the legacy id for one that has not
     * been repointed yet.
     *
     * ── THE STORED LINK IS NOT ENOUGH ON ITS OWN ──────────────────────────
     * `legacy_transport_vehicle_id` is written by the data move, and it goes
     * stale: a reseed of the legacy table left ours pointing at rows 29 and 30
     * while the live rows for the same two trucks were 35 and 36. The effect is
     * not a wrong answer, it is no answer — a trip on the RIGHT truck finds no
     * candidate and the reading is dropped, which looks exactly like an idle
     * truck. That is why step 8 stayed blank.
     *
     * So the plate finds candidates as well as confirming them. It is the one
     * identifier that survives a reseed, and `transport_vehicles` is unique on
     * (tenant, normalised plate) so this adds at most one id.
     */
    private function idsThisVehicleMayBeKnownBy(Vehicle $vehicle): array
    {
        $ids = [(int) $vehicle->id];

        if ($vehicle->legacy_transport_vehicle_id) {
            $ids[] = (int) $vehicle->legacy_transport_vehicle_id;
        }

        foreach ($this->legacyIdsWithThisPlate($vehicle) as $id) {
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /** The legacy row for this truck, found by plate rather than by a stored id. */
    private function legacyIdsWithThisPlate(Vehicle $vehicle): array
    {
        if (! $this->legacyMasterExists()) {
            return [];
        }

        return DB::table('transport_vehicles')
            ->where('tenant_id', $vehicle->company_id)
            ->where('registration_normalized', $this->normalisePlate($vehicle->registration_number))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The plate this id means in the legacy master, or null if it means nothing.
     *
     * Deliberately one table. Asking Fleet's table what a Fleet id's plate is,
     * to check whether it is this Fleet vehicle, is a question that answers
     * itself — which is the whole of the second half of D-116.
     */
    private function legacyPlateFor(int $vehicleId, int $companyId): ?string
    {
        if (! $this->legacyMasterExists()) {
            return null;
        }

        $plate = DB::table('transport_vehicles')
            ->where('tenant_id', $companyId)
            ->where('id', $vehicleId)
            ->value('registration_number');

        return $plate ? $this->normalisePlate($plate) : null;
    }

    /** What `stos:repoint-trip-fleet-refs` decided about this trip, if anything. */
    private function repointVerdictFor(int $tripId): ?object
    {
        if (! $this->ledgerExists()) {
            return null;
        }

        return DB::table('fleet_reference_repoints')
            ->where('table_name', 'transport_trips')
            ->where('column_name', 'vehicle_id')
            ->where('row_id', $tripId)
            // from_id as well as to_id: a verdict only applies while the trip
            // still holds the value it was written about.
            ->first(['from_id', 'to_id']);
    }

    /**
     * Has this company's trip data been moved into the Fleet id space?
     *
     * One ledger entry is enough to know: the repoint rules on every reference
     * it looks at in a single pass, so anything it left without a verdict was
     * raised afterwards and is therefore already in the new space.
     */
    private function switchHasHappenedFor(int $companyId): bool
    {
        if (! $this->ledgerExists()) {
            return false;
        }

        return $this->switched[$companyId] ??= DB::table('fleet_reference_repoints')
            ->where('company_id', $companyId)
            ->where('table_name', 'transport_trips')
            ->where('column_name', 'vehicle_id')
            ->exists();
    }

    private function legacyMasterExists(): bool
    {
        return $this->legacyMaster ??= Schema::hasTable('transport_vehicles');
    }

    private function ledgerExists(): bool
    {
        return $this->ledger ??= Schema::hasTable('fleet_reference_repoints');
    }

    /** Plates are written "MH 12 AB 1234" as often as "MH12AB1234". */
    private function normalisePlate(?string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $plate));
    }

    private function recorder(): TripEventRecorder
    {
        return app(TripEventRecorder::class);
    }

    public function available(): bool
    {
        return class_exists(TripEventRecorder::class) && class_exists(TransportTrip::class);
    }
}
