<?php

namespace Tests\Feature\Portal;

use App\Models\Customer\Client;
use App\Models\Project\Project;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Models\Task\Task;
use App\Models\Tenant;
use App\Models\Vendor\VendorAward;
use App\Models\Vendor\VendorShipment;
use App\Services\StatusService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Vendor Portal parity — the General/Execution/Performance/Compliance
 * endpoints that mirror the TPV portal, scoped to the PurchaseVendor identity.
 */
class PurchasePortalParityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;
    private PurchaseVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
        $this->vendor = PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Bolt Supplies',
            'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active',
        ]);
    }

    public function test_customers_lists_purchase_vendor_clients(): void
    {
        Client::create(['tenant_id' => self::TENANT, 'company' => 'Client A', 'purchase_vendor_id' => $this->vendor->id]);
        Client::create(['tenant_id' => self::TENANT, 'company' => 'Someone Else']);

        Sanctum::actingAs($this->vendor);
        $res = $this->getJson('/api/portal/purchase/customers')->assertOk()->json('data');
        $this->assertSame(['Client A'], collect($res)->pluck('company')->all());
    }

    public function test_projects_and_task_status(): void
    {
        $uid = \App\Models\User::create(['tenant_id' => self::TENANT, 'name' => 'Staff', 'role' => 'admin', 'email' => 's@t.local', 'password' => bcrypt('x'), 'status' => 'active'])->id;
        $p = new Project();
        $p->forceFill(['tenant_id' => self::TENANT, 'name' => 'Supply Job', 'status' => 'in_progress', 'progress' => 0, 'start_date' => '2026-01-01', 'created_by' => $uid, 'vendor_id' => $this->vendor->id, 'link_type' => 'purchase_vendor'])->save();
        $task = new Task();
        $task->forceFill(['tenant_id' => self::TENANT, 'name' => 'Deliver', 'status' => 'not_started', 'start_date' => '2026-01-01', 'created_by' => $uid, 'rel_type' => 'purchase_vendor', 'rel_id' => $this->vendor->id])->save();
        $status = app(StatusService::class)->keys('task', self::TENANT)[0] ?? 'in_progress';

        Sanctum::actingAs($this->vendor);
        $this->getJson('/api/portal/purchase/projects')->assertOk()->assertJsonPath('data.0.name', 'Supply Job');
        $this->getJson('/api/portal/purchase/work-tasks')->assertOk()->assertJsonPath('data.0.name', 'Deliver');
        $this->patchJson("/api/portal/purchase/tasks/{$task->id}/status", ['status' => $status])
            ->assertOk()->assertJsonPath('data.status', $status);
    }

    public function test_shipment_create_and_advance(): void
    {
        Sanctum::actingAs($this->vendor);
        $res = $this->postJson('/api/portal/purchase/shipments', [
            'courier' => 'DTDC', 'packages' => [['description' => 'Box', 'qty' => 2]],
        ])->assertCreated();
        $id = $res->json('id');
        $this->assertStringStartsWith('SHP-', $res->json('reference'));

        $this->getJson('/api/portal/purchase/shipments')->assertOk()->assertJsonPath('data.0.packages_count', 1);
        $this->patchJson("/api/portal/purchase/shipments/{$id}/status", ['status' => 'Dispatched'])
            ->assertOk()->assertJsonPath('status', 'Dispatched');
    }

    public function test_referral_submit_and_award_view(): void
    {
        VendorAward::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id, 'title' => 'On-time Star', 'awarded_on' => '2026-01-01']);

        Sanctum::actingAs($this->vendor);
        $this->getJson('/api/portal/purchase/awards')->assertOk()->assertJsonPath('data.0.title', 'On-time Star');
        $this->postJson('/api/portal/purchase/referrals', ['company_name' => 'Referred Co'])->assertCreated();
        $this->getJson('/api/portal/purchase/referrals')->assertOk()->assertJsonPath('data.0.company_name', 'Referred Co');
    }

    public function test_penalty_and_feedback(): void
    {
        PurchaseVendorViolation::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $this->vendor->id, 'reference' => 'PV-1', 'type' => 'Quality', 'severity' => 'Minor', 'occurred_at' => now(), 'points' => 3, 'status' => 'Open']);

        Sanctum::actingAs($this->vendor);
        $this->getJson('/api/portal/purchase/violations')->assertOk()->assertJsonPath('total_points', 3);
        $this->getJson('/api/portal/purchase/feedback')->assertOk()->assertJsonStructure(['live' => ['overall_score', 'band']]);
    }

    public function test_isolation_between_purchase_vendors(): void
    {
        $other = PurchaseVendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Other', 'purchase_vendor_code' => 'PV-'.uniqid(), 'status' => PurchaseVendorStatus::ACTIVE, 'portal_status' => 'active']);
        $ship = VendorShipment::create(['tenant_id' => self::TENANT, 'purchase_vendor_id' => $other->id, 'status' => 'Pre-Alert']);

        Sanctum::actingAs($this->vendor);
        $this->getJson('/api/portal/purchase/shipments')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/portal/purchase/shipments/{$ship->id}/status", ['status' => 'Dispatched'])->assertNotFound();
    }
}
