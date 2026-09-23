<?php

namespace App\Domains\Fleet\Services;

use App\Domains\Fleet\Models\FastagTransaction;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\UreaTransaction;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Exceptions\BusinessException;
use Illuminate\Support\Collection;

/**
 * STOS-FLEET — what the Fleet control tower reads.
 *
 * Every query is scoped with ->forCompany() by hand (golden rule 1); there is
 * no global scope and no safety net.
 *
 * The status grid and the passport are built here rather than in the
 * controller, and the traffic light comes from VehicleHealthEvaluator rather
 * than from each screen's own idea of "red".
 */
class FleetService
{
    /** Job-card states that mean the vehicle is still in the workshop. */
    private const OPEN_JOB_STATES = ['open', 'in_progress', 'awaiting_parts'];

    public function __construct(
        private VehicleHealthEvaluator $health,
        private VehicleAllocationService $allocation,
        private ComplianceService $compliance,
        private TyreService $tyres,
        private DriverService $drivers,
        private MaintenanceService $maintenance,
        private ServiceScheduleEvaluator $schedule,
    ) {
    }

    /* ── Published service contracts (STOS-TM-001) ──────────────────
     |
     | The two methods other developers call BY NAME. Their signatures are the
     | integration surface: renaming one silently breaks another developer's
     | module, so they are thin, documented wrappers, safe to keep stable while
     | the implementation behind them moves.
     */

