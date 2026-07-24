<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\ApprovalDelegation;
use App\Models\Tpv\OnboardingApproval;
use App\Models\Tpv\TpvOnboarding;
use App\Models\User;
use App\Support\Tpv\TpvOnboardingStatus as Status;
use Illuminate\Support\Facades\Log;

/**
 * Advanced (multi-level) approval workflow layered over the existing onboarding
 * approval. The legacy single approve/reject/hold flow is untouched; when the
 * chain reaches its last level it *delegates* to TpvOnboardingService — so the
 * Registration Number generation and vendor activation are reused, not copied.
 */
class OnboardingApprovalService
{
    public function __construct(private TpvOnboardingService $onboarding)
    {
    }

    /* ── Config ─────────────────────────────────────────────────────── */

    public function mode(): string
    {
        return (string) config('tpv.approval.mode', 'single');
    }

    /** Ordered level definitions; a single admin level when mode is 'single'. */
    public function levels(): array
    {
        if ($this->mode() !== 'multi_level') {
            return [['level' => 1, 'role' => 'admin', 'label' => 'Approval']];
        }

        return config('tpv.approval.levels', [['level' => 1, 'role' => 'admin', 'label' => 'Approval']]);
    }

    private function slaHours(): int
    {
        return (int) config('tpv.approval.sla_hours', 48);
    }

    /* ── Chain ──────────────────────────────────────────────────────── */

    /** Idempotently create the approval chain for an onboarding. */
    public function ensureChain(TpvOnboarding $onboarding): void
    {
        if ($onboarding->approvals()->exists()) {
            return;
        }

        foreach ($this->levels() as $def) {
            OnboardingApproval::create([
                'tenant_id'         => $onboarding->tenant_id,
                'tpv_onboarding_id' => $onboarding->id,
                'level'             => $def['level'],
                'approver_role'     => $def['role'],
                // Only level 1 starts its SLA clock; later levels start on unlock.
                'sla_due_at'        => $def['level'] === 1 ? now()->addHours($this->slaHours()) : null,
            ]);
        }
    }

    public function chain(TpvOnboarding $onboarding)
    {
        $this->ensureChain($onboarding);

        return $onboarding->approvals()->with('approver:id,name')->orderBy('level')->get();
    }

    private function currentRow(TpvOnboarding $onboarding): ?OnboardingApproval
    {
        return $onboarding->approvals()->whereNull('decision')->orderBy('level')->first();
    }

    /* ── Decision ───────────────────────────────────────────────────── */

    /**
     * Record a decision at the current level. Approve advances the chain (or
     * finalises at the last level); reject/hold apply to the whole onboarding via
     * the existing service.
     */
    public function decide(TpvOnboarding $onboarding, User $actor, string $decision, ?string $remarks): array
    {
        if (! in_array($onboarding->status, Status::DECIDABLE, true)) {
            throw new BusinessException('This onboarding is not awaiting a decision.');
        }

        $this->ensureChain($onboarding);
        $row = $this->currentRow($onboarding);
        if (! $row) {
            throw new BusinessException('No pending approval level.');
        }

        $auth = $this->resolveAuthority($actor, $row->approver_role);
        if (! $auth['authorized']) {
            throw new BusinessException("You are not authorised to decide the {$row->approver_role} level.", 403);
        }

        if ($decision === 'reject' && ! $remarks) {
            throw new BusinessException('A reason is required to reject.');
        }
        if ($decision === 'hold' && ! $remarks) {
            throw new BusinessException('A reason is required to hold.');
        }

        $row->update([
            'decision'     => $decision,
            'remarks'      => $remarks,
            'approver_id'  => $actor->id,
            'delegated_by' => $auth['delegated_by'],
            'decided_at'   => now(),
        ]);

        if ($decision === 'reject') {
            $this->onboarding->reject($onboarding, $actor, $remarks);

            return $this->result($onboarding, 'rejected');
        }
        if ($decision === 'hold') {
            $this->onboarding->hold($onboarding, $actor, $remarks);

            return $this->result($onboarding, 'held');
        }

        // approve
        $onboarding->recordAudit('Approval Level Passed', $actor, $remarks, [
            'level' => $row->level, 'delegated' => (bool) $auth['delegated_by'],
        ]);

        $next = $onboarding->approvals()->where('level', $row->level + 1)->first();

        if ($next) {
            // Intermediate level cleared — move to review and start the next SLA.
            if ($onboarding->status === Status::SUBMITTED) {
                $onboarding->update(['status' => Status::UNDER_REVIEW]);
            }
            $next->update(['sla_due_at' => now()->addHours($this->slaHours())]);

            return $this->result($onboarding->fresh(), 'advanced');
        }

        // Final level — reuse the existing approval (registration number + activate).
        $this->onboarding->approve($onboarding, $actor, $remarks);

        return $this->result($onboarding->fresh(), 'approved');
    }

