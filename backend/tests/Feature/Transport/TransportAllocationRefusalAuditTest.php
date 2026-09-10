<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BR-P0-003 "Allocation conflict log" and BR-P0-004 "Document status + override".
 *
 * Both rules are rated Critical and both name an audit artefact. Blocking is only
 * half of each; the block must also leave evidence. These tests prove the row is
 * written for every eligibility refusal, that it survives the exception, and that
 * nothing else about a refusal changed.
 */
class TransportAllocationRefusalAuditTest extends TestCase
{
    use RefreshDatabase;

    private const A = 1;

    private AllocationService $alloc;
    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private TransportDocumentService $docs;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::A, 'name' => 'Alpha Transport', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();

        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->docs       = app(TransportDocumentService::class);
        $this->actor = User::create([
            'tenant_id' => self::A, 'name' => 'Dispatcher', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'd-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(?float $capacity = null): TransportTrip
    {
        $o = TransportOrder::create([
            'tenant_id' => self::A, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(2), 'service_type' => 'Container Haulage',
            'required_capacity_tonnes' => $capacity,
        ]);
        $t = TransportTrip::create([
            'tenant_id' => self::A, 'order_id' => $o->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $t->forceFill(['status' => TripStatus::APPROVED])->save();

        return $t->fresh();
    }

    private function vehicle(?float $capacity = 30, bool $available = true): TransportVehicle
    {
        $v = $this->vehicleSvc->create([
            'registration_number' => 'MH12'.Str::upper(Str::random(2)).random_int(1000, 9999),
            'capacity_tonnes' => $capacity,
        ], self::A, $this->actor);

        return $available ? $this->vehicleSvc->transitionTo($v, VehicleStatus::AVAILABLE, self::A, $this->actor) : $v;
    }

    private function driver(array $o = []): TransportDriver
    {
        return $this->driverSvc->create(array_merge([
            'name' => 'Driver '.Str::random(4),
            'licence_number' => 'RJ14'.random_int(100000, 999999),
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $o), self::A, $this->actor);
    }

    /** The refusal row for a trip, or null. */
    private function refusal(TransportTrip $trip): ?TransportAuditLog
    {
        return TransportAuditLog::where('action', 'transport.allocation.refused')
            ->where('auditable_id', $trip->id)->latest('id')->first();
    }

    private function refuse(TransportTrip $trip, ?int $vehicleId, ?int $driverId): void
    {
        try {
            $this->alloc->assign($trip, $vehicleId, $driverId, self::A, $this->actor);
            $this->fail('the allocation should have been refused');
        } catch (BusinessException $e) {
            // expected
        }
    }

    /* ═══════ the five eligibility refusals ═══════ */

    /** BR-P0-003 — "Allocation conflict log". */
    public function test_vehicle_overlap_is_logged_against_br_p0_003(): void
    {
        $v = $this->vehicle();
        $this->alloc->assign($this->trip(), $v->id, null, self::A, $this->actor);
        $second = $this->trip();

        $this->refuse($second, $v->id, null);

        $row = $this->refusal($second);
        $this->assertNotNull($row, 'BR-P0-003 requires an allocation conflict log');
        $this->assertSame('BR-P0-003', $row->context['rule']);
        $this->assertSame('vehicle', $row->context['kind']);
        $this->assertSame($v->id, $row->context['resource_id']);
        // An overlap trips TWO checks: the vehicle's own status went to
        // `allocated` when it was crewed, AND it is on an active assignment. Both
        // are recorded; the rule is keyed off the assignment one, since that is
        // what BR-P0-003 is actually about.
        $blockers = implode(' | ', $row->context['blockers']);
        $this->assertStringContainsString('Already assigned', $blockers);
        $this->assertStringContainsString('trip', strtolower($blockers), 'a conflict log must name the conflicting trip');
        $this->assertContains('BR-P0-003; STOS-DB §198; RTM PLN-006', $row->context['sources']);

        $failed = collect($row->context['checks'])->where('passed', false)->pluck('key');
        $this->assertTrue($failed->contains('assignment'));
    }

    /** BR-P0-004 — "Document status + override", document half. */
    public function test_expired_licence_is_logged_against_br_p0_004_with_document_status(): void
    {
        $trip = $this->trip();
        $d = $this->driver(['licence_valid_until' => now()->subDays(4)->toDateString(), 'licence_class' => 'HMV']);

        $this->refuse($trip, null, $d->id);

        $row = $this->refusal($trip);
        $this->assertSame('BR-P0-004', $row->context['rule']);
        $this->assertSame('driver', $row->context['kind']);

        // The document-status half, as structured data rather than a sentence.
        $lic = $row->context['document_status']['licence'];
        $this->assertSame('HMV', $lic['class']);
        $this->assertSame(now()->subDays(4)->toDateString(), $lic['valid_until']);
        $this->assertFalse($lic['valid']);

        // The override half cannot be satisfied — PLN-007 is P1.
        $this->assertNull($row->context['override']);
        $this->assertArrayHasKey('override', $row->context, 'the key is present so the shape is right for PLN-007');
    }

    public function test_an_expired_vehicle_document_is_logged_with_its_document_status(): void
    {
        $trip = $this->trip();
        $v = $this->vehicle();
        $this->docs->file($v, TransportDocumentType::INSURANCE,
            ['document_number' => 'POL-9', 'valid_until' => now()->subDay()->toDateString()], self::A, $this->actor);

        $this->refuse($trip, $v->id, null);

        $row = $this->refusal($trip);
        $this->assertNull($row->context['rule'], 'no BR-P0 rule governs vehicle documents');
        $this->assertContains('QA-003; STOS-FLEET §14; STOS-CMP §21; RTM PLN-005', $row->context['sources']);

        $doc = collect($row->context['document_status']['documents'])->firstWhere('type', TransportDocumentType::INSURANCE);
        $this->assertSame('POL-9', $doc['number']);
        $this->assertFalse($doc['valid']);
        $this->assertSame(now()->subDay()->toDateString(), $doc['valid_until']);
    }

    public function test_a_vehicle_that_is_not_available_is_logged(): void
    {
        $trip = $this->trip();
        $v = $this->vehicle(available: false);   // still NEW

        $this->refuse($trip, $v->id, null);

        $row = $this->refusal($trip);
        $this->assertNull($row->context['rule']);
        $this->assertContains('STOS-FLEET §7/§8; BRW-044', $row->context['sources']);
        $this->assertNull($row->context['document_status'], 'a status refusal has no document story');
    }

    public function test_insufficient_capacity_is_logged(): void
    {
        $trip = $this->trip(capacity: 25);
        $v = $this->vehicle(capacity: 10);

        $this->refuse($trip, $v->id, null);

        $row = $this->refusal($trip);
        $this->assertContains('RTM PLN-001; FRS TRP-P0-003 ("payload")', $row->context['sources']);
        $this->assertStringContainsString('below', $row->context['blockers'][0]);
    }

    public function test_a_missing_required_document_is_logged(): void
    {
        app(TransportPolicyService::class)->set(self::A, 'vehicle.required_documents',
            [TransportDocumentType::FITNESS], $this->actor);
        $trip = $this->trip();
        $v = $this->vehicle();

        $this->refuse($trip, $v->id, null);

        $row = $this->refusal($trip);
        $this->assertStringContainsString('Missing required', $row->context['blockers'][0]);
        $this->assertSame([], $row->context['document_status']['documents'], 'nothing on file, and that is the point');
    }

    public function test_an_unavailable_driver_is_logged_against_br_p0_004(): void
    {
        $trip = $this->trip();
        $d = $this->driver();
        $this->driverSvc->transitionAvailabilityTo($d, DriverAvailability::ON_LEAVE, self::A, $this->actor);

        $this->refuse($trip, null, $d->id);

        $this->assertSame('BR-P0-004', $this->refusal($trip)->context['rule']);
    }

    /* ═══════ the row carries a full, reviewable verdict ═══════ */

    public function test_the_row_records_every_check_not_only_the_failures(): void
    {
        $trip = $this->trip();
        $d = $this->driver(['licence_valid_until' => now()->subDay()->toDateString()]);

        $this->refuse($trip, null, $d->id);

        $checks = $this->refusal($trip)->context['checks'];
        $this->assertCount(5, $checks, 'lifecycle, availability, assignment, licence, documents');
        $this->assertSame(1, collect($checks)->where('passed', false)->count());
        foreach ($checks as $c) {
            $this->assertArrayHasKey('detail', $c);
            $this->assertArrayHasKey('required', $c);
        }
    }

    public function test_the_row_names_the_actor_the_trip_and_the_resource(): void
    {
        $trip = $this->trip();
        $v = $this->vehicle(available: false);

        $this->refuse($trip, $v->id, null);

        $row = $this->refusal($trip);
        $this->assertSame($this->actor->id, (int) $row->actor_id);
        $this->assertSame(self::A, (int) $row->tenant_id);
        $this->assertSame(TransportTrip::class, $row->auditable_type);
        $this->assertSame($trip->trip_number, $row->context['trip_number']);
        $this->assertSame($v->registration_number, $row->context['resource_name']);
    }

    /* ═══════ nothing else about a refusal changed ═══════ */

    /**
     * The row is written OUTSIDE DB::transaction(), so it survives the throw.
     * Written inside, it would roll back with the refusal it exists to record.
     */
    public function test_the_audit_row_survives_the_refusal_but_nothing_else_is_written(): void
    {
        $trip = $this->trip();
        $v = $this->vehicle(available: false);

        $this->refuse($trip, $v->id, null);

        // The evidence persisted...
        $this->assertNotNull($this->refusal($trip));
        // ...and nothing else did.
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::A)->forTrip($trip->id)->count());
        $this->assertSame(VehicleStatus::NEW, $v->fresh()->status);
        $this->assertNull($trip->fresh()->vehicle_id);
    }

    public function test_repeated_refusals_each_leave_their_own_row(): void
    {
        $trip = $this->trip();
        $v = $this->vehicle(available: false);

        $this->refuse($trip, $v->id, null);
        $this->refuse($trip, $v->id, null);
        $this->refuse($trip, $v->id, null);

        $this->assertSame(3, TransportAuditLog::where('action', 'transport.allocation.refused')
            ->where('auditable_id', $trip->id)->count(), 'three attempts, three entries');
    }

    /* ═══════ precondition and validation failures stay unlogged ═══════ */

    public function test_a_non_approved_trip_produces_no_refusal_row(): void
    {
        $trip = $this->trip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();
        // Create the vehicle BEFORE measuring — creating one legitimately writes
        // its own audit rows, which would otherwise be mistaken for refusal rows.
        $vehicleId = $this->vehicle()->id;
        $before = TransportAuditLog::count();

        $this->refuse($trip->fresh(), $vehicleId, null);

        $this->assertNull($this->refusal($trip), 'wrong trip state is a precondition, not an allocation conflict');
        $this->assertSame($before, TransportAuditLog::count());
    }

    public function test_a_request_with_neither_resource_produces_no_refusal_row(): void
    {
        $trip = $this->trip();
        $before = TransportAuditLog::count();

        $this->refuse($trip, null, null);

        $this->assertNull($this->refusal($trip), 'a validation error is not a conflict log entry');
        $this->assertSame($before, TransportAuditLog::count());
    }

    /** A successful allocation still writes `performed`, never `refused`. */
    public function test_a_successful_allocation_writes_no_refusal_row(): void
    {
        $trip = $this->trip();
        $this->alloc->assign($trip, $this->vehicle()->id, $this->driver()->id, self::A, $this->actor);

        $this->assertNull($this->refusal($trip));
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.allocation.performed']);
    }
}
