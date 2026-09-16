<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Services\Transport\ConsignmentService;
use App\Services\Transport\ContainerService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportOrderService;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TransportVehicleService;
use App\Services\Transport\TripAssignmentService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Database\Seeder;

/**
 * THE walkthrough: 2 drivers, 2 vehicles, 2 trips. Nothing else.
 *
 * Replaces TransportConsignmentDemoSeeder, which produced more rows than a
 * person can hold in their head. The point of a demo is that someone can follow
 * it, not that every field has been exercised.
 *
 * ── THE TWO TRIPS ARE DIFFERENT ON PURPOSE ───────────────────────────────
 *   Trip 1  crewed and moving, with a planned arrival in the future. This is
 *           what makes "back in N days" visible on the allocation panel.
 *   Trip 2  approved and waiting for a vehicle and a driver. This is the trip
 *           you allocate during the demo, and the reason the search box and the
 *           "why is it busy" sentences have anything to show.
 *
 * Trip 1 holds one of the two vehicles and one of the two drivers, so when you
 * open Trip 2 the other candidates are free and the committed ones explain
 * themselves. With two of each, the demo shows both halves at once.
 *
 * ── IT WRITES FLEET ROWS, AND IT TOUCHES NO FLEET CODE ───────────────────
 * PERSON 2, READ THIS: this seeder creates rows in `transport_vehicles` and
 * `transport_drivers`. That is demo DATA, authorised by the owner, and it goes
 * through your own TransportVehicleService and TransportDriverService exactly
 * as fifteen existing test files already do. It changes no Fleet form, service,
 * validation rule or business rule, and it never writes those tables directly.
 * If you find fleet rows you did not expect, they are named
 * "SANGOE DEMO ..." / registration "MH 12 DEMO ..." and this is where they
 * came from.
 *
 * It does NOT delete any vehicle or driver, including ones left over from
 * earlier testing. Creating demo rows was authorised; removing somebody else's
 * was not. See the report line at the end of run() for what remains.
 *
 * ── SAFE TO RUN TWICE, AND REVERSIBLE ────────────────────────────────────
 * Clearing is a SOFT delete scoped to one tenant and to three tables —
 * transport_orders, transport_trips, transport_consignments. Never a truncate,
 * never another tenant, never a table outside this module. Everything it
 * removes can be brought back:
 *
 *   TransportOrder::onlyTrashed()->restore();   // and Trip, Consignment
 *
 * Re-running is a reset, not a duplication: it clears first, then rebuilds, so
 * the walkthrough is identical every time.
 *
 * ── IT DOES NOT, IN TWO PLACES. READ D-58 BEFORE TRUSTING THIS DEMO. ─────
 * The two `forceFill(['status' => ...])` calls below — TripStatus::ALLOCATED
 * and TripStatus::APPROVED — BYPASS TripStatus::TRANSITIONS. They are there
 * because `viability_pending` has NO OUTGOING EDGE: no route, no service method
 * and no state-machine entry approves a trip, so the application itself cannot
 * produce an approved trip at all.
 *
 * THIS SEEDER IS THEREFORE NOT EVIDENCE THAT THE DISPATCH CHAIN WORKS. The
 * allocation panel, pre-trip gate and dispatch screens are reachable in the demo
 * only because these two lines put the trip into a state a real user cannot
 * reach. SNG-TRN-008 (Trip Viability) was never built. See D-58.
 *
 * Both calls are to be deleted as soon as a real approval path exists, and the
 * demo trips rebuilt by walking the same transitions a user walks.
 *
 * ── EVERY ROW GOES THROUGH A REAL SERVICE ────────────────────────────────
 * Numbering, audit rows and refusals are the real ones. A seeder that wrote
 * models directly could produce a row the application itself could not, and
 * then the demo would be showing something that cannot happen.
 *
 * Run:  php artisan db:seed --class=TransportDemoSeeder
 */
class TransportDemoSeeder extends Seeder
{
    private const VEHICLES = [
        ['MH 12 DEMO 01', 'Trailer 40ft', 25.0],
        ['MH 14 DEMO 02', 'Trailer 20ft', 18.0],
    ];

