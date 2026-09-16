<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Registering a worker used to fail with a raw duplicate-key error once any
 * worker had been hard-deleted: worker_code was count()+1, and the count falls
 * behind the highest number issued the moment a row leaves the table, so the
 * generator handed back a code that already existed.
 *
 * A blank Aadhar had the same shape of problem from the other side — the column
 * is unique per tenant, so a second worker saved with '' collided with the first.
 */
class WorkerCodeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]));

        $this->vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE,
        ]);
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/tpv/workers', [
            'vendor_id' => $this->vendor->id,
            'name'      => 'Ravi',
            ...$overrides,
        ]);
    }

    public function test_a_worker_can_be_registered_after_an_earlier_one_is_hard_deleted(): void
    {
        $first  = $this->register(['name' => 'One'])->assertCreated()->json('id');
        $second = $this->register(['name' => 'Two'])->assertCreated()->json('id');

        // Hard-delete the middle of the sequence — this is what pushed count()
        // below the highest code already issued.
        TpvWorker::withTrashed()->whereKey($first)->forceDelete();

        $third = $this->register(['name' => 'Three'])->assertCreated()->json('id');

        $codes = TpvWorker::withTrashed()->pluck('worker_code');
        $this->assertCount($codes->unique()->count(), $codes, 'worker codes must stay unique');
        $this->assertNotSame(
            TpvWorker::withTrashed()->whereKey($second)->value('worker_code'),
            TpvWorker::withTrashed()->whereKey($third)->value('worker_code'),
        );
    }

    public function test_the_next_code_steps_past_a_soft_deleted_worker(): void
    {
        $this->register(['name' => 'One'])->assertCreated();
        $held = TpvWorker::first();
        $held->delete();                       // soft — the code is still taken

        $next = $this->register(['name' => 'Two'])->assertCreated()->json('id');

        $this->assertNotSame($held->worker_code, TpvWorker::withTrashed()->whereKey($next)->value('worker_code'));
    }

    public function test_two_workers_may_both_be_registered_without_an_aadhar(): void
    {
        $this->register(['name' => 'One', 'aadhar_number' => ''])->assertCreated();
        $this->register(['name' => 'Two', 'aadhar_number' => ''])->assertCreated();

        $this->assertSame(2, TpvWorker::whereNull('aadhar_number')->count());
    }

    public function test_a_repeated_aadhar_is_still_refused_with_a_readable_message(): void
    {
        $this->register(['name' => 'One', 'aadhar_number' => '901229631092'])->assertCreated();

        $this->register(['name' => 'Two', 'aadhar_number' => '901229631092'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'A worker with this Aadhar is already registered — WRK-'.date('Y').'-001 (One).']);
    }
}
