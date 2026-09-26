<?php

namespace Tests\Feature\Transport;

use App\Models\Customer\Client;
use App\Models\Tenant;
use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportConsignment;
use App\Models\Transport\TransportContainer;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Support\Transport\TripStatus;
use Database\Seeders\TransportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use Tests\TestCase;

/**
 * The demo is a deliverable, so it is tested like one.
 *
 * A seeder that half-runs is worse than one that does not run: it clears the
 * previous demo and leaves nothing in its place, five minutes before the
 * walkthrough.
 */
class TransportDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => 1, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        User::create([
            'tenant_id' => 1, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);

        Client::create(['tenant_id' => 1, 'company' => 'Demo Customer', 'status' => 'active']);
    }

    private function runDemoSeeder(): void
    {
        $this->seed(TransportDemoSeeder::class);
    }

    public function test_it_builds_exactly_two_of_everything(): void
    {
        $this->runDemoSeeder();

        // Fleet's masters, not the legacy pair — the seeder writes where the
        // product reads (D-143). A demo built in `transport_vehicles` would be
        // a demo of trips nobody could crew.
        $this->assertSame(2, Vehicle::forCompany(1)->count());
        $this->assertSame(2, DriverProfile::forCompany(1)->count());
        $this->assertSame(2, TransportTrip::forTenant(1)->count());
        $this->assertSame(2, TransportConsignment::forTenant(1)->count());
        $this->assertSame(2, TransportOrder::forTenant(1)->count());
    }

    public function test_the_two_trips_are_deliberately_different(): void
    {
        $this->runDemoSeeder();

        $trips = TransportTrip::forTenant(1)->orderBy('id')->get();

        // The further-along one has walked pre-trip, dispatch AND departure,
        // so it sits in `in_transit` and is genuinely on the road. Until
        // 2026-09-17 the furthest it could reach was pretrip_ok, and the report
        // line called it "moving" anyway. That is what makes the two trips
        // teach different things rather than showing the same screen twice.
        $moving  = $trips->firstWhere('status', TripStatus::IN_TRANSIT);
        $waiting = $trips->firstWhere('status', TripStatus::APPROVED);

        $this->assertNotNull($moving, 'one trip must be crewed and on the road — it is what makes "back in N days" visible');
        $this->assertNotNull($moving->departed_at, 'a trip described as moving must have left');
        $this->assertNotNull($waiting, 'one trip must be uncrewed — it is the one you allocate in the demo');

        // The crewed one holds a vehicle AND a driver, through a real assignment.
        $assignment = TripAssignment::forTenant(1)->active()->forTrip($moving->id)->first();
        $this->assertNotNull($assignment);
        $this->assertNotNull($assignment->vehicle_id);
        $this->assertNotNull($assignment->driver_id);

        // The waiting one holds neither.
        $this->assertNull(TripAssignment::forTenant(1)->active()->forTrip($waiting->id)->first());
    }

    public function test_the_moving_trip_has_a_future_arrival_so_the_sentence_has_something_to_say(): void
    {
        $this->runDemoSeeder();

        $moving = TransportTrip::forTenant(1)->where('status', TripStatus::IN_TRANSIT)->sole();

        $this->assertNotNull($moving->planned_arrival_at);
        $this->assertTrue($moving->planned_arrival_at->isFuture());
    }

    public function test_the_chain_reads_end_to_end(): void
    {
        // Order → consignment → trip, with no gap. This is the walkthrough.
        $this->runDemoSeeder();

        foreach (TransportTrip::forTenant(1)->get() as $trip) {
            $this->assertNotNull($trip->order_id, 'every trip belongs to an order');
            $this->assertNotNull($trip->consignment_id, 'every trip carries a consignment');

            $consignment = TransportConsignment::forTenant(1)->find($trip->consignment_id);
            $this->assertNotNull($consignment);
            $this->assertSame($trip->order_id, $consignment->order_id, 'the consignment is on the same order');
        }
    }

    public function test_numbers_are_real_not_hand_written(): void
    {
        // Proof the rows went through the services: only the numbering engine
        // produces these shapes.
        $this->runDemoSeeder();

        foreach (TransportTrip::forTenant(1)->get() as $t) {
            $this->assertMatchesRegularExpression('/^TRP-\d{4}-\d{6}$/', $t->trip_number);
        }
        foreach (TransportConsignment::forTenant(1)->get() as $c) {
            $this->assertMatchesRegularExpression('/^CNM-\d{4}-\d{6}$/', $c->consignment_number);
        }
        foreach (TransportOrder::forTenant(1)->get() as $o) {
            $this->assertMatchesRegularExpression('/^TO-\d{4}-\d{6}$/', $o->order_number);
        }
    }

    public function test_running_it_twice_resets_rather_than_duplicates(): void
    {
        $this->runDemoSeeder();
        $this->runDemoSeeder();

        $this->assertSame(2, TransportTrip::forTenant(1)->count());
        $this->assertSame(2, TransportConsignment::forTenant(1)->count());
        $this->assertSame(2, TransportOrder::forTenant(1)->count());

        // Fleet rows are reused, not recreated — registration is unique per company.
        $this->assertSame(2, Vehicle::forCompany(1)->count());
        $this->assertSame(2, DriverProfile::forCompany(1)->count());
    }

    public function test_clearing_is_reversible_and_leaves_no_dangling_assignment(): void
    {
        $this->runDemoSeeder();
        $firstRunTrips = TransportTrip::forTenant(1)->pluck('id');

        $this->runDemoSeeder();

        // The old trips are soft-deleted, so they can come back.
        foreach ($firstRunTrips as $id) {
            $this->assertNotNull(TransportTrip::withTrashed()->find($id)->deleted_at);
        }

        // And no ACTIVE assignment points at a deleted trip, which would make a
        // vehicle look busy for a trip nobody can open.
        $live = TripAssignment::forTenant(1)->active()->pluck('trip_id')->unique();
        foreach ($live as $tripId) {
            $this->assertNull(TransportTrip::forTenant(1)->find($tripId)?->deleted_at);
            $this->assertNotNull(TransportTrip::forTenant(1)->find($tripId), 'assignment points at a live trip');
        }
    }

    public function test_it_seeds_a_container_on_the_moving_trips_consignment(): void
    {
        $this->runDemoSeeder();

        // Two containers, ONE PER TRIP, so the chain reads end to end from
        // either one: trip → consignment → container.
        $this->assertSame(2, TransportContainer::forTenant(1)->count());
        $attached = TransportContainer::forTenant(1)->get()->filter->isAttached();
        $this->assertCount(2, $attached, 'both demo containers are on a consignment');

        $container = $attached->firstWhere('container_number', 'sgoe-402215-9');
        // §7 — stored as typed, matched on the normalised key.
        $this->assertSame('sgoe-402215-9', $container->container_number);
        $this->assertSame('SGOE4022159', $container->container_number_normalized);
    }

    public function test_re_running_never_leaves_a_container_stuck_on_a_deleted_consignment(): void
    {
        // The hazard: an attachment row is never deleted — it IS the §7 history
        // — so a live one pointing at a soft-deleted consignment would leave the
        // container permanently busy. The unique index over active_container_key
        // would then refuse to attach it anywhere else, with nothing on screen
        // saying why. Found by driving the real UI, not by review.
        $this->runDemoSeeder();
        $firstConsignments = TransportConsignment::forTenant(1)->pluck('id');

        $this->runDemoSeeder();

        foreach (ConsignmentContainer::forTenant(1)->whereNull('detached_at')->get() as $active) {
            $this->assertNotContains(
                $active->consignment_id,
                $firstConsignments,
                'an ACTIVE attachment still points at a consignment from the previous run',
            );
            $this->assertNotNull(
                TransportConsignment::forTenant(1)->find($active->consignment_id),
                'an ACTIVE attachment points at a consignment that is no longer live',
            );
        }

        // And the container is usable again, not stuck.
        $this->assertSame(2, TransportContainer::forTenant(1)->count(), 'reused, not duplicated');
        $this->assertCount(2, TransportContainer::forTenant(1)->get()->filter->isAttached());
    }

    public function test_the_seeder_never_writes_a_trip_status_directly(): void
    {
        // D-63. This seeder used to forceFill status to APPROVED and ALLOCATED,
        // and that single shortcut meant the demo showed a chain the product
        // could not perform — nobody noticed STT-002 was missing because the
        // seeder covered for it.
        //
        // Reading the source is the point: a behavioural test cannot tell a
        // status that was walked to from one that was written.
        $source = file_get_contents(database_path('seeders/TransportDemoSeeder.php'));
        $source = preg_replace('#//.*$#m', '', $source);   // code only, not the story

        // WRITES, not mentions. The seeder legitimately COMPARES against
        // TripStatus in assertDemoIsWhatItClaims() — checking its own work is
        // the opposite of forcing a state. What must never come back is
        // ASSIGNING one, which is exactly what D-63 was about.
        //
        // The first version of this guard banned the string outright and fired
        // on the self-check the day it was added. A guard that cannot tell a
        // read from a write trains people to weaken it.
        $writes = $this->tripStatusWrites($source);

        $this->assertSame([], $writes, sprintf(
            "TransportDemoSeeder ASSIGNS a trip status in code again:\n  %s\n\n"
            ."Demo trips must reach their state by walking the real transitions (see "
            ."approvedTrip()). If they cannot, that is a finding to report — not something to "
            .'route around. See D-63.',
            implode("\n  ", $writes),
        ));
    }

    /**
     * The guard has to still bite. Fed source that forces a trip status the
     * way D-63's seeder did — by constant, by literal, by variable — it must
     * find every one; fed Fleet's own resource-state writes, it must find none.
     */
    public function test_the_guard_still_catches_a_trip_status_write(): void
    {
        $forced = <<<'PHP'
            $trip->forceFill(['status' => TripStatus::APPROVED])->save();
            $trip->forceFill(['status' => 'allocated'])->save();
            $trip->update(['status' => $next]);
        PHP;

        $this->assertCount(3, $this->tripStatusWrites($forced), 'a forced trip status slipped past the guard');

        $fleet = <<<'PHP'
            $vehicle->update(['status' => \App\Domains\Fleet\Models\Vehicle::STATUS_AVAILABLE]);
            DriverProfile::create(['status' => \App\Domains\Fleet\Models\DriverProfile::AVAILABLE, 'x' => 1]);
        PHP;

        $this->assertSame([], $this->tripStatusWrites($fleet), 'a Fleet resource state is not a trip status');
    }

    /**
     * Every `'status' => …` write in the source, except those whose value is a
     * Fleet resource constant.
     *
     * The first version flagged every `'status' =>`. When the seeder was
     * repointed onto Fleet it began making its vehicles and drivers choosable
     * through Fleet's own model — `Vehicle::STATUS_*`, `DriverProfile::*` —
     * and the guard fired on a vehicle, which is not what D-63 was about.
     * So those two, and only those, are let through: anything else (a
     * TripStatus constant, a string, a variable) is still treated as a trip
     * status being forced.
     *
     * @return array<int,string>
     */
    private function tripStatusWrites(string $source): array
    {
        preg_match_all("/'status'\\s*=>\\s*[^,\\]\\)]+/", $source, $writes);

        return array_values(array_filter(
            array_map('trim', $writes[0]),
            fn (string $w) => ! preg_match('/=>\\s*\\\\?(App\\\\Domains\\\\Fleet\\\\Models\\\\)?(Vehicle::STATUS_|DriverProfile::)[A-Z_]+$/', $w),
        ));
    }

    public function test_the_demo_trips_reached_their_state_through_the_state_machine(): void
    {
        $this->runDemoSeeder();

        // Every seeded trip carries an approver, which only STT-002 sets. A
        // force-filled status would leave these null.
        foreach (TransportTrip::forTenant(1)->get() as $trip) {
            $this->assertNotNull(
                $trip->approved_at,
                $trip->trip_number.' has no approved_at — it did not pass through STT-002',
            );
            $this->assertNotNull($trip->approved_by, 'EVT-004 needs an approver');
        }
    }

    public function test_it_never_touches_another_tenant(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Bravo', 'slug' => 'bravo', 'subdomain' => 'bravo', 'status' => 'active',
        ])->save();

        $order = TransportOrder::create([
            'tenant_id' => 2, 'order_number' => 'TO-KEEPME', 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDay(), 'service_type' => 'X',
        ]);

        $this->runDemoSeeder();

        $this->assertNull($order->fresh()->deleted_at, "tenant 2's order must survive");
        $this->assertSame(0, TransportVehicle::forTenant(2)->count());
    }
}
