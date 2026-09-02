<?php

namespace Tests\Feature\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorkPermit;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchasePermitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase Permit To Work.
 *
 * A permit is the document that says dangerous work may proceed, so the guards
 * are the feature — not the form. These cover the two that carry the weight
 * (no approval without a JSA, no approval for an inactive vendor) and the
 * authority split that makes a permit mean anything: whoever raises one must
 * not also be able to clear it.
 */
class PurchasePermitTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => 'T'.$id, 'slug' => 't'.$id,
                'subdomain' => 't'.$id, 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role = 'admin', int $tenant = self::TENANT): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $status = 'Active', int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => 'V'.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(6).'@test.local',
            'status' => $status, 'portal_status' => 'active',
        ]);
    }

    private function svc(): PurchasePermitService
    {
        return app(PurchasePermitService::class);
    }

    /** Purchase permits are always vendor-scoped -- the column is NOT NULL. */
    private function permit(?PurchaseVendor $v = null): PurchaseWorkPermit
    {
        $v = $v ?: $this->vendor();

        return $this->svc()->create(self::TENANT, [
            'purchase_vendor_id' => $v->id,
            'type'  => 'Hot_Work',
            'title' => 'Welding on line 3',
            'valid_from' => now()->toDateString(),
            'valid_to'   => now()->addDays(3)->toDateString(),
        ], $this->user('staff'));
    }

    public function test_a_permit_cannot_be_approved_without_a_jsa(): void
    {
        $permit = $this->permit($this->vendor());

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Job Safety Analysis');

        // The whole point of the form: approving with no hazard analysis is the
        // failure it exists to prevent.
        $this->svc()->approve($permit, $this->user('admin'));
    }

    public function test_a_permit_with_a_jsa_can_be_approved_and_records_who_and_why(): void
    {
        $permit = $this->permit($this->vendor());
        $this->svc()->addJsaStep($permit, ['activity' => 'Cut pipe', 'hazard' => 'Sparks', 'control' => 'Fire watch', 'residual_risk' => 'Low']);

        $admin = $this->user('admin');
        $out = $this->svc()->approve($permit->fresh(), $admin, 'Fire watch confirmed');

        $this->assertSame('Approved', $out->status);
        $this->assertSame($admin->id, (int) $out->approved_by);
        $this->assertNotNull($out->approved_at);
        $this->assertSame('Fire watch confirmed', $out->decision_remarks);
    }

    public function test_a_suspended_vendor_cannot_be_permitted_to_work(): void
    {
        $permit = $this->permit($this->vendor('Suspended'));
        $this->svc()->addJsaStep($permit, ['activity' => 'Cut pipe']);

        $this->expectException(BusinessException::class);
        // Permitting work to a suspended vendor would route around the
        // suspension entirely.
        $this->expectExceptionMessage('Cannot approve');

        $this->svc()->approve($permit->fresh(), $this->user('admin'));
    }

    public function test_jsa_steps_are_numbered_on_append(): void
    {
        $permit = $this->permit();
        $this->svc()->addJsaStep($permit, ['activity' => 'One']);
        $this->svc()->addJsaStep($permit->fresh(), ['activity' => 'Two']);
        $this->svc()->addJsaStep($permit->fresh(), ['activity' => 'Three']);

        // Assigned on append, never supplied — two people filling the analysis
        // must not both claim step 3.
        $this->assertSame([1, 2, 3], $permit->fresh()->jsaSteps->pluck('step_no')->all());
    }

    public function test_the_lifecycle_is_ordered(): void
    {
        $permit = $this->permit($this->vendor());
        $this->svc()->addJsaStep($permit, ['activity' => 'Cut pipe']);
        $admin = $this->user('admin');

        // Cannot activate before approval.
        try {
            $this->svc()->activate($permit->fresh(), $admin);
            $this->fail('An unapproved permit must not be activatable.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }

        $this->svc()->approve($permit->fresh(), $admin);
        $this->assertSame('Active', $this->svc()->activate($permit->fresh(), $admin)->status);
        $this->assertSame('Closed', $this->svc()->close($permit->fresh(), $admin)->status);

        // And a closed permit cannot be reopened by closing it again.
        $this->expectException(BusinessException::class);
        $this->svc()->close($permit->fresh(), $admin);
    }

    public function test_an_expired_window_cannot_be_activated(): void
    {
        $permit = $this->svc()->create(self::TENANT, [
            'purchase_vendor_id' => $this->vendor()->id,
            'type' => 'Hot_Work', 'title' => 'Lapsed',
            'valid_from' => now()->subDays(9)->toDateString(),
            'valid_to'   => now()->subDays(2)->toDateString(),
        ], $this->user('staff'));
        $this->svc()->addJsaStep($permit, ['activity' => 'x']);

        $admin = $this->user('admin');
        $this->svc()->approve($permit->fresh(), $admin);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('validity window');
        $this->svc()->activate($permit->fresh(), $admin);
    }

    public function test_expire_lapsed_leaves_undecided_requests_alone(): void
    {
        // A Requested permit past its window is a decision someone still owes;
        // quietly expiring it would bury that.
        $stale = $this->svc()->create(self::TENANT, [
            'purchase_vendor_id' => $this->vendor()->id,
            'type' => 'Other', 'title' => 'Never decided',
            'valid_to' => now()->subDay()->toDateString(),
        ], $this->user('staff'));

        $this->svc()->expireLapsed(self::TENANT);

        $this->assertSame('Requested', $stale->fresh()->status);
    }

    public function test_staff_may_raise_a_permit_but_not_approve_it(): void
    {
        $permit = $this->permit($this->vendor());
        $this->svc()->addJsaStep($permit, ['activity' => 'Cut pipe']);

        Sanctum::actingAs($this->user('staff'));

        // Raising is open to staff...
        $this->postJson('/api/purchase/permits', [
            'purchase_vendor_id' => $this->vendor()->id,
            'type' => 'Electrical', 'title' => 'Panel work',
        ])->assertCreated();

        // ...clearing it is not. The route carries role:admin.
        $this->postJson("/api/purchase/permits/{$permit->id}/approve")->assertForbidden();
        $this->assertSame('Requested', $permit->fresh()->status);
    }

    public function test_a_rejection_requires_a_reason(): void
    {
        $permit = $this->permit();

        Sanctum::actingAs($this->user('admin'));

        // A refusal nobody can answer is not a refusal.
        $this->postJson("/api/purchase/permits/{$permit->id}/reject")->assertStatus(422);
        $this->postJson("/api/purchase/permits/{$permit->id}/reject", ['remarks' => 'Scaffold not certified'])
            ->assertOk();

        $this->assertSame('Rejected', $permit->fresh()->status);
    }

    public function test_permits_are_tenant_scoped(): void
    {
        $mine = $this->permit();
        $theirs = $this->svc()->create(999, [
            'purchase_vendor_id' => $this->vendor('Active', 999)->id,
            'type' => 'Other', 'title' => 'Theirs',
        ], $this->user('admin', 999));

        Sanctum::actingAs($this->user('admin'));

        $ids = collect($this->getJson('/api/purchase/permits')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        $this->getJson("/api/purchase/permits/{$theirs->id}")->assertNotFound();
    }
}
