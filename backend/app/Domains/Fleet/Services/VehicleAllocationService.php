<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use Illuminate\Support\Collection;

/**
 * STOS-FLEET — which vehicles may take this job, best first.
 *
 * This replaces memory-based scheduling, so the ranking has to be defensible:
 * every score component is 0-1, the weights live in config, and the reason
 * strings are GENERATED FROM THE SAME NUMBERS that produced the rank. An
 * explanation that is written separately from the maths drifts from it, and a
 * recommendation nobody believes is worse than no recommendation.
 *
 * This service RECOMMENDS. It does not allocate: a trip belongs to Dispatch
 * (Developer 1), and nothing here writes an assignment.
 */
class VehicleAllocationService
{
    public function __construct(
        private DriverService $drivers,
        private ServiceScheduleEvaluator $schedule,
    ) {
    }

    /**
     * @param  array  $filters  vehicle_type, required_capacity_tonnes, pickup_lat, pickup_lng
     * @return array{eligible: array, excluded: array, weights: array}
     */
    public function eligible(int $companyId, array $filters = []): array
    {
        $query = Vehicle::forCompany($companyId);

        if ($type = ($filters['vehicle_type'] ?? null)) {
            $query->where('vehicle_type', strtolower($type));
        }

        $vehicles = $query->orderBy('registration_number')->get();

        $live = VehicleLiveStatus::forCompany($companyId)
            ->whereIn('vehicle_id', $vehicles->pluck('id'))->get()->keyBy('vehicle_id');

        $blockingJobs = $this->safetyCriticalJobCounts($companyId, $vehicles->pluck('id'));
        $coupledTrailers = $this->trailerBlockers($companyId, $vehicles->pluck('id'));
        $openJobs = $this->openJobCounts($companyId, $vehicles->pluck('id'));
        $efficiency = $this->efficiencyByVehicle($companyId, $vehicles->pluck('id'));
        $utilisation = $this->utilisationByVehicle($companyId, $vehicles->pluck('id'));
        // The driver who regularly takes each vehicle, with their licence
        // verdict already computed by DriverService — one definition of
        // "expired", shared with the drivers board and the passport.
        $assignedDrivers = $this->drivers->forVehicles($vehicles->pluck('id')->all(), $companyId);

        // T-04 — loaded in bulk. The evaluator can find a vehicle's odometer on
        // its own, but doing that inside the loop would be one query per truck
        // on the busiest read in the module.
        $odometers = FuelTransaction::forCompany($companyId)
            ->whereIn('vehicle_id', $vehicles->pluck('id'))
            ->whereNotNull('odometer')
            ->selectRaw('vehicle_id, MAX(odometer) as reading')
            ->groupBy('vehicle_id')->pluck('reading', 'vehicle_id');

        $pickupLat = isset($filters['pickup_lat']) ? (float) $filters['pickup_lat'] : null;
        $pickupLng = isset($filters['pickup_lng']) ? (float) $filters['pickup_lng'] : null;

        // PLN-001 — the order's payload. Nothing matched this before: both
        // `vehicles.capacity_tonnes` and `transport_orders.required_capacity_tonnes`
        // existed and no code compared them, so a 9-tonne load could be
        // recommended a 2-tonne van and only the loading bay would find out.
        $required = isset($filters['required_capacity_tonnes']) && $filters['required_capacity_tonnes'] !== ''
            ? (float) $filters['required_capacity_tonnes']
            : null;

        $eligible = [];
        $excluded = [];

        foreach ($vehicles as $vehicle) {
            $l = $live[$vehicle->id] ?? null;
            $blockers = $this->blockersFor($vehicle, $l, (int) ($blockingJobs[$vehicle->id] ?? 0), $required);

            // T-54 — a tractor pulling a trailer with lapsed papers cannot
            // legally go out, however clean the tractor's own file is. The
            // blocker names the TRAILER, because sending somebody to renew the
            // truck's documents over a trailer's fitness certificate is how an
            // hour gets wasted at a gate.
            if ($trailerBlock = ($coupledTrailers[$vehicle->id] ?? null)) {
                $blockers[] = $trailerBlock;
            }

            if ($blockers !== []) {
                // Excluded vehicles are RETURNED, not silently dropped. A planner
                // asking "why isn't MH-04 on the list" must get an answer here
                // rather than from someone's memory.
                $excluded[] = [
                    'id' => $vehicle->id,
                    'registration_number' => $vehicle->registration_number,
                    'vehicle_type' => $vehicle->vehicle_type,
                    'blockers' => $blockers,
                ];

                continue;
            }

            $distanceKm = $this->distanceKm($l, $pickupLat, $pickupLng);
            $kmpl = $efficiency[$vehicle->id] ?? null;
            $recentKm = (float) ($utilisation[$vehicle->id] ?? 0);

            $driver = $assignedDrivers[$vehicle->id] ?? null;

            // A service verdict WARNS; it never excludes. A truck past its
            // interval is still roadworthy, and taking it off the road over an
            // oil change is the wrong trade — see ServiceScheduleEvaluator.
            $odometer = isset($odometers[$vehicle->id]) ? (float) $odometers[$vehicle->id] : null;
            $service = $this->schedule->evaluate($vehicle, $odometer);

            $flags = $this->driverFlags($driver);

            if ($serviceFlag = $this->schedule->flag($vehicle, $odometer)) {
                $flags[] = $serviceFlag;
            }

            if ($capacityFlag = $this->capacityFlag($vehicle, $required)) {
                $flags[] = $capacityFlag;
            }

            $scores = [
                'proximity'   => $this->proximityScore($distanceKm),
                'efficiency'  => $this->efficiencyScore($vehicle, $kmpl),
                'utilisation' => $this->utilisationScore($recentKm),
                // Whether the regular driver is free is a convenience of the
                // pairing, not a property of the truck. A licence, by contrast,
                // no longer touches this score at all — see driverScore().
                'driver'      => $this->driverScore($driver),
            ];

            $eligible[] = [
                'id'                  => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'vehicle_type'        => $vehicle->vehicle_type,
                'ownership_type'      => $vehicle->ownership_type,
                // Shown whether or not an order asked for a payload: a planner
                // choosing between two trucks wants the number, and a null here
                // is the prompt to go and record it.
                'capacity_tonnes'     => $vehicle->capacity_tonnes === null ? null : (float) $vehicle->capacity_tonnes,
                'compliance_status'   => $vehicle->compliance_status,
                'open_jobs'           => (int) ($openJobs[$vehicle->id] ?? 0),
                'live'                => $l ? [
                    'latitude' => $l->latitude, 'longitude' => $l->longitude,
                    'last_ping_at' => $l->last_ping_at,
                ] : null,
                'distance_km'     => $distanceKm === null ? null : round($distanceKm, 1),
                'efficiency_kmpl' => $kmpl === null ? null : round($kmpl, 2),
                'recent_km'       => round($recentKm, 1),
                // A component we could not measure stays null, never 0 — the UI
                // shows "not measured", and round(null) is deprecated anyway.
                'scores'          => array_map(fn ($v) => $v === null ? null : round($v, 3), $scores),
                'score'           => round($this->weighted($scores) * 100),
                'reasons'         => $this->reasons($vehicle, $distanceKm, $kmpl, $recentKm, $driver),
                // Driver compliance travels beside vehicle compliance, so a
                // planner sees both halves of "can this go out today".
                'driver'          => $driver,
                'flags'           => $flags,
                'service'         => $service,
            ];
        }

        usort($eligible, fn ($a, $b) => $b['score'] <=> $a['score']);

        // The top card's headline. Built from the winner's own reasons so it
        // cannot claim something the ranking did not use.
        if ($eligible !== []) {
            $top = $eligible[0];
            $eligible[0]['recommended'] = true;
            $eligible[0]['recommendation'] = 'Recommended: '.$top['registration_number'].' is '
                .implode(', ', array_map(fn ($r) => lcfirst($r), $top['reasons'])).'.';
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
            'weights'  => config('stos.allocation.weights'),
        ];
    }

