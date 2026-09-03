<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseGateScan;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Purchase site gate.
 *
 * Purchase could already decide whether a worker may enter; nothing recorded
 * that the question had been asked, so there was no gate log and no attendance.
 * These cover the part that makes both possible — and the two rules that are
 * easy to get subtly wrong:
 *
 *   1. a REFUSAL is not a crossing, so it must not move the in/out direction
 *      or put anyone on the on-site roster;
 *   2. the decision is STORED, not re-derived, so a scan recorded under old
 *      rules still reads the way it was decided.
 */
class PurchaseGateTest extends TestCase
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

    /** A worker the gate will admit — badged, active, no lapsed medical. */
    private function badgedWorker(PurchaseVendor $v): PurchaseWorker
    {
        $w = PurchaseWorker::create([
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id,
            'full_name' => 'W'.Str::random(4), 'status' => 'Active',
            'worker_code' => 'PW-'.Str::random(5),
        ]);
        $w->forceFill([
            'badge_number' => 'PB-'.Str::random(5),
            'qr_token'     => Str::random(20),
        ])->save();

        return $w->fresh();
    }

    private function gate(): PurchaseGateService
    {
        return app(PurchaseGateService::class);
    }

    public function test_an_unbadged_worker_is_denied_and_the_reason_is_recorded(): void
    {
        $v = $this->vendor();
        $w = PurchaseWorker::create([
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id,
            'full_name' => 'No Badge', 'status' => 'Active', 'worker_code' => 'PW-'.Str::random(5),
        ]);

        $scan = $this->gate()->scan($w)['scan'];

        $this->assertSame('deny', $scan->decision);
        // The reason is stored, not left to be re-derived — a refusal has to be
        // explainable to the person standing at the barrier.
        $this->assertNotEmpty($scan->reasons);
        $this->assertStringContainsString('badge', strtolower($scan->reasons[0]));
    }

    public function test_a_refusal_does_not_move_the_direction_or_the_roster(): void
    {
        $v = $this->vendor();
        $w = $this->badgedWorker($v);

        // Admitted entry.
        $in = $this->gate()->scan($w)['scan'];
        $this->assertSame('allow', $in->decision);
        $this->assertSame('in', $in->action);

        // Now break the badge so the next scan is refused.
        $w->forceFill(['status' => 'Suspended'])->save();
        $denied = $this->gate()->scan($w->fresh())['scan'];
        $this->assertSame('deny', $denied->decision);

        // The refusal must not have counted as the exit: restore the worker and
        // the next admitted scan is still the OUT of the original entry.
        $w->forceFill(['status' => 'Active'])->save();
        $next = $this->gate()->scan($w->fresh())['scan'];
        $this->assertSame('out', $next->action, 'A refusal must not consume the in/out alternation.');
    }

    public function test_on_site_roster_counts_only_workers_whose_last_allowed_scan_was_an_entry(): void
    {
        $v  = $this->vendor();
        $in = $this->badgedWorker($v);
        $gone = $this->badgedWorker($v);

        $this->gate()->scan($in);                 // in, still on site
        $this->gate()->scan($gone);               // in
        $this->gate()->scan($gone->fresh());      // out again

        $roster = $this->gate()->onSite(self::TENANT);

        $this->assertCount(1, $roster);
        $this->assertSame($in->id, (int) $roster->first()->purchase_worker_id);
    }

    public function test_attendance_leaves_hours_null_when_a_day_has_no_exit(): void
    {
        $v = $this->vendor();
        $w = $this->badgedWorker($v);

        $this->gate()->scan($w);   // entry only — never scanned out

        $att = $this->gate()->workerAttendance($w->fresh());

        $this->assertCount(1, $att['days']);
        // Counting an open day to "now" would grow all evening for someone who
        // simply forgot to scan out.
        $this->assertNull($att['days'][0]['hours']);
        $this->assertSame(1, $att['totals']['days_present']);
    }

    public function test_stats_separate_allowed_from_denied(): void
    {
        $v = $this->vendor();
        $ok = $this->badgedWorker($v);
        $no = PurchaseWorker::create([
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id,
            'full_name' => 'Denied', 'status' => 'Active', 'worker_code' => 'PW-'.Str::random(5),
        ]);

        $this->gate()->scan($ok);
        $this->gate()->scan($no);

        $stats = $this->gate()->stats(self::TENANT);

        $this->assertSame(2, $stats['scans']);
        $this->assertSame(1, $stats['allowed']);
        $this->assertSame(1, $stats['denied']);
        $this->assertSame(1, $stats['on_site']);
    }

    public function test_the_gate_log_is_tenant_scoped(): void
    {
        $mine   = $this->badgedWorker($this->vendor(self::TENANT));
        $theirs = $this->badgedWorker($this->vendor(999));

        $this->gate()->scan($mine);
        $this->gate()->scan($theirs);

        Sanctum::actingAs($this->user('admin'));
        $res = $this->getJson('/api/purchase/gate/log')->assertOk();

        $ids = collect($res->json('data'))->pluck('purchase_worker_id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'The gate log must never cross tenants.');
    }

    public function test_a_worker_from_another_tenant_cannot_be_scanned(): void
    {
        $theirs = $this->badgedWorker($this->vendor(999));

        Sanctum::actingAs($this->user('admin'));

        // 404 rather than 403 — the same existence-hiding the rest of the
        // Purchase admin API uses.
        $this->postJson("/api/purchase/gate/workers/{$theirs->id}/scan")->assertNotFound();
        $this->assertSame(0, PurchaseGateScan::where('purchase_worker_id', $theirs->id)->count());
    }

    public function test_a_non_person_crossing_is_recorded(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/purchase/gate/events', [
            'event_kind' => 'material',
            'direction'  => 'in',
            'label'      => 'Cement',
            'quantity'   => 40,
            'unit'       => 'bags',
        ])->assertCreated();

        $this->getJson('/api/purchase/gate/events')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Cement');
    }

    public function test_an_unknown_event_kind_is_rejected(): void
    {
        Sanctum::actingAs($this->user('admin'));

        $this->postJson('/api/purchase/gate/events', [
            'event_kind' => 'spaceship',
            'direction'  => 'in',
        ])->assertStatus(422);
    }
}
