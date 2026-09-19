<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripEvent;
use App\Models\User;
use App\Services\Transport\TripEventRecorder;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripEventType;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The contract three sections write through — CTD §32, §34; STOS-DB §37.
 *
 * The refusals matter more than the happy path here, and they are unusual: the
 * recorder must NOT throw into its caller, and the rows it writes must not be
 * editable or deletable by anyone at all.
 */
class TripEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripEventRecorder $rec;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->rec = app(TripEventRecorder::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops', 'role' => 'staff',
            'email' => 'o-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        return $trip->fresh();
    }

    /* ══════════ recording ══════════ */

    public function test_the_registry_supplies_category_and_source_so_a_caller_need_not_know_them(): void
    {
        // The point of the contract: P2 names the event, not CTD §101's nine
        // branches and §33's ten sources.
        $e = $this->rec->record('genset.on', trip: $this->trip());

        $this->assertSame(TripEventType::TEMPERATURE, $e->category);
        $this->assertSame(TripEventType::SENSOR, $e->source);
        $this->assertSame('Generator on', $e->summary);
    }

    public function test_the_chain_is_denormalised_so_a_container_timeline_needs_no_join(): void
    {
        $trip = $this->trip();
        $e = $this->rec->record('trip.dispatched', trip: $trip, actor: $this->actor);

        $this->assertSame($trip->id, $e->trip_id);
        $this->assertSame($trip->order_id, $e->order_id);
        $this->assertSame($this->actor->id, $e->actor_id);
        // Denormalised, so a departed employee does not erase who did what.
        $this->assertSame($this->actor->name, $e->actor_name);
    }

    public function test_occurred_at_and_recorded_at_are_two_different_facts(): void
    {
        // STOS-DB §19. A telemetry batch buffered for an hour has both, and a
        // timeline ordered by the wrong one puts an hour of driving after the
        // delivery that followed it.
        $happened = now()->subHours(2);
        $e = $this->rec->record('gps.position', trip: $this->trip(), occurredAt: $happened);

        $this->assertSame($happened->format('Y-m-d H:i'), $e->occurred_at->format('Y-m-d H:i'));
        $this->assertTrue($e->recorded_at->greaterThan($e->occurred_at));
    }

    public function test_the_timeline_is_ordered_by_when_things_happened(): void
    {
        $trip = $this->trip();
        $this->rec->record('gps.position', trip: $trip, occurredAt: now()->subHours(3));
        $this->rec->record('trip.delivered', trip: $trip, occurredAt: now());
        $this->rec->record('gps.position', trip: $trip, occurredAt: now()->subHours(1));

        $order = TripEvent::forTenant(self::TENANT_A)->forTrip($trip->id)
            ->chronological(newestFirst: false)->pluck('event_type')->all();

        $this->assertSame(['gps.position', 'gps.position', 'trip.delivered'], $order);
    }

    /* ══════════ CTD §34 — immutability ══════════ */

    public function test_an_event_cannot_be_edited(): void
    {
        $e = $this->rec->record('trip.dispatched', trip: $this->trip());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('immutable');
        $e->update(['summary' => 'Something else entirely']);
    }

    public function test_an_event_cannot_be_deleted(): void
    {
        $e = $this->rec->record('trip.dispatched', trip: $this->trip());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');
        $e->delete();
    }

    public function test_a_correction_is_an_append_that_points_at_what_it_corrects(): void
    {
        // CTD §34: "Corrections should create a Correction Event with audit
        // trail." Both rows stay readable, so the correction is part of the
        // history rather than a quiet overwrite of it.
        $trip = $this->trip();
        $wrong = $this->rec->record('trip.delivered', trip: $trip, summary: 'Delivered to Bhiwandi');

        $fix = $this->rec->correct($wrong, 'Delivered to Bhiwandi Gate 3, not Gate 1.', $this->actor);

        $this->assertSame('event.corrected', $fix->event_type);
        $this->assertSame($wrong->id, $fix->corrects_event_id);
        $this->assertTrue($fix->isCorrection());
        // And the original is still there.
        $this->assertNotNull($wrong->fresh());
        $this->assertSame('Delivered to Bhiwandi', $wrong->fresh()->summary);
    }

    /* ══════════ it must never take the caller down ══════════ */

    public function test_an_unregistered_type_is_recorded_rather_than_refused(): void
    {
        // Refusing would make P2 and P3 wait on a P1 release to record anything
        // new. TripEventRegistryTest is what catches the drift instead.
        $e = $this->rec->record('gate.weighbridge', trip: $this->trip());

        $this->assertNotNull($e);
        $this->assertFalse($e->isRegistered());
        $this->assertSame('Gate weighbridge', $e->summary);
    }

    public function test_a_failure_returns_null_instead_of_throwing(): void
    {
        // A timeline entry is a record OF work, not part of it. A GPS ingest
        // that succeeded must not be rolled back because its event row failed.
        //
        // No tenant is derivable here, which is the simplest real failure.
        $this->assertNull($this->rec->record('trip.dispatched'));
    }

    /* ══════════ tenancy ══════════ */

    public function test_events_are_scoped_to_their_tenant(): void
    {
        $mine   = $this->trip(self::TENANT_A);
        $theirs = $this->trip(self::TENANT_B);

        $this->rec->record('trip.dispatched', trip: $mine);
        $this->rec->record('trip.dispatched', trip: $theirs);

        $this->assertSame(1, TripEvent::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TripEvent::forTenant(self::TENANT_B)->count());
        $this->assertSame(0, TripEvent::forTenant(self::TENANT_A)->forTrip($theirs->id)->count());
    }

    /* ══════════ what the services now emit ══════════ */

    public function test_the_trip_services_write_to_the_timeline(): void
    {
        $trip = $this->trip();
        // Standing where STT-007 can leave from. The transitions themselves are
        // TransitTest's subject; this test is only asking whether the service
        // writes to the timeline on its way through.
        $trip->forceFill(['status' => TripStatus::IN_TRANSIT, 'departed_at' => now()->subHour()])->save();

        app(\App\Services\Transport\TransportTripService::class)
            ->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $this->fail_if_missing('trip.delivered', $trip->id);
    }

    private function fail_if_missing(string $type, int $tripId): void
    {
        $this->assertTrue(
            TripEvent::forTenant(self::TENANT_A)->forTrip($tripId)->where('event_type', $type)->exists(),
            "the service did not write a '$type' event to the timeline",
        );
    }
}