    /* ── eligibility ────────────────────────────────────────────── */

    /**
     * Why this vehicle cannot take the job. Same four-part shape as the health
     * evaluator's issues, so the allocation modal and the fleet board explain
     * a block identically.
     */
    private function blockersFor(Vehicle $vehicle, ?object $live, int $safetyJobs, ?float $required = null): array
    {
        $blockers = [];

        // ── CAPACITY IS ORDER-RELATIVE, WHICH IS WHY IT LIVES HERE ────────
        // Every other blocker is a fact about the truck; this one is a fact
        // about the truck AND this job. A 9-tonne vehicle is not "blocked" —
        // it is simply too small for THIS load and fine for the next one. So
        // it excludes the vehicle from this answer and touches nothing on the
        // row, exactly as `status` must not be written for a condition.
        //
        // An unknown capacity does NOT block. Most of the fleet predates the
        // column being filled, and excluding every unmeasured truck would
        // empty the list while looking like a considered verdict — the same
        // silent-empty shape as D-116. It warns instead; see `capacityFlag()`.
        if ($required !== null && $vehicle->capacity_tonnes !== null
            && (float) $vehicle->capacity_tonnes < $required) {
            $blockers[] = $this->blocker(
                'below_required_capacity',
                'Too small for this load.',
                'Carries '.rtrim(rtrim(number_format((float) $vehicle->capacity_tonnes, 2), '0'), '.')
                    .' t; the order needs '.rtrim(rtrim(number_format($required, 2), '0'), '.').' t.',
                'Operations planner'
            );
        }

        if ($vehicle->status === Vehicle::STATUS_RETIRED) {
            $blockers[] = $this->blocker('retired', 'Retired from the fleet.', 'It is no longer an operating asset.', 'Fleet manager');
        }

        if (in_array($vehicle->compliance_status, ['expired', 'blocked'], true)) {
            $blockers[] = $this->blocker(
                'compliance_blocked',
                'Papers are not valid.',
                $vehicle->compliance_status === 'expired' ? 'A statutory document has expired.' : 'A compliance hold is in force.',
                'Fleet compliance desk'
            );
        }

        if ($safetyJobs > 0) {
            $blockers[] = $this->blocker(
                'safety_job_open',
                'Open safety-critical job card.',
                $safetyJobs.' safety '.($safetyJobs === 1 ? 'job is' : 'jobs are').' still open in the workshop.',
                'Workshop supervisor'
            );
        }

        if ($vehicle->status === Vehicle::STATUS_UNDER_MAINTENANCE) {
            $blockers[] = $this->blocker('in_maintenance', 'Currently in the workshop.', 'The vehicle is marked under maintenance.', 'Workshop supervisor');
        }

        // T-04 — kept separate from in_maintenance on purpose. A planner
        // reading "in the workshop" assumes a slot and a return time; a
        // breakdown means the truck is somewhere on a road with a load on it.
        if ($vehicle->status === Vehicle::STATUS_BREAKDOWN) {
            $blockers[] = $this->blocker(
                'broken_down',
                'Broken down on the road.',
                'A breakdown job card is open against this vehicle.',
                'Operations control tower'
            );
        }

        // PLN-006 — prevent double allocation. A vehicle that departed on
        // another trip is not available for this one, however compliant it is.
        if (in_array($vehicle->status, Vehicle::ON_TRIP_STATES, true)) {
            $blockers[] = $this->blocker(
                'on_another_trip',
                'Already out on a trip.',
                'The vehicle was dispatched and has not been released yet.',
                'Operations control tower'
            );
        }

        // A vehicle nobody can see is a vehicle nobody should promise. It is a
        // WARNING rather than a hard block only when it has never been fitted
        // with a device — a fitted device gone quiet is the more worrying case.
        if ($vehicle->gps_device_id && (! $live || ! $live->last_ping_at
            || \Illuminate\Support\Carbon::parse($live->last_ping_at)->lt(now()->subMinutes(VehicleHealthEvaluator::STALE_PING_MINUTES)))) {
            $blockers[] = $this->blocker(
                'telemetry_stale',
                'Position unknown.',
                'The fitted device has not reported recently, so we cannot say where it is.',
                'Telemetry lead'
            );
        }

        return $blockers;
    }

