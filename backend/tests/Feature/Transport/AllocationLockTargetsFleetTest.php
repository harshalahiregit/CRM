<?php

namespace Tests\Feature\Transport;

use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportTrip;
use App\Services\Transport\TripAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-136 — BR-P0-003's lock must be taken on the row the allocation contends for.
 *
 * `lockResources()` locked `transport_vehicles` and `transport_drivers`. After
 * the repoint `$vehicleId` is a FLEET id, so for a migrated vehicle it locked
 * whichever legacy row happened to carry that number, and for a vehicle created
 * through Fleet's own screen there was no legacy row at all — `first()` returned
 * null and it locked nothing.
 *
 * ── WHAT THIS DID AND DID NOT BREAK ──────────────────────────────────────
 * It did NOT let two dispatchers take the same truck. Measured: in all three
 * configurations exactly one allocation succeeded and no vehicle was ever
 * double-booked. What broke is which refusal the loser is shown:
 *
 *   lock on Fleet (fixed)   one OK · one BusinessException naming the trip
 *   lock on legacy (before) one OK · one QueryException: Deadlock found
 *   no lock at all          one OK · one QueryException: Deadlock found
 *
 * A designed, readable refusal — QA-003's "blocked with an actionable message" —
 * was replaced by an accidental one. **The broken lock was indistinguishable
 * from no lock**, which is why nothing went red.
 *
 * And what held instead was MySQL's deadlock detection plus the unique index
 * over the generated columns. Neither was designed for this job. It was not
 * correct, it was lucky — and three runs measure three interleavings, not
 * every interleaving.
 *
 * ── WHY THIS FILE CANNOT BE THE WHOLE PROOF ──────────────────────────────
 * A guard against a race is not proven by a test that does not race, and the
 * suite runs on in-memory SQLite with one connection. The real proof is two
 * concurrent PHP processes against MySQL — `tests/concurrency/race-allocation.sh`,
 * which builds its own throwaway container and tears it down.
 *
 * What this file holds is the part SQLite can prove: that the lock names the
 * right table, and that a resource with no Fleet row is refused rather than
 * allocated unguarded.
 */
class AllocationLockTargetsFleetTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function fleetVehicle(): int
    {
        return DB::table('vehicles')->insertGetId([
            'company_id' => self::COMPANY, 'registration_number' => 'MH01LOCK'.self::uniqueSeq(4),
            'vehicle_type' => 'truck', 'ownership_type' => 'owned', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function trip(): TransportTrip
    {
        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return TransportTrip::withoutGlobalScopes()->find($id);
    }

    public function test_the_lock_is_taken_on_the_table_the_allocation_writes(): void
    {
        $vehicleId = $this->fleetVehicle();

        DB::enableQueryLog();
        app(TripAssignmentService::class)->assign($this->trip(), $vehicleId, null, self::COMPANY);
        $sql = collect(DB::getQueryLog())->pluck('query')->implode(' ; ');
        DB::disableQueryLog();

        $this->assertStringContainsString('from "vehicles"', $sql,
            'The lock was not taken on `vehicles`. Since the repoint that is the row two dispatchers '
            .'contend for and the row the assignment points at — locking anything else serialises '
            .'nothing, and the loser gets a deadlock instead of a sentence.');

        $this->assertStringNotContainsString('from "transport_vehicles"', $sql,
            'The lock is still reading `transport_vehicles`. That table no longer holds the row being '
            .'allocated, so the lock is taken on a row nobody is competing for.');
    }

    /**
     * A lock that locked nothing used to look exactly like a lock that worked.
     *
     * That is how this survived the repoint, so the absence is now loud. New
     * behaviour rather than a repoint, and it should have been proposed before
     * it was written; checked against the composite's three drivers, all of
     * which have a `driver_profiles` row, so no legitimate allocation is
     * refused by it.
     */
    public function test_a_resource_with_no_fleet_row_is_refused_rather_than_allocated_unguarded(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        app(TripAssignmentService::class)->assign($this->trip(), 999_999, null, self::COMPANY);
    }

    public function test_the_same_holds_for_the_driver_half(): void
    {
        $this->expectException(ResourceNotFoundException::class);

        app(TripAssignmentService::class)->assign($this->trip(), null, 999_999, self::COMPANY);
    }
}
