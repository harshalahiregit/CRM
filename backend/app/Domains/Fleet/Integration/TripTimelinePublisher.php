<?php

namespace App\Domains\Fleet\Integration;

use App\Domains\Fleet\Models\Vehicle;
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

        $this->recorder()->record(
            type: $now === 'off' ? 'genset.off' : 'genset.on',
            trip: $trip,
            occurredAt: $recordedAt,
            summary: 'Genset '.$now.' on '.$vehicle->registration_number,
            detail: [
                'vehicle_id' => $vehicle->id,
                'from' => $before, 'to' => $now,
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
     * And it gets harder after the repoint, not easier: some trips will carry
     * Fleet ids while rows whose legacy vehicle never migrated still carry the
     * old ones, so BOTH spaces will match something.
     *
     * ── SO THE ID FINDS CANDIDATES AND THE PLATE DECIDES ──────────────────
     * The registration number is the one identifier both tables agree on, in
     * both directions, before and after the repoint. The id is still used for
     * the lookup because it is indexed — it just no longer gets the last word.
     *
     * When a candidate's plate cannot be confirmed as this vehicle's, it is
     * REFUSED and logged rather than published. A wrong timeline entry naming
     * the wrong truck is worse than a missing one: the missing one is noticed.
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

        $plate = $this->normalisePlate($vehicle->registration_number);

        foreach ($candidates as $candidate) {
            if ($this->platesFor((int) $candidate->vehicle_id, (int) $vehicle->company_id) === [$plate]) {
                return TransportTrip::find($candidate->id);
            }

            Log::channel('stos')->warning('Trip matched a vehicle id but not its registration; not published', [
                'defect' => 'D-116',
                'trip_id' => $candidate->id, 'trip_vehicle_id' => $candidate->vehicle_id,
                'fleet_vehicle_id' => $vehicle->id, 'expected_plate' => $plate,
                'why' => 'transport_trips.vehicle_id is ambiguous between the two masters until the repoint completes',
            ]);
        }

        return null;
    }

    /**
     * Both ids this vehicle could be referenced by, in either space.
     *
     * The Fleet id for a repointed trip, and the legacy id it was moved from
     * for one that has not been repointed yet. `legacy_transport_vehicle_id` is
     * recorded by the data move precisely so this stays answerable.
     */
    private function idsThisVehicleMayBeKnownBy(Vehicle $vehicle): array
    {
        $ids = [$vehicle->id];

        if ($vehicle->legacy_transport_vehicle_id) {
            $ids[] = (int) $vehicle->legacy_transport_vehicle_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every plate this id resolves to, across both masters.
     *
     * One entry means the id is unambiguous. Two different plates means the
     * number means different trucks in the two tables, and nothing can tell
     * which one the trip meant — so the caller refuses.
     */
    private function platesFor(int $vehicleId, int $companyId): array
    {
        $plates = [];

        $fleet = DB::table('vehicles')->where('company_id', $companyId)
            ->where('id', $vehicleId)->value('registration_number');

        if ($fleet) {
            $plates[] = $this->normalisePlate($fleet);
        }

        if (Schema::hasTable('transport_vehicles')) {
            $legacy = DB::table('transport_vehicles')->where('tenant_id', $companyId)
                ->where('id', $vehicleId)->value('registration_number');

            if ($legacy) {
                $plates[] = $this->normalisePlate($legacy);
            }
        }

        return array_values(array_unique($plates));
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
