<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Purchase\PurchaseViolationService;
use App\Support\Purchase\PurchaseVendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rule 9 — "Repeated Violations Escalate" on the PURCHASE side (parity with
 * ViolationEscalationTest). Recording a violation that pushes a purchase vendor's
 * cumulative OPEN points across a ladder threshold now AUTO-applies the escalation
 * (On_Hold at 10, Blacklist at 13) — previously it never escalated automatically.
 */
class PurchaseViolationEscalationTest extends TestCase
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

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id' => self::TENANT, 'company_name' => 'Beta',
            'email' => 'b-'.Str::random(4).'@pv.local',
            'purchase_vendor_code' => 'PUR-'.Str::random(6), 'status' => PurchaseVendorStatus::ACTIVE,
        ]);
    }

    private function record(PurchaseVendor $v, int $points, User $actor): void
    {
        app(PurchaseViolationService::class)->record([
            'purchase_vendor_id' => $v->id, 'type' => 'PPE_Violation', 'severity' => 'Minor',
            'description' => 'test', 'points' => $points, 'status' => 'Open',
        ], self::TENANT, $actor->id);
    }

    public function test_crossing_the_suspension_threshold_auto_holds(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 10, $actor); // ≥10 → Suspension = On_Hold
        $this->assertSame(PurchaseVendorStatus::ON_HOLD, $v->fresh()->status);
    }

    public function test_crossing_the_blacklist_threshold_auto_blacklists(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 10, $actor);
        $this->record($v, 5, $actor); // total 15 ≥13 → Blacklist
        $this->assertSame(PurchaseVendorStatus::BLACKLISTED, $v->fresh()->status);
    }

    public function test_below_threshold_leaves_vendor_active(): void
    {
        $actor = $this->actor();
        $v = $this->vendor();
        $this->record($v, 3, $actor); // below Suspension
        $this->assertSame(PurchaseVendorStatus::ACTIVE, $v->fresh()->status);
    }
}