    /**
     * Pure authority rule (unit-tested): the actor may decide a level if they hold
     * the required role, are an admin (override), or hold an active delegation from
     * someone with the required role.
     */
    public static function authorize(string $actorRole, string $requiredRole, bool $hasDelegationFromRequired): array
    {
        if ($actorRole === $requiredRole || $actorRole === 'admin') {
            return ['authorized' => true, 'delegated' => false];
        }
        if ($hasDelegationFromRequired) {
            return ['authorized' => true, 'delegated' => true];
        }

        return ['authorized' => false, 'delegated' => false];
    }

    private function resolveAuthority(User $actor, string $requiredRole): array
    {
        $delegatedBy = null;
        $hasDelegation = false;

        if ($actor->role !== $requiredRole && $actor->role !== 'admin') {
            foreach (ApprovalDelegation::forTenant($actor->tenant_id)->activeFor($actor->id)->get() as $d) {
                $delegator = User::find($d->delegator_id);
                if ($delegator && $delegator->role === $requiredRole) {
                    $hasDelegation = true;
                    $delegatedBy = $d->delegator_id;
                    break;
                }
            }
        }

        $res = self::authorize($actor->role, $requiredRole, $hasDelegation);

        return ['authorized' => $res['authorized'], 'delegated_by' => $res['delegated'] ? $delegatedBy : null];
    }

    /* ── Delegation ─────────────────────────────────────────────────── */

    public function createDelegation(array $data, User $actor): ApprovalDelegation
    {
        $delegator = User::where('tenant_id', $actor->tenant_id)->find($data['delegator_id']);
        $delegate  = User::where('tenant_id', $actor->tenant_id)->find($data['delegate_id']);

        if (! $delegator || ! $delegate) {
            throw new BusinessException('Delegator or delegate not found in this tenant.', 404);
        }
        if ($delegator->id === $delegate->id) {
            throw new BusinessException('An approver cannot delegate to themselves.');
        }

        $delegation = ApprovalDelegation::create([
            'tenant_id'    => $actor->tenant_id,
            'delegator_id' => $delegator->id,
            'delegate_id'  => $delegate->id,
            'reason'       => $data['reason'] ?? null,
            'starts_at'    => $data['starts_at'],
            'ends_at'      => $data['ends_at'],
        ]);

        $delegation->recordAudit('Approval Delegated', $actor, $data['reason'] ?? null, [
            'delegator_id' => $delegator->id, 'delegate_id' => $delegate->id,
        ]);

        return $delegation->load('delegator:id,name', 'delegate:id,name');
    }

    public function listDelegations(int $tenantId)
    {
        return ApprovalDelegation::forTenant($tenantId)
            ->with('delegator:id,name', 'delegate:id,name')
            ->latest()->get();
    }

    /* ── Escalation ─────────────────────────────────────────────────── */

    /** Flag current levels past their SLA as escalated (scheduled hourly). */
    public function escalatePending(): int
    {
        $escalated = 0;

        OnboardingApproval::whereNull('decision')
            ->whereNull('escalated_at')
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->with('onboarding')
            ->chunkById(200, function ($rows) use (&$escalated) {
                foreach ($rows as $row) {
                    $row->update(['escalated_at' => now()]);
                    $row->onboarding?->recordAudit('Approval Escalated', null, null, [
                        'level' => $row->level, 'approver_role' => $row->approver_role,
                    ]);
                    $escalated++;
                }
            });

        return $escalated;
    }

    /* ── Read models ────────────────────────────────────────────────── */

    /** Onboardings awaiting a decision, each with its current level + SLA state. */
    public function listAwaiting(int $tenantId): array
    {
        return TpvOnboarding::forTenant($tenantId)
            ->whereIn('status', Status::DECIDABLE)
            ->with('vendor:id,company_name,vendor_code,vendor_type')
            ->latest()
            ->get()
            ->map(function (TpvOnboarding $o) {
                $this->ensureChain($o);
                $current = $this->currentRow($o);

                return [
                    'id'            => $o->id,
                    'status'        => $o->status,
                    'vendor'        => $o->vendor,
                    'submitted_at'  => optional($o->submitted_at)->toIso8601String(),
                    'current_level' => $current?->level,
                    'current_role'  => $current?->approver_role,
                    'sla_due_at'    => optional($current?->sla_due_at)->toIso8601String(),
                    'escalated'     => (bool) $current?->escalated_at,
                    'total_levels'  => $o->approvals()->count(),
                ];
            })
            ->all();
    }

    /** Full approval history/timeline for an onboarding. */
    public function history(TpvOnboarding $onboarding): array
    {
        return [
            'mode'     => $this->mode(),
            'chain'    => $this->chain($onboarding),
            'current'  => $this->currentRow($onboarding)?->level,
        ];
    }

    private function result(TpvOnboarding $onboarding, string $outcome): array
    {
        Log::channel('tpv')->info('TPV approval decision', [
            'onboarding_id' => $onboarding->id, 'outcome' => $outcome,
        ]);

        return [
            'outcome'    => $outcome,
            'onboarding' => $onboarding->fresh(),
            'chain'      => $this->chain($onboarding),
        ];
    }
}
