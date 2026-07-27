<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseApproval;
use App\Models\Purchase\PurchaseOnboarding;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PurchaseApprovalRepository extends BaseRepository
{
    protected string $modelClass = PurchaseApproval::class;

    /** The full approval chain for an onboarding, in stage order. */
    public function chainFor(PurchaseOnboarding $onboarding): Collection
    {
        return PurchaseApproval::forTenant($onboarding->tenant_id)
            ->where('purchase_onboarding_id', $onboarding->id)
            ->with(['approver:id,name', 'rejecter:id,name'])
            ->orderBy('sequence')
            ->get();
    }

    /** A single stage row for an onboarding (or null). */
    public function findStage(PurchaseOnboarding $onboarding, string $stage): ?PurchaseApproval
    {
        return PurchaseApproval::forTenant($onboarding->tenant_id)
            ->where('purchase_onboarding_id', $onboarding->id)
            ->where('stage', $stage)
            ->first();
    }
}
