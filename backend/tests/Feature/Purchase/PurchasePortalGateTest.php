<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseGateScan;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Purchase vendor can see whether its own people got on site.
 *
 * Purchase built the whole gate engine — two tables, a service, seven admin
 * routes — and exposed none of it to the vendor. TPV's portal has shown
 * attendance, the gate log and the on-site roster since the portal existed, so
 * the one party who most needs to know whether their workers were admitted
 * could not see it on Purchase and could on TPV.
 *
 * Two properties matter here and are the reason this file exists:
 *
 *   1. The scope comes from the TOKEN, never the request. A vendor asking about
 *      another vendor's workers gets its own figures, not theirs — passing
 *      `vendor_id` cannot widen anything.
 *   2. It is READ ONLY. Recording a crossing is the security desk's act; a
 *      vendor able to write its own scans could manufacture attendance, which
 *      is the one thing an attendance record must not allow.
 */
class PurchasePortalGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private PurchaseVendor $vendor;

    private PurchaseVendor $rival;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->vendor = $this->vendor('Southgate Industrial');
        $this->rival = $this->vendor('Rival Ltd');
    }

    private function vendor(string $name): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'purchase_vendor_code' => 'PV-'.strtoupper(Str::random(6)),
            'email' => Str::random(5).'@t.local',
            'status' => 'Active', 'portal_status' => 'active',
        ]);
    }

    private function worker(PurchaseVendor $vendor, string $name): PurchaseWorker
    {
        return PurchaseWorker::create([
            'tenant_id' => self::TENANT, 'purchase_vendor_id' => $vendor->id,
            'full_name' => $name, 'designation' => 'Fitter', 'status' => 'Active',
        ]);
    }

    /** A crossing at the gate, allowed unless said otherwise. */
    private function scan(PurchaseWorker $w, string $action = 'in', string $decision = PurchaseGateScan::ALLOW, ?string $at = null): PurchaseGateScan
    {
        return PurchaseGateScan::create([
            'tenant_id' => self::TENANT,
            'purchase_vendor_id' => $w->purchase_vendor_id,
            'purchase_worker_id' => $w->id,
            'decision' => $decision, 'action' => $action,
            'scanned_at' => $at ?: now(),
        ]);
    }

    /* ── the vendor sees its own ─────────────────────────────────────────── */

    public function test_a_vendor_sees_its_own_gate_log(): void
    {
        $mine = $this->worker($this->vendor, 'Rita Bose');
        $this->scan($mine, 'in');

        Sanctum::actingAs($this->vendor);

        $rows = $this->getJson('/api/portal/purchase/gate-log')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $mine->id, (int) $rows[0]['purchase_worker_id']);
    }

    public function test_the_stats_count_only_this_vendors_people(): void
    {
        $this->scan($this->worker($this->vendor, 'Rita Bose'), 'in');
        $this->scan($this->worker($this->rival, 'Someone Else'), 'in');

        Sanctum::actingAs($this->vendor);

        $stats = $this->getJson('/api/portal/purchase/gate/stats')->assertOk()->json();

        $this->assertSame(1, $stats['scans'], "the rival's crossing is not this vendor's business");
        $this->assertSame(1, $stats['on_site']);
    }

    public function test_the_on_site_roster_is_this_vendors_only(): void
    {
        $mine = $this->worker($this->vendor, 'Rita Bose');
        $this->scan($mine, 'in');
        $this->scan($this->worker($this->rival, 'Someone Else'), 'in');

        Sanctum::actingAs($this->vendor);

        $rows = $this->getJson('/api/portal/purchase/gate/on-site')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $mine->id, (int) $rows[0]['purchase_worker_id']);
    }

    /** Somebody who went in and came out again is no longer on site. */
    public function test_a_worker_who_left_is_not_counted_as_on_site(): void
    {
        $w = $this->worker($this->vendor, 'Rita Bose');
        $this->scan($w, 'in', at: now()->subHours(3));
        $this->scan($w, 'out', at: now()->subHour());

        Sanctum::actingAs($this->vendor);

        $this->assertSame(0, $this->getJson('/api/portal/purchase/gate/stats')->assertOk()->json('on_site'));
    }

    /** A refused badge is not attendance. */
    public function test_a_denied_scan_is_not_counted_as_present(): void
    {
        $this->scan($this->worker($this->vendor, 'Rita Bose'), 'in', PurchaseGateScan::DENY);

        Sanctum::actingAs($this->vendor);
        $stats = $this->getJson('/api/portal/purchase/gate/stats')->assertOk()->json();

        $this->assertSame(1, $stats['scans']);
        $this->assertSame(1, $stats['denied']);
        $this->assertSame(0, $stats['on_site'], 'a badge turned away did not get in');
    }

    public function test_a_vendor_can_read_one_of_its_own_workers_attendance(): void
    {
        $w = $this->worker($this->vendor, 'Rita Bose');
        $this->scan($w, 'in', at: now()->subHours(4));
        $this->scan($w, 'out', at: now()->subHour());

        Sanctum::actingAs($this->vendor);

        $this->getJson("/api/portal/purchase/workers/{$w->id}/attendance")->assertOk();
    }

    /* ── the shapes the shared screens read ──────────────────────────────── */

    /**
     * The portal's worker list must carry its rows where the register looks.
     *
     * `PurchaseWorkers` is one component serving the admin module and the portal.
     * The admin list answers a plain array; the portal answers
     * `{workers, summary}`. The component reads `data ?? body` and requires an
     * array, so the portal shape fell through to [] — a vendor registered a
     * worker, the save returned 201, and the register stayed empty. Nothing
     * errored, which is what made it read as "registration is broken".
     *
     * `purchasePortalApi.workforce.workers` unwraps it. This pins the server
     * half of that contract so the unwrapping cannot quietly stop matching.
     */
    public function test_the_portal_worker_list_carries_its_rows_under_workers(): void
    {
        $this->worker($this->vendor, 'Rita Bose');

        Sanctum::actingAs($this->vendor);
        $body = $this->getJson('/api/portal/purchase/workers')->assertOk()->json();

        $this->assertArrayHasKey('workers', $body, 'the client unwraps this key');
        $this->assertCount(1, $body['workers']);
        $this->assertSame('Rita Bose', $body['workers'][0]['full_name']);
    }

    /**
     * And one worker comes back flat, with readiness merged.
     *
     * The admin wraps the same thing as `{worker, readiness, badge}`, so the
     * client rebuilds that wrapper. If the server ever starts wrapping it too,
     * the client passes it straight through — but this records which shape it is
     * actually reconciling.
     */
    public function test_the_portal_single_worker_is_flat_with_readiness(): void
    {
        $w = $this->worker($this->vendor, 'Rita Bose');

        Sanctum::actingAs($this->vendor);
        $body = $this->getJson("/api/portal/purchase/workers/{$w->id}")->assertOk()->json();

        $this->assertSame('Rita Bose', $body['full_name'] ?? null, 'flat, not wrapped in `worker`');
        $this->assertArrayHasKey('readiness', $body);
        $this->assertArrayNotHasKey('worker', $body);
    }

    /* ── and nothing beyond it ───────────────────────────────────────────── */

    /**
     * The scope is the token's, so a supplied vendor_id cannot reach across.
     */
    public function test_a_supplied_vendor_id_cannot_widen_the_scope(): void
    {
        $this->scan($this->worker($this->rival, 'Someone Else'), 'in');

        Sanctum::actingAs($this->vendor);

        $rows = $this->getJson("/api/portal/purchase/gate-log?vendor_id={$this->rival->id}")
            ->assertOk()->json('data');

        $this->assertSame([], $rows, "asking about someone else's people returns your own, which is none");
    }

    public function test_a_vendor_cannot_read_anothers_workers_attendance(): void
    {
        $theirs = $this->worker($this->rival, 'Someone Else');
        $this->scan($theirs, 'in');

        Sanctum::actingAs($this->vendor);

        $this->getJson("/api/portal/purchase/workers/{$theirs->id}/attendance")->assertNotFound();
    }

    /**
     * Recording a crossing stays the security desk's act.
     *
     * If a vendor could write its own gate scans it could manufacture the very
     * attendance these screens report.
     */
    public function test_a_vendor_cannot_record_a_gate_crossing(): void
    {
        $w = $this->worker($this->vendor, 'Rita Bose');

        Sanctum::actingAs($this->vendor);

        // There is no write route on the portal at all — the router answers 405
        // because only GETs live under /gate. What matters is the refusal, not
        // which flavour of it, so this asserts the outcome rather than the code.
        $portal = $this->postJson('/api/portal/purchase/gate/events', [
            'kind' => 'visitor', 'action' => 'in',
        ]);
        $this->assertTrue($portal->status() >= 400, 'a vendor may not record a crossing');

        // And the admin gate refuses the vendor identity outright.
        $this->postJson("/api/purchase/gate/workers/{$w->id}/scan")->assertForbidden();

        $this->assertSame(0, PurchaseGateScan::count());
    }

    /** The admin's tenant-wide view is unchanged by the vendor scoping. */
    public function test_the_admin_still_sees_every_vendor(): void
    {
        $this->scan($this->worker($this->vendor, 'Rita Bose'), 'in');
        $this->scan($this->worker($this->rival, 'Someone Else'), 'in');

        $admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Priya Nair', 'role' => 'admin',
            'email' => 'a-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->assertSame(2, $this->getJson('/api/purchase/gate/stats')->assertOk()->json('scans'),
            'adding an optional vendor filter must not narrow the admin view');
    }
}
