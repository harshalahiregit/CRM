<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvViolationService;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 9 — "Repeated Violations Escalate" (Sangoe TPV §26/§36). Recording a
 * violation that pushes a vendor's cumulative OPEN points across a ladder
 * threshold now AUTO-applies the escalation (Suspension at 10, Blacklist at 13)
 * — no manual admin step. Ladder: 1 Warning · 3/5/7 Strikes · 10 Suspension · 13 Blacklist.
 */
class ViolationEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function actor(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    private function record(Vendor $v, int $points, User $actor): void
    {
        app(TpvViolationService::class)->record([
            'vendor_id' => $v->id, 'type' => 'PPE_Violation', 'severity' => 'Minor',
            'description' => 'test', 'points' => $points, 'status' => 'Open',
        ], self::TENANT, $actor->id);
    }

    public function test_crossing_the_suspension_threshold_auto_suspends(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 10, $actor); // ≥10 → Suspension
        $this->assertSame(VendorStatus::SUSPENDED, $v->fresh()->status);
    }

    public function test_crossing_the_blacklist_threshold_auto_blacklists(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 10, $actor);           // Suspension
        $this->record($v, 5, $actor);            // total 15 ≥13 → Blacklist
        $this->assertSame(VendorStatus::BLACKLISTED, $v->fresh()->status);
    }

    public function test_below_threshold_leaves_vendor_active(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 3, $actor); // Strike_1, below Suspension
        $this->assertSame(VendorStatus::ACTIVE, $v->fresh()->status);
    }
}