    private const DRIVERS = [
        ['SANGOE DEMO Ramesh Kumar', 'RJ14 2019 0011221', 'HMV'],
        ['SANGOE DEMO Suresh Patil', 'MH12 2020 0033445', 'HMV'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();

        if (! $tenant) {
            $this->command?->warn('No tenant found — run the main seeder first.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $actor    = User::where('tenant_id', $tenantId)->orderBy('id')->first();

        $cleared = $this->clearPreviousDemo($tenantId);

        [$vehicles, $drivers] = $this->fleet($tenantId, $actor);
        $customerId           = $this->customerId($tenantId);

        $moving  = $this->buildMovingTrip($tenantId, $actor, $customerId, $vehicles[0], $drivers[0]);
        $waiting = $this->buildWaitingTrip($tenantId, $actor, $customerId);

        // One container on the moving trip's consignment, so the Containers
        // screen and the §7 history have something real to show. Entered with
        // dashes deliberately: it demonstrates that the stored value is what was
        // typed while the match is on the normalised key.
        $this->attachDemoContainer($tenantId, $actor, (int) $moving->consignment_id);

        $this->report($tenantId, $cleared, $moving, $waiting);
    }

    /**
     * Soft-delete every order, trip and consignment for this tenant.
     *
     * Deliberately not filtered to a DEMO- prefix. In this workspace those three
     * tables contain nothing but development artefacts — orders numbered
     * TO-2vYsid, trips called TRP-PRETRIP-DEMO — and leaving half of them would
     * reproduce the noise this seeder exists to remove. The delete is soft, so
     * the decision is reversible; the alternative, guessing which rows were
     * "real", is not.
     *
     * Order matters: children before parents, so nothing is briefly orphaned.
     *
     * @return array<string,int>
     */
    private function clearPreviousDemo(int $tenantId): array
    {
        $trips        = TransportTrip::forTenant($tenantId)->get();
        $consignments = TransportConsignment::forTenant($tenantId)->get();
        $orders       = TransportOrder::forTenant($tenantId)->get();

        // Release first: an assignment row has no soft delete, and leaving an
        // ACTIVE one pointing at a deleted trip would make a vehicle look busy
        // for a trip nobody can open.
        $released = 0;
        foreach ($trips as $trip) {
            $released += \App\Models\Transport\TripAssignment::forTenant($tenantId)
                ->forTrip($trip->id)
                ->delete();
        }

        // The SAME hazard one table across, and it bites harder. An attachment
        // row is never deleted — it IS the §7 history — so a live one pointing
        // at a soft-deleted consignment leaves the container permanently busy:
        // the unique index over active_container_key then refuses to attach it
        // anywhere else, and nothing on screen says why. Detach rather than
        // delete, so the history stays truthful.
        $detached = 0;
        foreach ($consignments as $consignment) {
            $detached += ConsignmentContainer::forTenant($tenantId)
                ->where('consignment_id', $consignment->id)
                ->whereNull('detached_at')
                ->update(['detached_at' => now()]);
        }

        $counts = [
            'assignments'  => $released,
            'containers'   => $detached,
            'trips'        => $trips->count(),
            'consignments' => $consignments->count(),
            'orders'       => $orders->count(),
        ];

        $trips->each->delete();
        $consignments->each->delete();
        $orders->each->delete();

        return $counts;
    }

    /**
     * Two vehicles and two drivers, created through the Fleet services.
     *
     * Idempotent on registration and licence, both of which are unique per
     * tenant, so a second run finds the existing rows rather than colliding
     * with them.
     *
     * @return array{0:array<int,TransportVehicle>,1:array<int,TransportDriver>}
     */
    private function fleet(int $tenantId, ?User $actor): array
    {
        $vehicleService = app(TransportVehicleService::class);
        $driverService  = app(TransportDriverService::class);

        $vehicles = [];
        foreach (self::VEHICLES as [$registration, $type, $tonnes]) {
            $vehicle = TransportVehicle::forTenant($tenantId)
                ->where('registration_normalized', TransportVehicle::normalizeRegistration($registration))
                ->first();

            if (! $vehicle) {
                $vehicle = $vehicleService->create([
                    'registration_number' => $registration,
                    'vehicle_type'        => $type,
                    'capacity_tonnes'     => $tonnes,
                    'ownership_type'      => 'owned',
                ], $tenantId, $actor);
            }

            // FLEET §8 — a new vehicle is NOT allocatable. The demo needs these
            // two to be choosable, and the state machine is the only way to get
            // there, so the transition is made rather than the column forced.
            if ($vehicle->status === VehicleStatus::NEW) {
                $vehicle = $vehicleService->transitionTo(
                    $vehicle, VehicleStatus::AVAILABLE, $tenantId, $actor, 'Demo data'
                );
            }

            $vehicles[] = $vehicle;
        }

        $drivers = [];
        foreach (self::DRIVERS as [$name, $licence, $class]) {
            $driver = TransportDriver::forTenant($tenantId)
                ->where('licence_normalized', TransportDriver::normalizeLicence($licence))
                ->first();

            $drivers[] = $driver ?: $driverService->create([
                'name'                => $name,
                'licence_number'      => $licence,
                'licence_class'       => $class,
                'licence_valid_until' => now()->addYears(3)->toDateString(),
                'mobile'              => '98200000'.count($drivers).count($drivers),
            ], $tenantId, $actor);
        }

        return [$vehicles, $drivers];
    }

    /** Trip 1 — crewed, moving, and due back in the future. */
    private function buildMovingTrip(
        int $tenantId,
        ?User $actor,
        int $customerId,
        TransportVehicle $vehicle,
        TransportDriver $driver,
    ): TransportTrip {
        $order = $this->order($tenantId, $actor, $customerId, [
            'pickup'  => 'JNPT Terminal, Navi Mumbai',
            'deliver' => 'Bhiwandi Warehouse, Thane',
            'service' => 'Container Haulage',
        ]);

        $consignment = app(ConsignmentService::class)->create([
            'order_id'           => $order->id,
            'customer_reference' => 'PO-88914',
            'cargo_description'  => '1 × 40ft container, palletised industrial spares',
            'service_type'       => 'Container Haulage',
            'package_count'      => 48,
            'gross_weight_kg'    => 21450.500,
        ], $tenantId, $actor);

        $trip = app(TransportTripService::class)->createFromOrder($order->id, [
            'route'          => 'JNPT → Bhiwandi',
            'consignment_id' => $consignment->id,
        ], $tenantId, $actor);

        // The dates are what item 4 renders. Set before the assignment so the
        // commitment sentence is complete the moment the vehicle is held.
        $trip->forceFill([
            // BYPASSES THE STATE MACHINE — see D-58. Delete once a real
            // approval path exists.
            'status'               => TripStatus::ALLOCATED,
            'planned_departure_at' => now()->subDay(),
            'planned_arrival_at'   => now()->addDays(2)->setTime(16, 0),
        ])->save();

        app(TripAssignmentService::class)
            ->assign($trip->fresh(), $vehicle->id, $driver->id, $tenantId, $actor);

        return $trip->fresh();
    }

    /** Trip 2 — approved, and waiting for someone to crew it. */
    private function buildWaitingTrip(int $tenantId, ?User $actor, int $customerId): TransportTrip
    {
        $order = $this->order($tenantId, $actor, $customerId, [
            'pickup'  => 'Mundra Port, Gujarat',
            'deliver' => 'Cold Store, Pune',
            'service' => 'Reefer Movement',
        ]);

        $consignment = app(ConsignmentService::class)->create([
            'order_id'           => $order->id,
            'customer_reference' => 'RF-5540',
            'cargo_description'  => 'Temperature-controlled pharma, 2–8 °C',
            'service_type'       => 'Reefer Movement',
            'package_count'      => 320,
            'gross_weight_kg'    => 4200.000,
        ], $tenantId, $actor);

        $trip = app(TransportTripService::class)->createFromOrder($order->id, [
            'route'          => 'Mundra → Pune',
            'consignment_id' => $consignment->id,
        ], $tenantId, $actor);

        $trip->forceFill([
            // BYPASSES THE STATE MACHINE — see D-58. Delete once a real
            // approval path exists.
            'status'               => TripStatus::APPROVED,
            'planned_departure_at' => now()->addDays(1)->setTime(6, 0),
            'planned_arrival_at'   => now()->addDays(3)->setTime(18, 0),
        ])->save();

        return $trip->fresh();
    }

    /**
     * MDM-008 + STOS-CTD §7 — one container, attached through the real service.
     *
     * Idempotent on the normalised number, which is unique per tenant, so a
     * re-run finds the existing container and re-attaches it rather than
     * colliding with it.
     */
    private function attachDemoContainer(int $tenantId, ?User $actor, ?int $consignmentId): void
    {
        if (! $consignmentId) {
            return;
        }

        $service = app(ContainerService::class);

        // TWO containers, in the two states the screen has to distinguish: one
        // on a consignment and one free. With only the attached one, the "Free"
        // filter and the empty half of the list could not be demonstrated — and
        // an unused filter is the kind of thing nobody notices is broken.
        $onConsignment = $this->ensureContainer($service, $tenantId, $actor, 'sgoe-402215-9', '40ft Reefer');
        $this->ensureContainer($service, $tenantId, $actor, 'sgoe-771040-2', '20ft Standard');

        $consignment = TransportConsignment::forTenant($tenantId)->find($consignmentId);

        if ($consignment && ! $onConsignment->fresh()->isAttached()) {
            $service->attach($onConsignment->fresh(), $consignment, $tenantId, $actor);
        }
    }

    /** Idempotent on the normalised number, which is unique per tenant. */
    private function ensureContainer(
        ContainerService $service,
        int $tenantId,
        ?User $actor,
        string $entered,
        string $type,
    ): TransportContainer {
        $existing = TransportContainer::forTenant($tenantId)
            ->where('container_number_normalized', TransportContainer::normalise($entered))
            ->first();

        return $existing ?: $service->create([
            'container_number' => $entered,
            'container_type'   => $type,
        ], $tenantId, $actor);
    }

    /** @param array<string,string> $spec */
    private function order(int $tenantId, ?User $actor, int $customerId, array $spec): TransportOrder
    {
        $order = app(TransportOrderService::class)->create([
            'customer_id'       => $customerId,
            'pickup_location'   => ['address' => $spec['pickup']],
            'delivery_location' => ['address' => $spec['deliver']],
            'required_at'       => now()->addDays(3)->toDateTimeString(),
            'service_type'      => $spec['service'],
        ], $tenantId, $actor);

        // A trip can only be raised from an APPROVED order, and the demo starts
        // downstream of that. Walked through the real transitions so the audit
        // trail reads the way a real order's would.
        $service = app(TransportOrderService::class);
        $order   = $service->transition($order, OrderStatus::SUBMITTED, $tenantId, $actor);

        return $service->transition($order->fresh(), OrderStatus::APPROVED, $tenantId, $actor);
    }

    private function customerId(int $tenantId): int
    {
        return (int) (\App\Models\Customer\Client::where('tenant_id', $tenantId)->value('id') ?? 1);
    }

    /** @param array<string,int> $cleared */
    private function report(int $tenantId, array $cleared, TransportTrip $moving, TransportTrip $waiting): void
    {
        $c = $this->command;

        $c?->info(sprintf(
            'Cleared (soft, reversible): %d order(s), %d consignment(s), %d trip(s), '
            .'%d assignment(s), %d container attachment(s).',
            $cleared['orders'], $cleared['consignments'], $cleared['trips'],
            $cleared['assignments'], $cleared['containers'],
        ));

        $c?->info('Demo ready for tenant #'.$tenantId.':');
        $c?->info('  '.$moving->trip_number.'  crewed and moving — due back '
            .$moving->planned_arrival_at?->format('j M'));
        $c?->info('  '.$waiting->trip_number.'  approved, needs a vehicle and a driver  ← start here');

        // Say what is NOT clean, rather than letting the screen say it.
        $extraVehicles = TransportVehicle::forTenant($tenantId)
            ->whereNotIn('registration_number', array_column(self::VEHICLES, 0))->count();
        $extraDrivers = TransportDriver::forTenant($tenantId)
            ->whereNotIn('name', array_column(self::DRIVERS, 0))->count();

        if ($extraVehicles || $extraDrivers) {
            $c?->warn(sprintf(
                '%d other vehicle(s) and %d other driver(s) remain from earlier testing. '
                .'They are Person 2\'s rows and this seeder does not delete them.',
                $extraVehicles, $extraDrivers,
            ));
        }
    }
}
