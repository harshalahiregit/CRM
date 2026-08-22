<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Purchase\PurchaseVendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase central Approval Register (Sangoe TPV §12) — the whole flow through the
 * real HTTP API. The register is a generic classification record with an
 * admin-only decision, so the tests that matter are the role boundary, the
 * reject-needs-a-reason rule, the terminal lock, and tenant isolation.
 *
 * Doc-flow map (§12):
 *   list exposes the 18 types → raise (Pending) → staff CANNOT decide (admin-only)
 *   → admin approves / rejects (reject needs remarks) → a decided entry is locked.
 */
class PurchaseApprovalRegisterFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin', int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => 'AppCo',
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => strtolower(Str::random(5)).'@test.local',
            'status' => 'Draft', 'portal_status' => 'active',
        ]);
    }

    private function raise(array $overrides = []): int
    {
        return $this->postJson('/api/purchase/approval-requests', array_merge([
            'approval_type' => 'contract',
            'title'         => 'Approve annual supply contract',
            'priority'      => 'High',
        ], $overrides))->assertCreated()->assertJsonPath('status', 'Pending')->json('id');
    }

    public function test_register_exposes_the_eighteen_types(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->getJson('/api/purchase/approval-requests')
            ->assertOk()
            ->assertJsonCount(18, 'types')
            ->assertJsonPath('data', []);
    }

    public function test_admin_can_raise_and_approve(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $vendor = $this->vendor();

        $id = $this->raise(['purchase_vendor_id' => $vendor->id]);

        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'approve', 'remarks' => 'ok'])
            ->assertOk()
            ->assertJsonPath('status', 'Approved')
            ->assertJsonPath('decided_by', $admin->id);
    }

    /** Rule: rejecting needs a reason. */
    public function test_reject_requires_remarks(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->raise();

        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'reject'])
            ->assertStatus(422);

        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'reject', 'remarks' => 'Out of budget'])
            ->assertOk()->assertJsonPath('status', 'Rejected');
    }

    /** Deciding is admin-only — staff may raise and view, never decide. */
    public function test_staff_cannot_decide(): void
    {
        // Staff raises (allowed).
        Sanctum::actingAs($this->user('staff'));
        $id = $this->raise();

        // Staff cannot decide → 403, and it stays Pending.
        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'approve'])
            ->assertStatus(403);

        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/purchase/approval-requests')
            ->assertOk()->assertJsonPath('data.0.status', 'Pending');
    }

    /** A decided entry is terminal — it cannot be decided again. */
    public function test_decided_entry_is_locked(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->raise();

        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'reject', 'remarks' => 'too late'])
            ->assertStatus(422);
    }

    public function test_register_is_tenant_isolated(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $id = $this->raise();

        (new Tenant())->forceFill(['id' => 2, 'name' => 'T2', 'slug' => 't-2', 'subdomain' => 't2', 'status' => 'active'])->save();
        Sanctum::actingAs($this->user('admin', 2));

        // Different tenant cannot decide the other tenant's approval, and does not
        // see it in its own register.
        $this->postJson("/api/purchase/approval-requests/{$id}/decide", ['decision' => 'approve'])->assertNotFound();
        $this->getJson('/api/purchase/approval-requests')->assertOk()->assertJsonPath('data', []);
    }
}