    /**
     * Vehicles whose coupled trailer would stop the combination — T-54.
     *
     * Loaded in bulk: this runs on the busiest read in the module, and one
     * query per truck to find a trailer that usually is not there would be a
     * poor trade.
     *
     * Only a BLOCKED trailer produces an entry. A trailer whose papers merely
     * expire soon is a warning on the trailer register, not a reason to refuse
     * a truck today.
     *
     * @return array<int,array>  vehicle id => blocker
     */
    private function trailerBlockers(int $companyId, Collection $vehicleIds): array
    {
        if ($vehicleIds->isEmpty() || ! \Illuminate\Support\Facades\Schema::hasTable('vehicle_trailer_assignments')) {
            return [];
        }

        $rows = \App\Domains\Fleet\Models\VehicleTrailerAssignment::forCompany($companyId)
            ->open()
            ->whereIn('vehicle_id', $vehicleIds)
            ->with('trailer:id,trailer_number,status,compliance_status')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $trailer = $row->trailer;

            if (! $trailer || $trailer->status !== \App\Domains\Fleet\Models\Trailer::STATUS_COMPLIANCE_BLOCKED) {
                continue;
            }

            $out[(int) $row->vehicle_id] = $this->blocker(
                'trailer_compliance_blocked',
                'The coupled trailer is not road legal.',
                $trailer->trailer_number.' has a lapsed document, so the combination cannot go out.',
                'Fleet compliance desk'
            );
        }

