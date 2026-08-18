<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvGateScan;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Models\Vendor\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The vendor-scoped filters behind the Gate Log and Strikes sections of the
 * vendor detail page.
 *
 * A scan and a strike both belong to a WORKER; a vendor owns workers. Rather
 * than give the vendor screen its own query, the existing tenant-wide endpoints
 * gained a vendor_id filter that resolves through that relation — so there is
 * one gate log and one strike list, viewed two ways.
 *
 * Workforce / Medical / Training need no test here: they read
 * GET /tpv/workers?vendor_id=, which already filtered by vendor.
 */
class TpvVendorSectionFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function user(string $role): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'tenant_id' => self::TENANT, 'company_name' => $name,
            'email' => strtolower($name).'@test.local', 'status' => 'Active',
        ]);
    }

    private function worker(Vendor $v, string $name): TpvWorker
    {
        return TpvWorker::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $v->id,
            'name' => $name, 'worker_code' => 'W-'.Str::random(5), 'status' => 'Active',
        ]);
    }

    public function test_workers_are_filtered_to_the_requested_vendor(): void
    {
        $a = $this->vendor('AlphaCo');
        $b = $this->vendor('BetaCo');
        $this->worker($a, 'Alpha Worker');
        $this->worker($b, 'Beta Worker');

        Sanctum::actingAs($this->user('admin'));

        $res = $this->getJson('/api/tpv/workers?vendor_id='.$a->id)->assertOk();

        $names = collect($res->json('data') ?? $res->json())->pluck('name');
        $this->assertContains('Alpha Worker', $names);
        $this->assertNotContains('Beta Worker', $names);
    }

    public function test_gate_log_is_filtered_to_the_vendors_workers(): void
    {
        $a  = $this->vendor('GateA');
        $b  = $this->vendor('GateB');
        $wa = $this->worker($a, 'A Worker');
        $wb = $this->worker($b, 'B Worker');

        foreach ([[$wa, 'admit'], [$wb, 'deny']] as [$w, $decision]) {
            TpvGateScan::create([
                'tenant_id' => self::TENANT, 'tpv_worker_id' => $w->id,
                'decision' => $decision, 'scanned_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->user('admin'));

        $rows = collect($this->getJson('/api/tpv/gate-log?vendor_id='.$a->id)->assertOk()->json());
        $ids  = $rows->pluck('tpv_worker_id');

        $this->assertTrue($ids->contains($wa->id));
        $this->assertFalse($ids->contains($wb->id), 'Another vendor\'s scans must not appear.');
    }

    public function test_strikes_are_filtered_to_the_vendors_workers(): void
    {
        $a  = $this->vendor('StrikeA');
        $b  = $this->vendor('StrikeB');
        $wa = $this->worker($a, 'A Worker');
        $wb = $this->worker($b, 'B Worker');

        foreach ([$wa, $wb] as $w) {
            TpvSafetyStrike::create([
                'tenant_id' => self::TENANT, 'tpv_worker_id' => $w->id,
                'severity' => 'minor', 'reason' => 'Test', 'occurred_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->user('admin'));

        $ids = collect($this->getJson('/api/tpv/strikes?vendor_id='.$a->id)->assertOk()->json())
            ->pluck('tpv_worker_id');

        $this->assertTrue($ids->contains($wa->id));
        $this->assertFalse($ids->contains($wb->id));
    }

    /** Omitting the filter must keep the tenant-wide behaviour these screens rely on. */
    public function test_no_vendor_filter_still_returns_the_whole_tenant(): void
    {
        $a  = $this->vendor('WideA');
        $b  = $this->vendor('WideB');
        $wa = $this->worker($a, 'A Worker');
        $wb = $this->worker($b, 'B Worker');

        foreach ([$wa, $wb] as $w) {
            TpvSafetyStrike::create([
                'tenant_id' => self::TENANT, 'tpv_worker_id' => $w->id,
                'severity' => 'minor', 'reason' => 'Test', 'occurred_at' => now(),
            ]);
        }

        Sanctum::actingAs($this->user('admin'));

        $this->assertCount(2, $this->getJson('/api/tpv/strikes')->assertOk()->json());
    }

    /** These are admin screens — the vendor roles must not reach them. */
    public function test_vendor_roles_cannot_read_the_admin_sections(): void
    {
        $v = $this->vendor('DenyCo');

        foreach (['third_party_vendor', 'vendor'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson('/api/tpv/gate-log?vendor_id='.$v->id)->assertForbidden();
            $this->getJson('/api/tpv/strikes?vendor_id='.$v->id)->assertForbidden();
        }
    }
}
