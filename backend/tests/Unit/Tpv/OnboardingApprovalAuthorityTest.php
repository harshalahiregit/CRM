<?php

namespace Tests\Unit\Tpv;

use App\Services\Tpv\OnboardingApprovalService;
use PHPUnit\Framework\TestCase;

/**
 * The pure authority rule that decides whether an actor may act on a level.
 */
class OnboardingApprovalAuthorityTest extends TestCase
{
    public function test_direct_role_is_authorized_not_delegated(): void
    {
        $r = OnboardingApprovalService::authorize('staff', 'staff', false);
        $this->assertTrue($r['authorized']);
        $this->assertFalse($r['delegated']);
    }

    public function test_admin_can_act_on_any_level_as_override(): void
    {
        $r = OnboardingApprovalService::authorize('admin', 'staff', false);
        $this->assertTrue($r['authorized']);
        $this->assertFalse($r['delegated']);
    }

    public function test_active_delegation_authorizes_and_flags_delegated(): void
    {
        $r = OnboardingApprovalService::authorize('staff', 'admin', true);
        $this->assertTrue($r['authorized']);
        $this->assertTrue($r['delegated']);
    }

    public function test_wrong_role_without_delegation_is_denied(): void
    {
        $r = OnboardingApprovalService::authorize('staff', 'admin', false);
        $this->assertFalse($r['authorized']);

        $this->assertFalse(OnboardingApprovalService::authorize('vendor', 'staff', false)['authorized']);
    }
}
