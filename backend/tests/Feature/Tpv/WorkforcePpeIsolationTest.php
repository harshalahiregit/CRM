<?php

namespace Tests\Feature\Tpv;

use App\Models\Inventory\Movement;
use App\Models\Inventory\Product;
use App\Models\Inventory\Stock;
use App\Models\Inventory\Warehouse;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\PpeInventoryService;
use App\Support\Tpv\TpvOnboardingStatus as ObStatus;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Workforce Step 4 (PPE) and the Onboarding → Workforce handover.
 *
 * Three defects are pinned here:
 *
 * 1. The portal's PPE routes were the ADMIN PpeController/PpeRequirementController
 *    mounted inside the vendor.portal group. Those guard on tenant alone, so any
 *    vendor could read another vendor's PPE and — far worse — issue and write off
 *    kit against another vendor's worker, moving shared Inventory stock.
 *
 * 2. Neither PPE caller sent a warehouse_id, and StockService::record() 404s
 *    without one. Issuing and returning PPE failed outright: "That warehouse does
 *    not exist", naming a warehouse nobody had chosen.
 *
 * 3. TpvOnboardingService::approve() never set the vendor Active, so the portal
 *    never revealed Workforce and every worker was permanently blocked from a
 *    site badge.
 *
 * There is ONE stock ledger. These tests assert against `stocks` and
 * `inventory_movements` directly — the same tables the admin Inventory module
 * reads — rather than against any TPV-local count.
 */
class WorkforcePpeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'tenant-1',
            'subdomain' => 'tenant1', 'status' => 'active',
        ])->save();

        $this->warehouse = Warehouse::create([
            'tenant_id' => self::TENANT, 'name' => 'Main Store', 'code' => 'MAIN',
            'type' => 'godown', 'is_default' => true, 'status' => 'active',
        ]);
    }

    /* ── Fixtures ─────────────────────────────────────────────────────── */

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    /** A vendor company plus the portal login that owns it. */
    private function vendorWithLogin(string $name): array
    {
        $user = $this->user('third_party_vendor');

        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            // Never the login's email — the portal middleware also links by email.
            'email' => strtolower($name).'-'.Str::random(6).'@vendor.local',
            'status' => VendorStatus::ACTIVE, 'user_id' => $user->id,
        ]);

        return [$user, $vendor];
    }

    private function worker(Vendor $v, string $name = 'Worker'): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'worker_code' => 'W-'.Str::random(6), 'name' => $name,
            'current_step' => 1, 'status' => 'Draft',
        ]);
    }

    /** A PPE product with a known opening balance at the default warehouse. */
    private function product(float $qty = 100): Product
    {
        $p = Product::create([
            'tenant_id' => self::TENANT, 'name' => 'Safety Helmet',
            'sku' => 'PPE-'.Str::random(6), 'base_unit' => 'pcs',
            'status' => 'active', 'without_checking_warehouse' => false,
        ]);

        Stock::create([
            'tenant_id' => self::TENANT, 'product_id' => $p->id,
            'warehouse_id' => $this->warehouse->id, 'quantity' => $qty, 'reserved_quantity' => 0,
        ]);

        return $p;
    }

    private function onHand(Product $p): float
    {
        return (float) Stock::where('product_id', $p->id)->sum('quantity');
    }

    private function ppe(): PpeInventoryService
    {
        return app(PpeInventoryService::class);
    }

    /* ── Vendor ownership (1–5) ───────────────────────────────────────── */

    public function test_a_vendor_cannot_view_another_vendors_worker_ppe(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $workerB = $this->worker($vendorB);

        Sanctum::actingAs($userA);

        $this->getJson("/api/portal/ppe/workers/{$workerB->id}")->assertStatus(404);
    }

    public function test_a_vendor_cannot_view_another_vendors_ppe_compliance(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $workerB = $this->worker($vendorB);

        Sanctum::actingAs($userA);

        $this->getJson("/api/portal/ppe/compliance/workers/{$workerB->id}")->assertStatus(404);
    }

    public function test_a_vendor_cannot_issue_ppe_to_another_vendors_worker(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [, $vendorB] = $this->vendorWithLogin('BetaCo');
        $workerB = $this->worker($vendorB);
        $item    = $this->product(50);

        Sanctum::actingAs($userA);

        $this->postJson("/api/portal/ppe/workers/{$workerB->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 1,
        ])->assertStatus(404);

        // The shared stock must not have moved, and no holding recorded.
        $this->assertSame(50.0, $this->onHand($item));
        $this->assertSame(0, TpvWorkerPpeIssue::where('tpv_worker_id', $workerB->id)->count());
    }

    public function test_a_vendor_cannot_return_another_vendors_ppe(): void
    {
        [$userA] = $this->vendorWithLogin('AlphaCo');
        [$userB, $vendorB] = $this->vendorWithLogin('BetaCo');
        $workerB = $this->worker($vendorB);
        $item    = $this->product(50);

        // B legitimately issues to its own worker.
        Sanctum::actingAs($userB);
        $issue = TpvWorkerPpeIssue::find(
            $this->postJson("/api/portal/ppe/workers/{$workerB->id}/issue", [
                'inventory_item_id' => $item->id, 'qty' => 2,
            ])->assertStatus(201)->json('id')
        );
        $after = $this->onHand($item);

        // A must not be able to write it off.
        Sanctum::actingAs($userA);
        $this->postJson("/api/portal/ppe/issues/{$issue->id}/return", [
            'qty' => 2, 'condition' => 'lost',
        ])->assertStatus(404);

        $this->assertSame(0.0, (float) $issue->fresh()->returned_qty);
        $this->assertSame($after, $this->onHand($item));
    }

    public function test_a_vendor_can_manage_its_own_workers_ppe(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(10);

        Sanctum::actingAs($user);

        $this->getJson("/api/portal/ppe/workers/{$worker->id}")->assertOk();
        $this->getJson("/api/portal/ppe/compliance/workers/{$worker->id}")->assertOk();

        $id = $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 1,
        ])->assertStatus(201)->json('id');

        $this->postJson("/api/portal/ppe/issues/{$id}/return", [
            'qty' => 1, 'condition' => 'returned',
        ])->assertOk();
    }

    public function test_the_ppe_summary_counts_only_the_callers_own_holdings(): void
    {
        [$userA, $vendorA] = $this->vendorWithLogin('AlphaCo');
        [$userB, $vendorB] = $this->vendorWithLogin('BetaCo');
        $item = $this->product(100);

        Sanctum::actingAs($userB);
        $this->postJson("/api/portal/ppe/workers/{$this->worker($vendorB)->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 7,
        ])->assertStatus(201);

        Sanctum::actingAs($userA);
        $this->postJson("/api/portal/ppe/workers/{$this->worker($vendorA)->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 3,
        ])->assertStatus(201);

        // A sees its own 3, never B's 7 — the old endpoint returned the tenant's 10.
        // Cast rather than assertJsonPath: SQLite's SUM comes back as an int here
        // and MySQL's as a float, and the number is what matters, not its type.
        $body = $this->getJson('/api/portal/ppe/summary')->assertOk()->json();

        $this->assertSame(3.0, (float) $body['total_issued']);
        $this->assertSame(3.0, (float) $body['issued_today']);
    }

    /* ── Inventory rules (6–16) ───────────────────────────────────────── */

    public function test_issuing_deducts_stock_and_writes_the_full_trail(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(100);

        Sanctum::actingAs($user);

        // No warehouse_id — exactly what PpeCatalogue.jsx sends. This used to 404.
        $id = $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 2,
        ])->assertStatus(201)->json('id');

        // 6 — available decreased
        $this->assertSame(98.0, $this->onHand($item));

        // 8 — the holding record
        $issue = TpvWorkerPpeIssue::findOrFail($id);
        $this->assertSame($worker->id, $issue->tpv_worker_id);
        $this->assertSame('issued', $issue->status);
        $this->assertSame(2.0, (float) $issue->qty);

        // 7 — the ledger entry, traceable back to the issue
        $m = Movement::where('reference_type', 'ppe_issue')->where('reference_id', $id)->firstOrFail();
        $this->assertSame('issue', $m->type);
        $this->assertSame('out', $m->direction);
        $this->assertSame(2.0, (float) $m->quantity);
        $this->assertSame(98.0, (float) $m->balance_after);
        $this->assertSame($item->id, $m->product_id);
        $this->assertSame($this->warehouse->id, $m->from_warehouse_id);

        // 9 & 10 — worker PPE state and step
        $w = $worker->fresh();
        $this->assertSame(1, (int) $w->ppe_status);
        $this->assertSame(4, (int) $w->current_step);
    }

    public function test_issuing_is_refused_when_stock_is_exhausted(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(2);

        Sanctum::actingAs($user);

        $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 2,
        ])->assertStatus(201);
        $this->assertSame(0.0, $this->onHand($item));

        // 11 — nothing left
        $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 1,
        ])->assertStatus(422);

        // 13 — and the balance did not go under
        $this->assertSame(0.0, $this->onHand($item));
    }

    public function test_issuing_more_than_available_is_refused_atomically(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(5);

        Sanctum::actingAs($user);

        // 12 — over-issue rejected outright, not partially filled
        $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 6,
        ])->assertStatus(422);

        $this->assertSame(5.0, $this->onHand($item));
        $this->assertSame(0, TpvWorkerPpeIssue::count());
        $this->assertSame(0, Movement::where('reference_type', 'ppe_issue')->count());
    }

    public function test_a_genuine_return_restores_stock_to_the_issuing_site(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(10);

        Sanctum::actingAs($user);
        $id = $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 3,
        ])->assertStatus(201)->json('id');
        $this->assertSame(7.0, $this->onHand($item));

        // 14 — good kit comes back
        $this->postJson("/api/portal/ppe/issues/{$id}/return", [
            'qty' => 3, 'condition' => 'returned',
        ])->assertOk();

        $this->assertSame(10.0, $this->onHand($item));

        $back = Movement::where('reference_type', 'ppe_issue')->where('reference_id', $id)
            ->where('direction', 'in')->firstOrFail();
        $this->assertSame('return', $back->type);
        // Back to the site it left, not to whatever the default happens to be.
        $this->assertSame($this->warehouse->id, $back->to_warehouse_id);
    }

    public function test_lost_and_damaged_kit_does_not_come_back_into_stock(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(10);

        Sanctum::actingAs($user);

        foreach (['lost', 'damaged'] as $condition) {
            $id = $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
                'inventory_item_id' => $item->id, 'qty' => 1,
            ])->assertStatus(201)->json('id');

            $afterIssue = $this->onHand($item);

            // 15 & 16 — the item left stock at issue time; writing it off must not
            // put it back, and must not double-deduct either.
            $this->postJson("/api/portal/ppe/issues/{$id}/return", [
                'qty' => 1, 'condition' => $condition,
            ])->assertOk();

            $this->assertSame($afterIssue, $this->onHand($item), "{$condition} changed stock");
            $this->assertSame($condition, TpvWorkerPpeIssue::find($id)->status);
        }
    }

    public function test_a_vendor_cannot_move_another_tenants_stock_by_naming_its_warehouse(): void
    {
        // warehouse_id is not accepted by the portal endpoint at all, and the
        // service validates any id it is given against the tenant. Belt and braces.
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'tenant-2',
            'subdomain' => 'tenant2', 'status' => 'active',
        ])->save();
        $foreign = Warehouse::create([
            'tenant_id' => 2, 'name' => 'Other Store', 'code' => 'OTHER',
            'type' => 'godown', 'is_default' => true, 'status' => 'active',
        ]);

        [$user, $vendor] = $this->vendorWithLogin('AlphaCo');
        $worker = $this->worker($vendor);
        $item   = $this->product(10);

        Sanctum::actingAs($user);

        // Ignored by validation, so the issue still lands on the caller's own site.
        $id = $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 1, 'warehouse_id' => $foreign->id,
        ])->assertStatus(201)->json('id');

        $m = Movement::where('reference_type', 'ppe_issue')->where('reference_id', $id)->firstOrFail();
        $this->assertSame($this->warehouse->id, $m->from_warehouse_id);
        $this->assertNotSame($foreign->id, $m->from_warehouse_id);

        // And the service refuses it outright when called directly.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->ppe()->issue($worker, [
            'inventory_item_id' => $item->id, 'qty' => 1, 'warehouse_id' => $foreign->id,
        ], $user);
    }

    /* ── Vendor activation (17–19) ────────────────────────────────────── */

    public function test_admin_approval_activates_the_vendor(): void
    {
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'PendingCo',
            'email' => 'pending@vendor.local', 'status' => VendorStatus::INACTIVE,
        ]);
        $ob = TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'current_step' => 6, 'status' => ObStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", ['remarks' => 'All good'])->assertOk();

        // 17 — the vendor is Active, which is what every downstream gate reads.
        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
        $this->assertNotEmpty($vendor->fresh()->registration_number);
    }

    public function test_activation_unblocks_the_workforce_badge_gate(): void
    {
        // 18 — before approval the worker is blocked on the vendor, not on itself.
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'GateCo',
            'email' => 'gate@vendor.local', 'status' => VendorStatus::INACTIVE,
        ]);
        $ob     = TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'current_step' => 6, 'status' => ObStatus::SUBMITTED,
        ]);
        $worker = $this->worker($vendor);
        $svc    = app(\App\Services\Tpv\TpvWorkerService::class);

        $before = $svc->blockers($worker->fresh());
        $this->assertNotEmpty(
            array_filter($before, fn ($b) => str_contains($b, 'complete their onboarding first')),
            'Precondition: an inactive vendor blocks its workers.',
        );

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", [])->assertOk();

        $after = $svc->blockers($worker->fresh());
        $this->assertEmpty(
            array_filter($after, fn ($b) => str_contains($b, 'complete their onboarding first')),
            'Approval must clear the vendor-onboarding blocker.',
        );
    }

    public function test_a_vendor_cannot_approve_its_own_onboarding(): void
    {
        // 19 — approval stays admin-only. No self-activation.
        [$user, $vendor] = $this->vendorWithLogin('SelfCo');
        $vendor->update(['status' => VendorStatus::INACTIVE]);
        $ob = TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'current_step' => 6, 'status' => ObStatus::SUBMITTED,
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", [])->assertStatus(403);

        Sanctum::actingAs($this->user('staff'));
        $this->postJson("/api/tpv/onboarding/{$ob->id}/approve", [])->assertStatus(403);

        $this->assertSame(VendorStatus::INACTIVE, $vendor->fresh()->status);
    }

    /* ── Workforce steps (20–26) ──────────────────────────────────────── */

    public function test_the_worker_step_advances_with_each_completed_stage(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('StepCo');
        $item = $this->product(10);

        Sanctum::actingAs($user);

        // 20 — created at Step 1
        $worker = TpvWorker::findOrFail(
            $this->postJson('/api/portal/workers', [
                'name' => 'Ramesh', 'designation' => 'Fitter', 'mobile' => '9876543210',
            ])->assertStatus(201)->json('id')
        );
        $this->assertSame(1, (int) $worker->current_step);
        // Ownership comes from the token, never the payload.
        $this->assertSame($vendor->id, $worker->vendor_id);

        // 21 — medical
        $this->postJson("/api/portal/workers/{$worker->id}/medical", [
            'fitness_status' => 'Fit', 'examined_on' => now()->toDateString(),
        ])->assertOk();
        $this->assertSame(2, (int) $worker->fresh()->current_step);

        // 22 — induction
        $this->postJson("/api/portal/workers/{$worker->id}/induction", [
            'passed' => true, 'conducted_on' => now()->toDateString(),
        ])->assertOk();
        $this->assertSame(3, (int) $worker->fresh()->current_step);

        // 23 — PPE
        $this->postJson("/api/portal/ppe/workers/{$worker->id}/issue", [
            'inventory_item_id' => $item->id, 'qty' => 1,
        ])->assertStatus(201);
        $this->assertSame(4, (int) $worker->fresh()->current_step);
    }

    public function test_a_vendor_cannot_activate_a_worker_but_an_admin_can(): void
    {
        [$user, $vendor] = $this->vendorWithLogin('BadgeCo');
        $worker = $this->worker($vendor);

        // 25 — activation is not on the portal surface at all.
        Sanctum::actingAs($user);
        $this->postJson("/api/tpv/workers/{$worker->id}/activate", [])->assertStatus(403);
        $this->assertNotSame('Active', $worker->fresh()->status);

        // 26 — an admin can, once the gate is clear. Staff cannot.
        Sanctum::actingAs($this->user('staff'));
        $this->postJson("/api/tpv/workers/{$worker->id}/activate", [])->assertStatus(403);

        Sanctum::actingAs($this->user('admin'));
        $res = $this->postJson("/api/tpv/workers/{$worker->id}/activate", []);
        $this->assertContains($res->getStatusCode(), [200, 422],
            'An admin reaches the handler; 422 means the blocker list refused, not the role gate.');
    }

    /* ── Regression: the onboarding gate stays shut ───────────────────── */

    public function test_the_admin_tpv_api_still_refuses_vendors(): void
    {
        [$user] = $this->vendorWithLogin('AlphaCo');
        Sanctum::actingAs($user);

        // Routes without a model binding: SubstituteBindings runs in the `api`
        // group, ahead of the route's own middleware, so a nonexistent id would
        // 404 before the role gate is ever consulted and prove nothing.
        foreach ([
            '/api/tpv/onboarding',
            '/api/tpv/onboarding/stats',
            '/api/tpv/workers',
            '/api/tpv/workers/stats',
            '/api/tpv/ppe/requirements',
        ] as $url) {
            $this->getJson($url)->assertStatus(403, "{$url} must refuse a vendor login");
        }
    }
}
