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
 * A TPV worker belongs to one tenant, and asking for another tenant's worker
 * gets you nothing.
 *
 * It used to get you the worker AND take it from its owner: assertTenant()
 * answered a tenant mismatch by writing the caller's tenant_id onto the row.
 * TpvWorker has no global tenant scope — BelongsToTenant only stamps tenant_id
 * on create — so route-model binding resolves every tenant's rows and this
 * check is the only thing standing between them.
 */
class TpvWorkerTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(int $id, string $slug): void
    {
        (new Tenant())->forceFill([
            'id' => $id, 'name' => $slug, 'slug' => $slug, 'subdomain' => $slug, 'status' => 'active',
        ])->save();
    }

    private function admin(int $tenantId): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function worker(int $tenantId): TpvWorker
    {
        $vendor = Vendor::create([
            'tenant_id' => $tenantId, 'company_name' => 'V-'.Str::random(5),
            'status' => VendorStatus::ACTIVE,
        ]);

        return TpvWorker::create([
            'tenant_id' => $tenantId, 'vendor_id' => $vendor->id, 'name' => 'Suresh',
            'worker_code' => 'PW-'.Str::random(6), 'current_step' => 1, 'status' => 'Draft',
            'medical_status' => 2, 'medical_type' => 'skip',
        ]);
    }

    public function test_another_tenants_worker_is_not_readable(): void
    {
        $this->tenant(1, 't1');
        $this->tenant(2, 't2');
        $theirs = $this->worker(2);

        Sanctum::actingAs($this->admin(1));

        $this->getJson("/api/tpv/workers/{$theirs->id}")->assertStatus(404);
    }

    public function test_reading_another_tenants_worker_does_not_steal_it(): void
    {
        $this->tenant(1, 't1');
        $this->tenant(2, 't2');
        $theirs = $this->worker(2);

        Sanctum::actingAs($this->admin(1));
        $this->getJson("/api/tpv/workers/{$theirs->id}");

        // The row still belongs to tenant 2. This is the half that made the old
        // behaviour permanent: the owner lost the worker from their own lists.
        $this->assertSame(2, (int) $theirs->fresh()->tenant_id);
    }

    public function test_another_tenants_worker_cannot_be_written_to(): void
    {
        $this->tenant(1, 't1');
        $this->tenant(2, 't2');
        $theirs = $this->worker(2);

        Sanctum::actingAs($this->admin(1));

        $this->postJson("/api/tpv/workers/{$theirs->id}/induction", [
            'induction_type' => 'General Safety',
            'trainer' => 'Rahul',
            'location' => 'Site Office',
        ])->assertStatus(404);

        $this->assertSame(2, (int) $theirs->fresh()->tenant_id);
        $this->assertDatabaseCount('tpv_worker_inductions', 0);
    }

    public function test_my_own_worker_is_still_reachable(): void
    {
        $this->tenant(1, 't1');
        $mine = $this->worker(1);

        Sanctum::actingAs($this->admin(1));

        $this->getJson("/api/tpv/workers/{$mine->id}")->assertOk();
    }

    /*
     * There is no test for the "worker from before tenanting is adopted"
     * branch: tpv_workers.tenant_id is NOT NULL, so a row without an owner
     * cannot be written in the first place. The branch is kept as a belt for
     * databases migrated from before that constraint, and is unreachable here.
     */
}