    /**
     * Vehicles that may take a job, best first. Consumed by Developer 1
     * (Operations) during dispatch planning.
     *
     * @param  string|null  $vehicleType  'REEFER' or 'reefer' — either case
     * @param  array  $context  optional pickup_lat / pickup_lng, to rank on proximity
     */
    public function getEligibleVehicles(int $companyId, ?string $vehicleType = null, array $context = []): array
    {
        return $this->allocation->eligible($companyId, array_filter([
            'vehicle_type' => $vehicleType,
            // PLN-001. Pass the order's `required_capacity_tonnes` straight
            // through — a vehicle that cannot carry the load is excluded with
            // `below_required_capacity`, and one with no payload recorded is
            // kept but flagged `capacity_unknown`.
            'required_capacity_tonnes' => $context['required_capacity_tonnes'] ?? null,
            'pickup_lat'   => $context['pickup_lat'] ?? null,
            'pickup_lng'   => $context['pickup_lng'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Everything this trip has cost to run. Consumed by Developer 3 (Finance)
     * for trip P&L and billing readiness.
     *
     * `$companyId` is optional ONLY so the documented single-argument call
     * works; it resolves from the session when omitted. A trip id alone is not
     * a tenancy boundary — with neither available this refuses rather than
     * summing across workspaces.
     */
    public function getTripOperatingCosts(int $tripId, ?int $companyId = null): array
    {
        $companyId = $companyId ?? Vehicle::currentCompanyId();

        if (! $companyId) {
            throw new BusinessException('Trip costs cannot be read without a company context.', 403);
        }

        $fuel  = fn () => FuelTransaction::forCompany($companyId)->where('trip_id', $tripId);
        $urea  = fn () => UreaTransaction::forCompany($companyId)->where('trip_id', $tripId);
        $tolls = fn () => FastagTransaction::forCompany($companyId)->where('trip_id', $tripId);
        // Maintenance attributable to THIS trip — a breakdown repair carries the
        // trip it happened on. Routine servicing has no trip_id and is fleet
        // overhead, not a trip cost; apportioning that per kilometre is a
        // costing policy for Finance to set, not for the fleet domain to invent.
        $jobs  = fn () => MaintenanceJob::forCompany($companyId)->where('trip_id', $tripId);

        $lines = [
            'fuel'        => $this->exact($fuel()->sum('amount')),
            'urea'        => $this->exact($urea()->sum('amount')),
            'tolls'       => $this->exact($tolls()->sum('amount')),
            'maintenance' => $this->exact($jobs()->sum('total_cost')),
        ];

        return [
            'trip_id'    => $tripId,
            'company_id' => $companyId,
            'currency'   => 'INR',
            'lines'      => $lines,
            'total'      => $this->exact(array_sum(array_map('floatval', $lines))),
            'counts'     => [
                'fuel'        => $fuel()->count(),
                'urea'        => $urea()->count(),
                'tolls'       => $tolls()->count(),
                'maintenance' => $jobs()->count(),
            ],
            // Emergency diesel is the line Finance must rule on before a trip
            // can be billed (STOS-DOC billing readiness).
            'emergency_fuel' => [
                'amount'    => $this->exact($fuel()->where('is_emergency', true)->sum('amount')),
                'billable'  => $this->exact($fuel()->where('recovery_status', 'billable')->sum('amount')),
                'undecided' => $fuel()->where('is_emergency', true)->where('recovery_status', 'pending')->count(),
            ],
        ];
    }

    /**
     * The vehicle status grid: every vehicle with its live reading, its verdict
     * and its next action.
     */
    public function grid(int $companyId, array $filters = []): array
    {
        $vehicles = Vehicle::forCompany($companyId)->orderBy('registration_number')->get();

        $live = $this->liveByVehicle($companyId, $vehicles->pluck('id'));
        $openJobs = $this->openJobCountsByVehicle($companyId, $vehicles->pluck('id'));

        $rows = $vehicles->map(function (Vehicle $v) use ($live, $openJobs) {
            $l = $live[$v->id] ?? null;
            $jobs = (int) ($openJobs[$v->id] ?? 0);

            return [
                'id'                  => $v->id,
                'registration_number' => $v->registration_number,
                'vehicle_type'        => $v->vehicle_type,
                'ownership_type'      => $v->ownership_type,
                'status'              => $v->status,
                'compliance_status'   => $v->compliance_status,
                'gps_device_id'       => $v->gps_device_id,
                'open_jobs'           => $jobs,
                // Reefer-only readings stay null on a dry vehicle, so the UI can
                // hide the fields instead of showing empty dials.
                'live'                => $this->livePayload($v, $l),
                'health'              => $this->health->evaluate($v, $l, $jobs),
            ];
        });

        // Tiles first, THEN filtering: the counts must describe the whole fleet,
        // not whatever slice is on screen.
        $tiles = $this->tiles($rows);

        if ($state = ($filters['state'] ?? null)) {
            $rows = $rows->where('health.state', $state)->values();
        }

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $rows = $rows->filter(function (array $r) use ($term) {
                return stripos($r['registration_number'], $term) !== false
                    || stripos((string) $r['gps_device_id'], $term) !== false;
            })->values();
        }

        return [
            'tiles'    => $tiles,
            'vehicles' => $rows->values()->all(),
            'stale_after_minutes' => VehicleHealthEvaluator::STALE_PING_MINUTES,
        ];
    }

    /**
     * The Digital Vehicle Passport: one screen that answers everything about
     * one asset, so nobody opens five tabs to ask "is this truck alright".
     */
    /**
     * @param  int|string  $key  numeric id, or the registration number a person
     *                           read off the number plate
     */
    public function passport($key, int $companyId): array
    {
        $vehicle = $this->resolveVehicle($key, $companyId);

        $live = VehicleLiveStatus::forCompany($companyId)->where('vehicle_id', $vehicle->id)->first();
        $openJobs = MaintenanceJob::forCompany($companyId)
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', self::OPEN_JOB_STATES)
            ->count();

        $fuel = FuelTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)
            ->orderByDesc('id')->limit(10)->get();

        $tolls = FastagTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)
            ->orderByDesc('transaction_timestamp')->limit(10)->get();

        // With the lines eager-loaded, the passport can justify the money on a
        // card item by item instead of showing a total and asking for trust.
        $jobs = MaintenanceJob::forCompany($companyId)->where('vehicle_id', $vehicle->id)
            ->with(['parts', 'labour'])
            ->orderByDesc('id')->limit(20)->get();

        $urea = UreaTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)
            ->orderByDesc('id')->limit(10)->get();

        return [
            'vehicle' => $vehicle->toArray(),
            'health'  => $this->health->evaluate($vehicle, $live, $openJobs),
            'live'    => $this->livePayload($vehicle, $live),
            'signal'  => $this->signalHealth($live?->last_ping_at),

            'gensets' => Genset::forCompany($companyId)->where('vehicle_id', $vehicle->id)
                ->get(['id', 'serial_number', 'status'])->all(),

            // The recent trail, newest first. Capped: this table is unbounded
            // and a passport screen must never try to render all of it.
            'telemetry' => TelemetryRecord::forCompany($companyId)->where('vehicle_id', $vehicle->id)
                ->orderByDesc('recorded_at')->limit(50)
                ->get(['id', 'latitude', 'longitude', 'speed', 'ignition', 'generator_status', 'temperature', 'recorded_at'])
                ->all(),

            'fuel' => [
                'recent'       => $fuel->all(),
                'total_litres' => $this->exact(FuelTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('litres'), 3),
                'total_amount' => $this->exact(FuelTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('amount')),
                'last_odometer' => $this->exact($fuel->firstWhere('odometer', '!=', null)?->odometer ?? 0, 1),
            ],

            'tolls' => [
                'recent'       => $tolls->all(),
                'total_amount' => $this->exact(FastagTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('amount')),
                'unreconciled' => FastagTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)
                    ->where('reconciliation_status', 'unreconciled')->count(),
            ],

            // T-04 — derived, never a status. A truck past its interval is
            // still roadworthy; this warns so a service can be booked rather
            // than discovered.
            'service' => $this->schedule->evaluate($vehicle),

            // The pre-dispatch gate's evidence: five documents, each with its
            // own date and verdict, rather than one rolled-up flag.
            'compliance' => $this->compliance->evaluate($vehicle),

            // Who normally drives this, resolved through the directory so the
            // screen shows a name — and their licence, judged the same way the
            // vehicle's own papers are.
            'driver' => $this->drivers->forVehicle($vehicle->id, $companyId),

            // T-22 — the band travels with the readings. The service has always
            // logged an out-of-band top-up and no screen ever showed it, which
            // made the check invisible to the only people who can act on it.
            'urea' => [
                'recent' => $urea->all(),
                'total_litres' => $this->exact(UreaTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('litres'), 3),
                'total_amount' => $this->exact(UreaTransaction::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('amount')),
                'band' => [
                    'min'  => UreaService::EXPECTED_MIN,
                    'max'  => UreaService::EXPECTED_MAX,
                    'unit' => 'L/100km',
                ],
                'exceptions' => $urea->filter(fn ($u) => $u->outside_band === true)->count(),
            ],

            'tyres' => $this->tyres->forVehicle($vehicle->id, $companyId),

            'workshop' => [
                'jobs'       => $jobs->all(),
                'open_count' => $openJobs,
                'total_cost' => $this->exact(MaintenanceJob::forCompany($companyId)->where('vehicle_id', $vehicle->id)->sum('total_cost')),
                // T-33 — what the workshop cost in availability. The repair
                // bill appears on an invoice; the days off the road never do,
                // and they are usually the larger number.
                'downtime'   => $this->maintenance->downtimeFor($vehicle->id, $companyId),
                // A condemnation outlives its own card, so the screen has to be
                // able to name which card is still holding the vehicle.
                'condemnation' => $this->maintenance->condemnationFor($vehicle->id, $companyId),
            ],
        ];
    }

