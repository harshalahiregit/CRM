<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Purchase\PurchaseWorkerCompetency;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseWorkforceService;
use App\Services\Purchase\PurchaseWorkPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purchase work packages, activities and work authorisation.
 *
 * Authorisation is DERIVED per call, never stored — a worker whose medical
 * lapses overnight must stop being authorised overnight rather than when
 * something remembers to clear a cached flag. These pin the parts that decide
 * whether someone may work.
 */
class PurchaseWorkPackageTest extends TestCase
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

    private function vendor(int $tenant = self::TENANT): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => $tenant, 'company_name' => 'V'.Str::random(4),
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(6).'@test.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function svc(): PurchaseWorkPackageService
    {
        return app(PurchaseWorkPackageService::class);
    }

    public function test_activities_are_ordered_on_append(): void
    {
        $v = $this->vendor();
        $pkg = $this->svc()->create(self::TENANT, ['purchase_vendor_id' => $v->id, 'name' => 'Shutdown'], null);

        $this->svc()->addActivity($pkg, ['name' => 'Isolate']);
        $this->svc()->addActivity($pkg->fresh(), ['name' => 'Weld']);
        $this->svc()->addActivity($pkg->fresh(), ['name' => 'Test']);

        // Appended, never supplied — two people adding activities must not both
        // claim the same position.
        $this->assertSame([1, 2, 3], $pkg->fresh()->activities->pluck('sort_order')->all());
    }

    public function test_a_reference_is_generated(): void
    {
        $pkg = $this->svc()->create(self::TENANT, ['name' => 'Referenced'], null);
        $this->assertMatchesRegularExpression('/^PWP-\d{4}-\d{3}$/', $pkg->reference);
    }

    public function test_authorisation_names_every_blocker_not_just_the_first(): void
    {
        $v = $this->vendor();
        $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => 'Fresh', 'status' => 'Active']);

        $out = $this->svc()->authorize($w->fresh());

        $this->assertFalse($out['authorized']);
        // "Not authorised" with no breakdown is unactionable — the person at the
        // barrier needs to know which things to go and fix.
        $this->assertGreaterThan(1, count($out['blockers']));
        $this->assertContains('Medical fitness', $out['blockers']);
        $this->assertContains('Entry badge', $out['blockers']);
    }

    public function test_competency_is_only_required_where_the_activity_names_one(): void
    {
        $v = $this->vendor();
        $pkg = $this->svc()->create(self::TENANT, ['purchase_vendor_id' => $v->id, 'name' => 'Pkg'], null);
        $plain = $this->svc()->addActivity($pkg, ['name' => 'Sweeping']);
        $skilled = $this->svc()->addActivity($pkg->fresh(), ['name' => 'Welding', 'required_competency' => 'Welder']);

        $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => 'Hand', 'status' => 'Active']);

        // Asking for a ticket no activity demands would block work for no
        // stated reason.
        $noneNeeded = collect($this->svc()->authorize($w->fresh(), $plain)['checks'])->firstWhere('key', 'competency');
        $this->assertFalse($noneNeeded['required']);

        $needed = collect($this->svc()->authorize($w->fresh(), $skilled)['checks'])->firstWhere('key', 'competency');
        $this->assertTrue($needed['required']);
        $this->assertFalse($needed['ok']);
    }

    public function test_an_expired_competency_does_not_count_but_an_expiring_one_does(): void
    {
        $v = $this->vendor();
        $pkg = $this->svc()->create(self::TENANT, ['purchase_vendor_id' => $v->id, 'name' => 'Pkg'], null);
        $act = $this->svc()->addActivity($pkg, ['name' => 'Welding', 'required_competency' => 'Welder']);
        $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => 'Welder', 'status' => 'Active']);

        $make = fn ($validUntil) => PurchaseWorkerCompetency::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $v->id,
            'purchase_worker_id' => $w->id, 'name' => 'Welder', 'valid_until' => $validUntil,
        ]);

        $expired = $make(now()->subMonth());
        $check = collect($this->svc()->authorize($w->fresh(), $act)['checks'])->firstWhere('key', 'competency');
        $this->assertFalse($check['ok']);
        $this->assertSame('Held but expired', $check['detail']);

        $expired->delete();
        // Expiring has NOT lapsed yet — refusing on it would stop work that is
        // legitimately covered today.
        $make(now()->addDays(10));
        $check = collect($this->svc()->authorize($w->fresh(), $act)['checks'])->firstWhere('key', 'competency');
        $this->assertTrue($check['ok']);
        $this->assertSame('Held, expiring soon', $check['detail']);
    }

    public function test_a_missing_work_package_is_advisory_not_blocking(): void
    {
        $v = $this->vendor();
        $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => 'Unassigned', 'status' => 'Active']);

        $check = collect($this->svc()->authorize($w->fresh())['checks'])->firstWhere('key', 'work_package');

        // An accountability gap worth showing, but not a reason to refuse
        // someone whose medical, induction and PPE are all in order.
        $this->assertFalse($check['required']);
        $this->assertNotContains('Work package', $this->svc()->authorize($w->fresh())['blockers']);
    }

    public function test_deleting_a_package_releases_its_workers(): void
    {
        $v = $this->vendor();
        $pkg = $this->svc()->create(self::TENANT, ['purchase_vendor_id' => $v->id, 'name' => 'Doomed'], null);
        $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => 'Assigned', 'status' => 'Active']);
        $this->svc()->assignWorker($w, $pkg->id);

        $this->assertSame($pkg->id, (int) $w->fresh()->work_package_id);

        $this->svc()->delete($pkg);

        // Leaving them pointed at a deleted package would make them accountable
        // to nothing.
        $this->assertNull($w->fresh()->work_package_id);
    }

    public function test_a_worker_cannot_be_assigned_to_another_tenants_package(): void
    {
        $theirs = $this->svc()->create(999, ['name' => 'Theirs'], null);
        $w = app(PurchaseWorkforceService::class)->create($this->vendor(), ['full_name' => 'Mine', 'status' => 'Active']);

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->svc()->assignWorker($w, $theirs->id);
    }

    public function test_work_packages_are_tenant_scoped_over_http(): void
    {
        $mine = $this->svc()->create(self::TENANT, ['name' => 'Mine'], null);
        $theirs = $this->svc()->create(999, ['name' => 'Theirs'], null);

        Sanctum::actingAs($this->user('admin'));

        $ids = collect($this->getJson('/api/purchase/work-packages')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        $this->getJson("/api/purchase/work-packages/{$theirs->id}")->assertNotFound();
    }

    public function test_the_roster_counts_authorised_against_blocked(): void
    {
        $v = $this->vendor();
        $pkg = $this->svc()->create(self::TENANT, ['purchase_vendor_id' => $v->id, 'name' => 'Crew'], null);
        foreach (['A', 'B'] as $n) {
            $w = app(PurchaseWorkforceService::class)->create($v, ['full_name' => $n, 'status' => 'Active']);
            $this->svc()->assignWorker($w, $pkg->id);
        }

        $roster = $this->svc()->roster(self::TENANT, ['work_package_id' => $pkg->id]);

        $this->assertSame(2, $roster['totals']['workers']);
        // Neither has a medical, induction, training or badge yet.
        $this->assertSame(0, $roster['totals']['authorized']);
        $this->assertSame(2, $roster['totals']['blocked']);
    }
}
