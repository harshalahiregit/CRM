<?php

namespace App\Services\Hr\Approval;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrApprovalAction;
use App\Models\Hr\HrApprovalRequest;
use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\Approval\ApprovalState;
use App\Support\Hr\Approval\ApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The approval ladder: who may decide, in what order, and what has been decided.
 *
 * What this deliberately does NOT do is change any business record. It decides
 * WHETHER and WHEN; the calling service still decides WHAT HAPPENS — deducting
 * a leave balance, settling an advance, closing a payroll run. That split is
 * what lets eleven processes with eleven different status vocabularies share
 * one engine without any of them being rewritten.
 *
 * Three gates guard every decision, and they are independent:
 *
 *   1. capability — the caller's own permission check, which stays where it is
 *   2. data scope — ScopeResolver, unchanged, against the SUBJECT's employee
 *   3. the ladder — is this request actually waiting on this person
 *
 * Being named on a step grants nothing. An approver configured for an employee
 * outside their data scope is refused by gate 2, and the request stays open
 * rather than quietly going through.
 */
class ApprovalEngine
{
    public function __construct(
        private WorkflowRegistry $registry,
        private ApproverResolver $resolver,
        private ConditionEvaluator $conditions,
    ) {
    }

    /* ── opening a request ────────────────────────────────────────────── */

    /**
     * The request for this record, created on first use.
     *
     * Lazy on purpose. Creating these when a record is made would mean
     * backfilling every leave application that already exists; creating it on
     * the first decision means nothing is rewritten and a record from before
     * this engine existed behaves exactly as it did until somebody acts on it.
     */
    public function requestFor(
        Model $subject,
        string $process,
        int $tenantId,
        ?int $employeeId,
        ?float $amount = null
    ): HrApprovalRequest {
        $existing = HrApprovalRequest::where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        if ($existing) {
            return $existing;
        }

        $context  = $this->conditions->contextFor($tenantId, $employeeId, $subject, $amount);
        $resolved = $this->registry->resolveSteps($tenantId, $process, $context);

        return HrApprovalRequest::create([
            'tenant_id'        => $tenantId,
            'subject_type'     => $subject->getMorphClass(),
            'subject_id'       => $subject->getKey(),
            'process'          => $process,
            'employee_id'      => $employeeId,
            'workflow_id'      => $resolved['workflow']?->id,
            'workflow_version' => $resolved['workflow']?->version,
            'steps_snapshot'   => $resolved['steps'],
            'current_step'     => 1,
            'state'            => ApprovalState::PENDING,
            'amount'           => $amount,
            'opened_at'        => now(),
        ]);
    }

    /* ── may this person act ──────────────────────────────────────────── */

    /**
     * Is this request waiting on this user, right now?
     *
     * Gate 3 only. The caller is still responsible for gates 1 and 2 — see
     * assertMayDecide(), which is what every controller should use.
     */
    public function mayAct(HrApprovalRequest $request, User $actor): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        $step = $request->currentStepDefinition();
        if (! $step) {
            return false;
        }

        // Tenancy first: a request belongs to one workspace and nobody outside
        // it is ever the approver, whatever a step says.
        if ((int) $request->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }

        if (($step['approver_type'] ?? null) === ApproverType::LEGACY_HR_QUEUE) {
            // Asked of the predicate itself rather than reimplemented, so the
            // compatibility rung cannot drift away from the gate it stands in for.
            return $actor->canManageHrQueue();
        }

        $resolved = $this->resolver->resolve($step, (int) $request->tenant_id, $request->employee_id);

