<?php

namespace Tests\Feature\Tpv;

use App\Models\Tenant;
use App\Models\Tpv\TpvSetting;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvViolationService;
use App\Support\Tpv\TpvSettings;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §26 — the violation ladder can be overridden per project/client on top of the
 * tenant ladder.
 */
class ViolationLadderPerProjectTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    public function test_project_override_replaces_the_steps_for_that_project(): void
    {
        // Store a tenant override that carries a stricter ladder for one project.
        TpvSetting::create([
            'tenant_id' => self::TENANT, 'group' => 'violation_ladder',
            'payload' => [
                'project_overrides' => [
                    'Refinery' => ['steps' => [
                        ['points' => 0, 'level' => 'None'],
                        ['points' => 3, 'level' => 'Suspension'],
                    ]],
                ],
            ],
        ]);

        $settings = app(TpvSettings::class);

        $base = $settings->violationLadderFor(null, self::TENANT)['steps'];
        $refinery = $settings->violationLadderFor('Refinery', self::TENANT)['steps'];

        // The Refinery ladder is the stricter override, not the tenant baseline.
        $this->assertNotSame($base, $refinery);
        $this->assertSame('Suspension', $refinery[1]['level']);
        $this->assertSame(3, $refinery[1]['points']);

        // An unknown project falls back to the tenant ladder.
        $this->assertSame($base, $settings->violationLadderFor('Township', self::TENANT)['steps']);
    }

    /**
     * The genuine end-to-end proof: auto-escalation on record() actually honours the
     * project override (keyed by the violation's project_id), suspending a vendor at
     * a point total the tenant default ladder would leave Active.
     */
    public function test_auto_escalation_applies_the_project_override(): void
    {
        $projectId = 77;

        // A stricter ladder for project 77 only: Suspension at just 5 points. The
        // tenant default suspends far higher (10), so 6 points would NOT suspend
        // without this override.
        TpvSetting::create([
            'tenant_id' => self::TENANT, 'group' => 'violation_ladder',
            'payload' => [
                'project_overrides' => [
                    (string) $projectId => ['steps' => [
                        ['points' => 0, 'level' => 'None'],
                        ['points' => 5, 'level' => 'Suspension'],
                    ]],
                ],
            ],
        ]);
        app(TpvSettings::class)->forget(self::TENANT, 'violation_ladder');

        $admin = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
        $vendor = Vendor::create(['tenant_id' => self::TENANT, 'company_name' => 'Acme', 'status' => VendorStatus::ACTIVE]);
        $svc = app(TpvViolationService::class);

        // Major(2) + Critical(4) = 6 points, both on project 77.
        $svc->record(['vendor_id' => $vendor->id, 'project_id' => $projectId, 'type' => 'PPE_Violation', 'severity' => 'Major'], self::TENANT, $admin->id);
        $svc->record(['vendor_id' => $vendor->id, 'project_id' => $projectId, 'type' => 'Unsafe_Work', 'severity' => 'Critical'], self::TENANT, $admin->id);

        // The project override drove an auto-suspension the tenant default would not.
        $this->assertSame(VendorStatus::SUSPENDED, $vendor->fresh()->status);
    }
}
