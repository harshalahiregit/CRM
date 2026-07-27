<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseApproval;
use App\Models\Purchase\PurchaseOnboarding;
use App\Models\User;
use App\Repositories\Purchase\PurchaseApprovalRepository;
use App\Support\Purchase\PurchaseApprovalStage as Stage;
use App\Support\Purchase\PurchaseApprovalStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase onboarding approval engine — the five-stage chain (Registration →
 * Document → Commercial → Purchase → Final Activation) with per-stage approve /
 * reject, remarks and audit history. Purchase-owned: reads/writes only
 * purchase_approvals. Never touches the shared onboarding_approvals table or any
 * TPV approval model/service. Reuses only generic infra (BaseRepository, the
 * Auditable trait, logging).
 */
class PurchaseApprovalService
{
    public function __construct(private PurchaseApprovalRepository $repo)
    {
    }

    /** Ensure the five canonical Pending chain rows exist for an onboarding. */
    public function ensureChain(PurchaseOnboarding $onboarding): void
    {
        foreach (Stage::all() as $stage) {
            PurchaseApproval::firstOrCreate(
                ['purchase_onboarding_id' => $onboarding->id, 'stage' => $stage],
                [
                    'tenant_id'          => $onboarding->tenant_id,
                    'purchase_vendor_id' => $onboarding->purchase_vendor_id,
                    'sequence'  => Stage::sequence($stage),
                    'status'    => Status::PENDING,
                ],
            );
        }
    }

    /** The approval chain (ordered), creating any missing stage rows first. */
    public function chainFor(PurchaseOnboarding $onboarding): Collection
    {
        $this->ensureChain($onboarding);

        return $this->repo->chainFor($onboarding);
    }

    /** Full history = the chain rows (decision + timestamps); audit trail via Auditable. */
    public function history(PurchaseOnboarding $onboarding): Collection
    {
        return $this->chainFor($onboarding);
    }

    /** All five stages approved? */
    public function isFullyApproved(PurchaseOnboarding $onboarding): bool
    {
        $chain = $this->chainFor($onboarding);

        return $chain->count() === count(Stage::all())
            && $chain->every(fn (PurchaseApproval $a) => $a->status === Status::APPROVED);
    }

    /** Approve or reject a single stage. */
    public function decide(PurchaseOnboarding $onboarding, string $stage, string $decision, User $actor, ?string $remarks = null): PurchaseApproval
    {
        if (! Stage::isValid($stage)) {
            throw new BusinessException("Unknown approval stage: {$stage}");
        }

        $this->ensureChain($onboarding);
        $row = $this->repo->findStage($onboarding, $stage);

        if ($decision === 'approve') {
            $this->assertPrerequisitesApproved($onboarding, $stage);

            $row->update([
                'status'      => Status::APPROVED,
                'remarks'     => $remarks ?? $row->remarks,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
            ]);
            $row->recordAudit('Approval Stage Approved', $actor, $remarks, ['stage' => $stage]);
        } elseif ($decision === 'reject') {
            if (! $remarks) {
                throw new BusinessException('A reason is required to reject an approval stage.');
            }
            $row->update([
                'status'      => Status::REJECTED,
                'remarks'     => $remarks,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
            ]);
            $row->recordAudit('Approval Stage Rejected', $actor, $remarks, ['stage' => $stage]);
        } else {
            throw new BusinessException('Unknown approval decision.');
        }

        Log::channel('purchase')->info('Purchase approval decision', [
            'onboarding_id' => $onboarding->id, 'stage' => $stage, 'decision' => $decision, 'actor_id' => $actor->id,
        ]);

        return $row->fresh(['approver:id,name', 'rejecter:id,name']);
    }

    /** Approve every outstanding stage — the consolidated one-click activation. */
    public function approveAll(PurchaseOnboarding $onboarding, User $actor, ?string $remarks = null): void
    {
        $this->ensureChain($onboarding);

        foreach ($this->repo->chainFor($onboarding) as $row) {
            if ($row->status === Status::APPROVED) {
                continue;
            }
            $row->update([
                'status'      => Status::APPROVED,
                'remarks'     => $remarks ?? $row->remarks,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
            ]);
        }

        $onboarding->recordAudit('Approval Chain Completed', $actor, $remarks, [
            'stages' => count(Stage::all()),
        ]);
    }

    /** Record a rejection at a stage — the consolidated one-click rejection. */
    public function rejectAt(PurchaseOnboarding $onboarding, string $stage, User $actor, string $remarks): PurchaseApproval
    {
        return $this->decide($onboarding, $stage, 'reject', $actor, $remarks);
    }

    private function assertPrerequisitesApproved(PurchaseOnboarding $onboarding, string $stage): void
    {
        $pending = [];
        foreach (Stage::prerequisites($stage) as $prev) {
            $row = $this->repo->findStage($onboarding, $prev);
            if (! $row || $row->status !== Status::APPROVED) {
                $pending[] = Stage::label($prev);
            }
        }

        if ($pending !== []) {
            throw new BusinessException('Approve the earlier stages first: '.implode(', ', $pending).'.');
        }
    }
}