        return in_array((int) $actor->id, $resolved['user_ids'], true);
    }

    /**
     * The full three-gate check. Controllers call THIS.
     *
     * Gate 1 is passed in as a closure because each process words its own
     * permission differently, and the engine has no business inventing a
     * permission vocabulary beside the one that already exists.
     */
    public function assertMayDecide(HrApprovalRequest $request, User $actor, ?callable $capability = null): void
    {
        // 1. capability
        if ($capability && ! $capability($actor)) {
            abort(403, 'You are not authorised to decide this request.');
        }

        // 2. data scope — unchanged architecture, against the subject's employee.
        //    Deliberately independent of the ladder: being an approver is not a
        //    grant of access to somebody else's record.
        app(ScopeResolver::class)->assertCanActOnEmployee($actor, $request->employee_id);

        // 3. the ladder
        if (! $this->mayAct($request, $actor)) {
            abort(403, $this->refusalReason($request, $actor));
        }
    }

    /** Why this person may not act, in words worth showing someone. */
    public function refusalReason(HrApprovalRequest $request, User $actor): string
    {
        if (! $request->isOpen()) {
            return 'This request has already been decided.';
        }

        $step = $request->currentStepDefinition();
        if (! $step) {
            return 'This request has no step waiting for a decision.';
        }

        return 'This request is with '.($step['name'] ?? 'another approver').'.';
    }

    /* ── deciding ─────────────────────────────────────────────────────── */

    /**
     * Record a decision and advance the ladder.
     *
     * Returns what the caller needs to know: whether this was the last rung, so
     * the domain service can make its own transition, or whether the request is
     * still open and the business record should not move yet.
     *
     * @return array{final: bool, state: string, request: HrApprovalRequest}
     */
    public function decide(
        HrApprovalRequest $request,
        User $actor,
        string $action,
        ?string $comment = null
    ): array {
        if (! $request->isOpen()) {
            throw new BusinessException('This request has already been decided.', 422);
        }

        $step = $request->currentStepDefinition();
        if (! $step) {
            throw new BusinessException('This request has no step waiting for a decision.', 422);
        }

        $resolved = $this->resolver->resolve($step, (int) $request->tenant_id, $request->employee_id);

        return DB::transaction(function () use ($request, $actor, $action, $comment, $step, $resolved) {
            // Re-read inside the transaction so two approvers pressing at once
            // cannot both pass the open check above.
            $fresh = HrApprovalRequest::whereKey($request->id)->lockForUpdate()->first();

            if (! $fresh || ! $fresh->isOpen()) {
                throw new BusinessException('This request has already been decided.', 422);
            }

            HrApprovalAction::create([
                'tenant_id'           => $fresh->tenant_id,
                'approval_request_id' => $fresh->id,
                'step_order'          => $fresh->current_step,
                'step_name'           => $step['name'] ?? null,
                'actor_id'            => $actor->id,
                'actor_name'          => $actor->name,
                'actor_role'          => $actor->internal_role ?: $actor->role,
                'action'              => $action,
                'comment'             => $comment,
                'resolved_approver'   => [
                    'type'     => $step['approver_type'] ?? null,
                    'describe' => $resolved['describe'] ?? null,
                    'user_ids' => $resolved['user_ids'] ?? [],
                ],
            ]);

            if ($action === HrApprovalAction::REJECTED) {
                $fresh->update([
                    'state'     => ApprovalState::REJECTED,
                    'closed_at' => now(),
                ]);

                return ['final' => true, 'state' => ApprovalState::REJECTED, 'request' => $fresh->fresh()];
            }

            if ($fresh->isFinalStep()) {
                $fresh->update([
                    'state'     => ApprovalState::APPROVED,
                    'closed_at' => now(),
                ]);

                return ['final' => true, 'state' => ApprovalState::APPROVED, 'request' => $fresh->fresh()];
            }

            // More rungs to go. The business record does NOT move.
            $next = $fresh->remainingSteps()[0]['step_order'];
            $fresh->update(['current_step' => $next]);

            return ['final' => false, 'state' => ApprovalState::PENDING, 'request' => $fresh->fresh()];
        });
    }

    /* ── housekeeping ─────────────────────────────────────────────────── */

    /**
     * Mark a request as decided elsewhere.
     *
     * The attendance app approves leave through LeaveApprovalService directly,
     * with its own reporting-line check, and this phase does not change that.
     * When the CRM next looks at a record the app has already decided, the
     * request is closed as superseded so the engine's view and the business
     * record do not contradict each other.
     */
    public function supersede(HrApprovalRequest $request, string $why = 'Decided outside the approval workflow'): void
    {
        if (! $request->isOpen()) {
            return;
        }

        $request->update([
            'state'          => ApprovalState::SUPERSEDED,
            'blocked_reason' => $why,
            'closed_at'      => now(),
        ]);
    }

    /**
     * Flag a request nobody can act on, with a reason an admin can fix.
     *
     * Never auto-approves. A missing approver is a configuration problem, and
     * approving because one could not be found would hand out exactly the
     * decision the workflow existed to require.
     */
    public function block(HrApprovalRequest $request, string $reason): HrApprovalRequest
    {
        $request->update([
            'state'          => ApprovalState::BLOCKED,
            'blocked_reason' => $reason,
        ]);

        return $request->fresh();
    }

    /**
     * Who is this waiting on, and can anybody act at all?
     *
     * Used by the controller to block a request the moment it is opened against
     * an unresolvable rung, rather than letting somebody discover it by trying.
     */
    public function inspect(HrApprovalRequest $request): array
    {
        $step = $request->currentStepDefinition();

        if (! $step) {
            return ['resolvable' => false, 'describe' => 'No step', 'user_ids' => []];
        }

        if (($step['approver_type'] ?? null) === ApproverType::LEGACY_HR_QUEUE) {
            // Always resolvable by definition — it is the current gate.
            return ['resolvable' => true, 'describe' => 'HR queue', 'user_ids' => []];
        }

        $resolved = $this->resolver->resolve($step, (int) $request->tenant_id, $request->employee_id);

        return [
            'resolvable' => $resolved['user_ids'] !== [],
            'describe'   => $resolved['describe'],
            'user_ids'   => $resolved['user_ids'],
        ];
    }
}
