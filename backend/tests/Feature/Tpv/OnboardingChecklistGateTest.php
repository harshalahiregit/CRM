<?php

namespace Tests\Feature\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvOnboardingService;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use App\Support\Vendor\VendorStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §10 — the general onboarding checklist genuinely GATES activation: approve()
 * refuses to activate a vendor while a required checklist item is unticked, and
 * succeeds once every item is ticked. This is the doc's "checklist must be
 * complete before activation" enforced end-to-end, not merely displayed.
 */
class OnboardingChecklistGateTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    private function admin(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin-'.Str::random(6).'@test.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    private function pending(): array
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'GateCo', 'role' => 'third_party_vendor',
            'email' => 'gateco-'.Str::random(5).'@test.local', 'password' => bcrypt('secret'), 'status' => 'inactive',
        ]);
        $vendor = Vendor::create([
            'tenant_id' => self::TENANT, 'user_id' => $user->id, 'company_name' => 'GateCo',
            'email' => $user->email, 'vendor_type' => 'permanent', 'status' => VendorStatus::INACTIVE,
        ]);
        $ob = TpvOnboarding::create([
            'tenant_id' => self::TENANT, 'vendor_id' => $vendor->id,
            'current_step' => Status::TOTAL_STEPS, 'status' => Status::SUBMITTED,
        ]);

        return [$vendor, $ob];
    }

    public function test_approval_is_blocked_while_the_checklist_is_incomplete(): void
    {
        [$vendor, $ob] = $this->pending();
        $svc = app(TpvOnboardingService::class);

        // Precondition — the general checklist gates activation and nothing is ticked.
        $this->assertTrue($svc->checklist($ob)['gates_activation']);
        $this->assertFalse($svc->checklist($ob)['complete']);

        try {
            $svc->approve($ob, $this->admin(), 'go');
            $this->fail('approve() must refuse while the checklist is incomplete.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('checklist incomplete', strtolower($e->getMessage()));
        }

        // The vendor was NOT activated.
        $this->assertSame(VendorStatus::INACTIVE, $vendor->fresh()->status);
        $this->assertSame(Status::SUBMITTED, $ob->fresh()->status);
    }

    public function test_approval_succeeds_once_every_item_is_ticked(): void
    {
        [$vendor, $ob] = $this->pending();
        $svc = app(TpvOnboardingService::class);

        $items = array_column($svc->checklist($ob)['items'], 'item');
        $svc->setChecklist($ob, array_fill_keys($items, true));
        $this->assertTrue($svc->checklist($ob->fresh())['complete']);

        $svc->approve($ob->fresh(), $this->admin(), 'go');

        $this->assertSame(VendorStatus::ACTIVE, $vendor->fresh()->status);
        $this->assertSame(Status::APPROVED, $ob->fresh()->status);
    }

    public function test_partial_completion_still_blocks_and_names_the_missing_item(): void
    {
        [$vendor, $ob] = $this->pending();
        $svc = app(TpvOnboardingService::class);

        $items = array_column($svc->checklist($ob)['items'], 'item');
        // Tick all but the last item.
        $svc->setChecklist($ob, array_fill_keys(array_slice($items, 0, -1), true));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage(end($items));
        $svc->approve($ob->fresh(), $this->admin(), 'go');
    }
}
