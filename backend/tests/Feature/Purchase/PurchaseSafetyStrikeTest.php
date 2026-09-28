<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseSafetyStrike;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseWorker;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Safety strikes on the Purchase side.
 *
 * TPV has had this since July; Purchase had no strikes engine at all — no
 * table, model or service — so a repeat safety offender on a Purchase crew
 * could be sent home and nothing anywhere recorded it. The next site had no
 * way to know.
 *
 * The rules worth pinning are the ones that end somebody's site access: three
 * active strikes, one Critical, and the fact that voiding stops a strike
 * counting without letting a terminated worker back in.
 */
class PurchaseSafetyStrikeTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant)->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function staff(): User
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staff', 'role' => 'staff',
            'email' => 'staff-'.Str::random(5).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function worker(string $name = 'Ramesh', int $tenantId = self::TENANT): PurchaseWorker
    {
        $vendor = PurchaseVendor::create([
            'tenant_id' => $tenantId, 'company_name' => 'Alpha Contractors',
            'purchase_vendor_code' => 'PV-'.Str::random(5), 'status' => 'Active',
        ]);

        return PurchaseWorker::create([
            'tenant_id' => $tenantId, 'purchase_vendor_id' => $vendor->id,
            'full_name' => $name, 'worker_code' => 'W-'.Str::random(5), 'status' => 'Active',
        ]);
    }

    private function issue(PurchaseWorker $worker, array $data = []): array
    {
        return $this->postJson("/api/purchase/workforce/workers/{$worker->id}/strikes", [
            'severity' => 'Minor', 'reason' => 'No harness at height', ...$data,
        ])->assertCreated()->json();
    }

    /* ── Issuing ────────────────────────────────────────────────────────── */

    public function test_a_strike_is_recorded_against_a_worker(): void
    {
        $worker = $this->worker();
        $this->admin();

        $out = $this->issue($worker);

        $this->assertSame('Minor', $out['strike']['severity']);
        $this->assertSame(1, $out['active_count']);
        $this->assertFalse($out['terminated']);
        $this->assertSame('Active', $worker->fresh()->status, 'one strike is not a termination');
    }

    public function test_the_third_active_strike_terminates_site_access(): void
    {
        $worker = $this->worker();
        $this->admin();

        $this->issue($worker);
        $this->issue($worker, ['reason' => 'Second']);
        $out = $this->issue($worker, ['reason' => 'Third']);

        $this->assertTrue($out['terminated']);
        $this->assertTrue($out['strike']['triggered_termination']);
        $this->assertSame('Terminated', $worker->fresh()->status);
    }

    public function test_one_critical_strike_terminates_on_its_own(): void
    {
        $worker = $this->worker();
        $this->admin();

        $out = $this->issue($worker, ['severity' => 'Critical', 'reason' => 'Bypassed a lockout']);

        $this->assertTrue($out['terminated']);
        $this->assertSame('Terminated', $worker->fresh()->status);
    }

    public function test_an_already_terminated_worker_cannot_be_struck_again(): void
    {
        $worker = $this->worker();
        $this->admin();
        $this->issue($worker, ['severity' => 'Critical', 'reason' => 'Bypassed a lockout']);

        $this->postJson("/api/purchase/workforce/workers/{$worker->id}/strikes", [
            'severity' => 'Minor', 'reason' => 'Again',
        ])->assertStatus(422);
    }

    public function test_a_strike_cannot_be_dated_in_the_future(): void
    {
        // A strike records something that happened.
        $worker = $this->worker();
        $this->admin();

        $this->postJson("/api/purchase/workforce/workers/{$worker->id}/strikes", [
            'severity' => 'Minor', 'reason' => 'Later', 'occurred_at' => now()->addDay()->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_an_unknown_severity_is_refused(): void
    {
        $worker = $this->worker();
        $this->admin();

        $this->postJson("/api/purchase/workforce/workers/{$worker->id}/strikes", [
            'severity' => 'Catastrophic', 'reason' => 'Made up',
        ])->assertStatus(422);
    }

    /* ── Voiding ────────────────────────────────────────────────────────── */

    public function test_voiding_stops_a_strike_counting_but_keeps_the_row(): void
    {
        $worker = $this->worker();
        $this->admin();
        $first = $this->issue($worker);
        $this->issue($worker, ['reason' => 'Second']);

        $this->postJson("/api/purchase/strikes/{$first['strike']['id']}/void", [
            'reason' => 'Appeal upheld — wrong worker identified',
        ])->assertOk();

        // The row is still there; it simply no longer counts.
        $this->assertDatabaseHas('purchase_worker_strikes', ['id' => $first['strike']['id']]);
        $this->assertSame(1, PurchaseSafetyStrike::where('purchase_worker_id', $worker->id)->whereNull('voided_at')->count());

        // And so the next strike is the third ISSUED but only the second ACTIVE.
        $out = $this->issue($worker, ['reason' => 'Third']);
        $this->assertFalse($out['terminated'], 'a voided strike must not count towards the limit');
    }

    public function test_voiding_does_not_reinstate_a_terminated_worker(): void
    {
        // Restoring site access is a deliberate separate act — an appeal on one
        // strike must never quietly put somebody back on site.
        $worker = $this->worker();
        $this->admin();
        $out = $this->issue($worker, ['severity' => 'Critical', 'reason' => 'Bypassed a lockout']);

        $this->postJson("/api/purchase/strikes/{$out['strike']['id']}/void", ['reason' => 'Appeal upheld'])
            ->assertOk();

        $this->assertSame('Terminated', $worker->fresh()->status);
    }

    public function test_a_strike_cannot_be_voided_twice(): void
    {
        $worker = $this->worker();
        $this->admin();
        $out = $this->issue($worker);

        $this->postJson("/api/purchase/strikes/{$out['strike']['id']}/void", ['reason' => 'First'])->assertOk();
        $this->postJson("/api/purchase/strikes/{$out['strike']['id']}/void", ['reason' => 'Again'])->assertStatus(422);
    }

    /* ── Reading ────────────────────────────────────────────────────────── */

    public function test_the_ledger_can_be_narrowed_to_one_vendors_crew(): void
    {
        // What the vendor-detail tab asks for: every strike across the workers
        // this vendor owns.
        $mine   = $this->worker('Mine');
        $theirs = $this->worker('Theirs');
        $this->admin();
        $this->issue($mine);
        $this->issue($theirs);

        $rows = $this->getJson("/api/purchase/strikes?vendor_id={$mine->purchase_vendor_id}")->assertOk()->json();

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['worker']['full_name']);
    }

    public function test_staff_may_read_the_ledger_but_not_issue(): void
    {
        // Reading is how a supervisor checks; issuing ends site access, so it is
        // admin authority — the same split TPV uses.
        $worker = $this->worker();
        $this->admin();
        $this->issue($worker);

        $this->staff();
        $this->getJson('/api/purchase/strikes')->assertOk();
        $this->postJson("/api/purchase/workforce/workers/{$worker->id}/strikes", [
            'severity' => 'Minor', 'reason' => 'Not mine to give',
        ])->assertForbidden();
    }

    public function test_another_workspaces_worker_is_not_theirs_to_strike(): void
    {
        (new Tenant)->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $theirs = $this->worker('Theirs', 2);
        $this->admin();

        $this->postJson("/api/purchase/workforce/workers/{$theirs->id}/strikes", [
            'severity' => 'Minor', 'reason' => 'Reaching across',
        ])->assertNotFound();
    }

    public function test_stats_count_active_voided_and_terminations_apart(): void
    {
        $a = $this->worker('A');
        $b = $this->worker('B');
        $this->admin();
        $first = $this->issue($a);
        $this->issue($b, ['severity' => 'Critical', 'reason' => 'Lockout']);
        $this->postJson("/api/purchase/strikes/{$first['strike']['id']}/void", ['reason' => 'Appeal'])->assertOk();

        $stats = $this->getJson('/api/purchase/strikes/stats')->assertOk()->json();

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['active']);
        $this->assertSame(1, $stats['voided']);
        $this->assertSame(1, $stats['terminations']);
        $this->assertSame(3, $stats['rules']['limit'], 'the shipped policy, until a tenant overrides it');
    }
}
