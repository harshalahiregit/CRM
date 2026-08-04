<?php

namespace App\Listeners\Hr;

use App\Events\Inventory\AssetAssignedToEmployee;
use App\Models\Hr\HrEmployeeOnboarding;
use App\Services\Hr\EmployeeOnboardingService;

/**
 * Assigning an asset in Inventory advances onboarding's IT & Asset Allocation stage.
 *
 * This is the whole of the onboarding→asset link now. HR stores no asset row; it
 * only moves its own workflow forward when Inventory says an asset changed hands.
 */
class AdvanceOnboardingAssetStage
{
    public function __construct(private EmployeeOnboardingService $onboarding)
    {
    }

    public function handle(AssetAssignedToEmployee $event): void
    {
        // Handing an asset back is not progress — nothing to advance.
        if (! $event->isAssignment()) {
            return;
        }

        $onboarding = HrEmployeeOnboarding::query()
            ->where('tenant_id', $event->tenantId)
            ->where('employee_id', $event->employeeId)
            ->latest('id')
            ->first();

        if (! $onboarding) {
            return;
        }

        $this->onboarding->recordAssetAllocated($onboarding, $event->actorId);
    }
}