        return $out;
    }

    private function blocker(string $code, string $why, string $missing, string $owner): array
    {
        return ['code' => $code, 'why' => $why, 'missing' => $missing, 'owner' => $owner];
    }

    /**
     * A truck offered for a load nobody has measured it against.
     *
     * The honest thing to say is "we do not know", not silence and not a
     * refusal. Silence lets a planner assume it fits; a refusal takes a
     * perfectly good truck off the list over a missing number. So the vehicle
     * stays eligible and carries the doubt with it.
     *
     * Lower-case snake_case, per the ruled naming standard for machine reasons.
     */
    private function capacityFlag(Vehicle $vehicle, ?float $required): ?string
    {
        if ($required === null || $vehicle->capacity_tonnes !== null) {
            return null;
        }

        return 'capacity_unknown';
    }


    /* ── driver compliance (STOS-CMP) ───────────────────────────── */

    /**
     * How much the assigned driver helps this pairing.
     *
     * Deliberately a SCORE and not an exclusion: the vehicle is roadworthy
     * either way, and swapping the driver is a smaller decision than standing
     * the truck down. A planner sees the flag and either re-assigns or picks
     * the next vehicle.
     */
    /**
     * How convenient is this truck's regular pairing — NOT how compliant.
     *
     * ── WHY THE LICENCE IS NOT IN HERE ────────────────────────────────────
     * It used to be: an expired licence scored the vehicle to zero. Person 1
     * pointed out that is the wrong object. A licence belongs to the driver,
     * not the truck. The truck is roadworthy and nothing about it has expired,
     * so it stays fully eligible and a different driver takes it — the licence
     * is a hard block on the DRIVER, raised by DriverService::eligible().
     *
     * Scoring the truck down meant a dispatcher was quietly offered a worse
     * vehicle because of a paperwork problem that a two-second driver swap
     * fixes. What remains here is only whether the regular driver is free,
     * which is a genuine convenience of the pairing.
     */
    private function driverScore(?array $driver): float
    {
        if (! $driver) {
            // No regular driver is normal in a yard where whoever is free takes
            // the next load. Neutral, not a penalty.
            return 0.5;
        }

        return ($driver['profile']['status'] ?? DriverProfile::AVAILABLE) === DriverProfile::AVAILABLE ? 1.0 : 0.5;
    }

    /**
     * Machine-readable reasons a pairing is imperfect.
     *
     * lowercase snake_case, per the naming standard ruled 2026-09-19: DATABASE
     * enums and state-machine states are UPPERCASE, while machine reasons
     * returned in an API payload are lowercase. These are the second kind —
     * they are not states a vehicle is in, they are reasons attached to a
     * response — so they match `safety_job_open` and `on_another_trip` rather
     * than `IN_TRANSIT`.
     */
    private function driverFlags(?array $driver): array
    {
        if (! $driver) {
            return ['no_driver_assigned'];
        }

        $flags = [];

        // Licence state is deliberately absent. It is a fact about the person,
        // and attaching it here made a dispatch board stand down a perfectly
        // good truck. It is returned against the driver instead, as a blocker.
        if (($driver['profile']['status'] ?? DriverProfile::AVAILABLE) !== DriverProfile::AVAILABLE) {
            $flags[] = 'driver_unavailable';
        }

        return $flags;
    }

    /* ── scoring ────────────────────────────────────────────────── */

    private function weighted(array $scores): float
    {
        $weights = config('stos.allocation.weights');
        $total = 0.0;
        $sum = 0.0;

        foreach ($scores as $key => $value) {
            $w = (float) ($weights[$key] ?? 0);
            // A component we cannot measure is skipped, not scored zero —
            // otherwise a vehicle with no fuel history always loses to one with
            // a single bad fill.
            if ($value === null) {
                continue;
            }
            $sum += $w * $value;
            $total += $w;
        }

        return $total > 0 ? $sum / $total : 0.0;
    }

    /** 1.0 at the pickup, falling to 0 at the configured useful range. */
    private function proximityScore(?float $distanceKm): ?float
    {
        if ($distanceKm === null) {
            return null;
        }

        $max = (float) config('stos.allocation.max_useful_distance_km', 400);

        return max(0.0, 1.0 - min($distanceKm, $max) / $max);
    }

    /** How this vehicle's real consumption compares with its type's benchmark. */
    private function efficiencyScore(Vehicle $vehicle, ?float $kmpl): ?float
    {
        if ($kmpl === null) {
            return null;
        }

        $benchmark = (float) (config('stos.fuel.benchmark_kmpl')[$vehicle->vehicle_type] ?? 0);

        if ($benchmark <= 0) {
            return null;
        }

        // Capped at 1: beating the benchmark by miles usually means a short
        // odometer gap, not a miraculous engine.
        return min(1.0, $kmpl / $benchmark);
    }

    /** Least-recently-worked scores highest, so wear spreads across the fleet. */
    private function utilisationScore(float $recentKm): float
    {
        $ceiling = 3000.0;   // a hard week for one vehicle

        return max(0.0, 1.0 - min($recentKm, $ceiling) / $ceiling);
    }

    /** Human sentences, generated from the same numbers the score used. */
    private function reasons(Vehicle $vehicle, ?float $distanceKm, ?float $kmpl, float $recentKm, ?array $driver = null): array
    {
        $reasons = ['Available and compliant'];

        if ($driver) {
            $state = $driver['licence']['state'] ?? 'unknown';
            $reasons[] = match ($state) {
                'valid'    => 'Driver '.$driver['name'].' is licensed',
                'expiring' => 'Driver '.$driver['name'].' has a licence expiring soon',
                'unknown'  => 'Driver '.$driver['name'].' has no licence recorded',
                default    => 'Driver '.$driver['name'].' has an EXPIRED licence',
            };
        } else {
            $reasons[] = 'No regular driver assigned';
        }

        if ($distanceKm !== null) {
            $reasons[] = $distanceKm <= 25
                ? 'Near the pickup ('.round($distanceKm, 1).' km)'
                : round($distanceKm, 1).' km from the pickup';
        }

        if ($kmpl !== null) {
            $benchmark = (float) (config('stos.fuel.benchmark_kmpl')[$vehicle->vehicle_type] ?? 0);
            if ($benchmark > 0) {
                $reasons[] = $kmpl >= $benchmark
                    ? 'Running at or better than benchmark ('.round($kmpl, 2).' km/l)'
                    : 'Running below benchmark ('.round($kmpl, 2).' of '.$benchmark.' km/l)';
            }
        }

        $reasons[] = $recentKm <= 0
            ? 'Not used in the last week'
            : 'Lightly used this week ('.round($recentKm).' km)';

        return $reasons;
    }

    /* ── data ───────────────────────────────────────────────────── */

    /** Great-circle distance. Good enough to rank by; not a routing engine. */
    private function distanceKm(?object $live, ?float $lat, ?float $lng): ?float
    {
        if (! $live || $lat === null || $lng === null || $live->latitude === null || $live->longitude === null) {
            return null;
        }

        $earth = 6371.0;
        $dLat = deg2rad((float) $live->latitude - $lat);
        $dLng = deg2rad((float) $live->longitude - $lng);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad((float) $live->latitude)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array<int, float> average km/l from recorded fills */
    private function efficiencyByVehicle(int $companyId, Collection $ids): array
    {
        return FuelTransaction::forCompany($companyId)
            ->whereIn('vehicle_id', $ids)
            ->whereNotNull('efficiency_kmpl')
            ->selectRaw('vehicle_id, AVG(efficiency_kmpl) as e')
            ->groupBy('vehicle_id')
            ->pluck('e', 'vehicle_id')
            ->map(fn ($e) => (float) $e)
            ->all();
    }

    /**
     * Kilometres covered recently, from the odometer readings on fills.
     *
     * Telemetry distance would be better but means summing a trail of points
     * per vehicle; the odometer is already recorded, exact, and cheap to read.
     */
    private function utilisationByVehicle(int $companyId, Collection $ids): array
    {
        $since = now()->subDays((int) config('stos.allocation.utilisation_days', 7));

        return FuelTransaction::forCompany($companyId)
            ->whereIn('vehicle_id', $ids)
            ->where('created_at', '>=', $since)
            ->whereNotNull('km_driven')
            ->selectRaw('vehicle_id, SUM(km_driven) as km')
            ->groupBy('vehicle_id')
            ->pluck('km', 'vehicle_id')
            ->map(fn ($k) => (float) $k)
            ->all();
    }

    private function safetyCriticalJobCounts(int $companyId, Collection $ids): array
    {
        return MaintenanceJob::forCompany($companyId)
            ->whereIn('vehicle_id', $ids)
            ->where('is_safety_critical', true)
            ->whereIn('status', MaintenanceJob::OPEN_STATES)
            ->selectRaw('vehicle_id, count(*) as c')
            ->groupBy('vehicle_id')->pluck('c', 'vehicle_id')->map(fn ($c) => (int) $c)->all();
    }

    private function openJobCounts(int $companyId, Collection $ids): array
    {
        return MaintenanceJob::forCompany($companyId)
            ->whereIn('vehicle_id', $ids)
            ->whereIn('status', MaintenanceJob::OPEN_STATES)
            ->selectRaw('vehicle_id, count(*) as c')
            ->groupBy('vehicle_id')->pluck('c', 'vehicle_id')->map(fn ($c) => (int) $c)->all();
    }
}
