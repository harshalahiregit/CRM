<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvSetting;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvViolationService;
use App\Support\Tpv\TpvSettings;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §26 / Rule 9 — the violation escalation ladder is tenant-configurable through
 * TpvSettings. Overriding the thresholds changes the escalation level (and the
 * auto-suspension it drives); overriding severity points changes how much a
 * violation scores. With no override the shipped default is used unchanged.
 */
class ViolationLadderConfigTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function vendor(): Vendor
    {
        return Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
    }

    private function override(array $payload): void
    {
        TpvSetting::create(['tenant_id' => self::TENANT, 'group' => 'violation_ladder', 'payload' => $payload]);
        app(TpvSettings::class)->forget(self::TENANT, 'violation_ladder');
    }

    public function test_default_ladder_scores_and_levels(): void
    {
        $v = $this->vendor();
        $admin = $this->admin();
        // Major(2) + Critical(4) = 6 points → Strike_2 (5) on the default ladder; below Suspension(10).
        app(TpvViolationService::class)->record(['vendor_id' => $v->id, 'type' => 'PPE_Violation', 'severity' => 'Major'], self::TENANT, $admin->id);
        app(TpvViolationService::class)->record(['vendor_id' => $v->id, 'type' => 'Unsafe_Work', 'severity' => 'Critical'], self::TENANT, $admin->id);

        $esc = app(TpvViolationService::class)->escalationFor(self::TENANT, $v->id);
        $this->assertSame(6, $esc['open_points']);
        $this->assertSame('Strike_2', $esc['level']);
        $this->assertSame(VendorStatus::ACTIVE, $v->fresh()->status, 'default ladder must not suspend at 6 points');
    }

    public function test_overridden_thresholds_change_the_level_and_auto_suspend(): void
    {
        // Lower the ladder so 5 points already means Suspension.
        $this->override([
            'severity_points' => ['Minor' => 1, 'Major' => 2, 'Critical' => 4],
            'steps' => [
                ['points' => 0, 'level' => 'None'],
                ['points' => 3, 'level' => 'Strike_1'],
                ['points' => 5, 'level' => 'Suspension'],
            ],
        ]);

        $v = $this->vendor();
        $admin = $this->admin();
        app(TpvViolationService::class)->record(['vendor_id' => $v->id, 'type' => 'PPE_Violation', 'severity' => 'Major'], self::TENANT, $admin->id);
        app(TpvViolationService::class)->record(['vendor_id' => $v->id, 'type' => 'Unsafe_Work', 'severity' => 'Critical'], self::TENANT, $admin->id);

        $esc = app(TpvViolationService::class)->escalationFor(self::TENANT, $v->id);
        $this->assertSame('Suspension', $esc['level']);
        $this->assertSame(VendorStatus::SUSPENDED, $v->fresh()->status, 'the lowered ladder must auto-suspend at 6 points');
    }

    public function test_overridden_severity_points(): void
    {
        $this->override([
            'severity_points' => ['Minor' => 5, 'Major' => 8, 'Critical' => 12],
            'steps' => [['points' => 0, 'level' => 'None'], ['points' => 1, 'level' => 'Warning']],
        ]);

        $v = $this->vendor();
        app(TpvViolationService::class)->record(['vendor_id' => $v->id, 'type' => 'PPE_Violation', 'severity' => 'Minor'], self::TENANT, $this->admin()->id);

        $this->assertSame(5, (int) TpvVendorViolation::where('vendor_id', $v->id)->value('points'));
    }
}