    /* ── helpers ────────────────────────────────────────────────── */

    /**
     * Find a vehicle by id or plate, within the company.
     *
     * Plates get written "MH 12 AB 1234" as often as "MH12AB1234", so the
     * lookup normalises the same way the register stores them — otherwise the
     * passport 404s on a number the user can plainly see on the truck.
     */
    private function resolveVehicle($key, int $companyId): Vehicle
    {
        $query = Vehicle::forCompany($companyId);

        if (ctype_digit((string) $key)) {
            $vehicle = $query->find((int) $key);
        } else {
            // Plates are written every way a person can write them:
            // "MH12AB1234", "MH 12 AB 1234", "MH-12-AB-1234" — and a space in a
            // URL path arrives as "+", which is NOT decoded the way it is in a
            // query string. So BOTH sides are stripped to letters and digits
            // before comparing; matching the raw column would 404 on a number
            // the user can plainly read off the truck.
            $plate = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $key));

            $vehicle = $query->whereRaw(
                "UPPER(REPLACE(REPLACE(REPLACE(registration_number, ' ', ''), '-', ''), '+', '')) = ?",
                [$plate]
            )->first();
        }

        if (! $vehicle) {
            throw new BusinessException('That vehicle is not in your fleet.', 404);
        }

        return $vehicle;
    }

    /**
     * GPS signal health for the gauge: active | degraded | offline.
     *
     * Three states rather than two, because "the last fix is twenty minutes
     * old" is neither fine nor dead, and a driver in a valley should not look
     * identical to a unit that has been ripped out.
     */
    public function signalHealth($lastPingAt): string
    {
        if (! $lastPingAt) {
            return 'offline';
        }

        $minutes = (int) config('stos.telemetry.stale_ping_minutes', VehicleHealthEvaluator::STALE_PING_MINUTES);
        $age = \Illuminate\Support\Carbon::parse($lastPingAt)->diffInMinutes(now());

        if ($age >= $minutes) {
            return 'offline';
        }

        return $age >= (int) ceil($minutes / 2) ? 'degraded' : 'active';
    }

    /**
     * A decimal aggregate, as a fixed-scale STRING.
     *
     * SUM() comes back through PHP as a float, and (float) 75784.30 serialises
     * as 75784.29999999999 — golden rule 5 says money never floats, and a
     * screen showing that number once is a support ticket. Eloquent's decimal
     * casts already give per-row values as strings; the totals now match them.
     */
    private function exact($value, int $scale = 2): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    /**
     * One row per state, covering every vehicle exactly once.
     *
     * `allocated` and `in_transit` are deliberately absent: those are trip
     * facts and Dispatch (Developer 1) owns trips. A tile that guessed them
     * from telemetry would call a yard shunt a delivery.
     */
    private function tiles(Collection $rows): array
    {
        $states = ['moving', 'idle', 'offline', 'maintenance', 'compliance_blocked', 'unmonitored', 'retired'];
        $counts = array_fill_keys($states, 0);

        foreach ($rows as $row) {
            $state = $row['health']['state'];
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        return [
            'total'  => $rows->count(),
            'by_state' => $counts,
            'red'    => $rows->where('health.tone', 'red')->count(),
            'amber'  => $rows->where('health.tone', 'amber')->count(),
            'green'  => $rows->where('health.tone', 'green')->count(),
        ];
    }

    /** Reefer readings stay null on a dry vehicle — progressive disclosure. */
    private function livePayload(Vehicle $vehicle, ?object $live): ?array
    {
        // A vehicle is given an EMPTY live row the moment it is onboarded, so
        // ingestion is a pure primary-key update for the rest of its life. That
        // row is not a reading: without a last_ping_at nothing has ever
        // reported, and returning it would paint a full set of gauges reading
        // "—" on a truck whose device has never been switched on.
        if (! $live || ! $live->last_ping_at) {
            return null;
        }

        $isReefer = $vehicle->vehicle_type === 'reefer';

        return [
            'latitude'     => $live->latitude,
            'longitude'    => $live->longitude,
            'speed'        => $live->speed,
            'ignition'     => $live->ignition === null ? null : (bool) $live->ignition,
            'last_ping_at' => $live->last_ping_at,
            'is_reefer'    => $isReefer,
            'generator_status' => $isReefer ? $live->generator_status : null,
            'temperature'      => $isReefer ? $live->temperature : null,
            'target_temperature' => $isReefer ? (float) config('stos.telemetry.excursion_temperature', -18.0) : null,
        ];
    }

    /** @return array<int, object> keyed by vehicle_id */
    private function liveByVehicle(int $companyId, Collection $vehicleIds): array
    {
        return VehicleLiveStatus::forCompany($companyId)
            ->whereIn('vehicle_id', $vehicleIds)
            ->get()
            ->keyBy('vehicle_id')
            ->all();
    }

    /** @return array<int, int> open job cards keyed by vehicle_id */
    private function openJobCountsByVehicle(int $companyId, Collection $vehicleIds): array
    {
        return MaintenanceJob::forCompany($companyId)
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('status', self::OPEN_JOB_STATES)
            ->selectRaw('vehicle_id, count(*) as c')
            ->groupBy('vehicle_id')
            ->pluck('c', 'vehicle_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }
}
