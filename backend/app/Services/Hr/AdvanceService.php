<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Support\Hr\AdvanceStage;
use Illuminate\Support\Facades\DB;

/**
 * Advances: the ladder, the money going out, and the bills coming back.
 *
 * Three tiers approve in turn — manager, then accounts, then director — and only
 * the tier whose turn it is may act. SangoeTrack has the same three stages but
 * approves "without choosing a stage", so any approver could complete a request
 * from any rung; here the rung is the gate, and AdvanceTierService owns who
 * stands on it.
 *
 * A hold pauses the ladder without unwinding it: held_from remembers the rung,
 * so being questioned by accounts does not cost the manager's approval.
 *
 * Nothing is ever deleted. SangoeTrack removes the previous settlement when one
 * is re-submitted, which destroys the only record of what was first claimed at
 * exactly the moment somebody wants to compare the two.
 */
class AdvanceService
{
    /** Cash aside, an unreferenced payment cannot be reconciled later. */
    public const MODES = ['cash', 'upi', 'bank_transfer', 'cheque'];

    public function __construct(
        private RequestThreadService $thread,
        private AdvanceTierService $tiers,
    ) {
    }

    /* ── the employee's side ─────────────────────────────────────────── */

    public function request(HrEmployee $employee, array $data, User $actor): HrAdvance
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $advance = HrAdvance::create([
                'tenant_id'                => $employee->tenant_id,
                'employee_id'              => $employee->id,
                'reference'                => HrAdvance::nextReference((int) $employee->tenant_id),
                'advance_type'             => $data['advance_type'] ?? null,
                'category'                 => $data['category'] ?? null,
                'project_site'             => $data['project_site'] ?? null,
                'purpose'                  => $data['purpose'],
                'amount_requested'         => $data['amount_requested'],
                'required_date'            => $data['required_date'] ?? null,
                'expected_settlement_date' => $data['expected_settlement_date'] ?? null,
                'status'                   => AdvanceStage::PENDING,
            ]);

            $this->thread->event(
                $advance,
                'submitted',
                'Advance requested for ' . $this->money($advance->amount_requested) . '.',
                $actor,
                ['amount_requested' => (float) $advance->amount_requested]
            );

            return $advance;
        });
    }

    /** Answering a hold — this is what clears it and puts the request back on its rung. */
    public function reply(HrAdvance $advance, User $actor, string $body): HrAdvance
    {
        $this->assertOpen($advance);

        return DB::transaction(function () use ($advance, $actor, $body) {
            $this->thread->message($advance, $actor, $body);

            if ($advance->isOnHold()) {
                // Back to the rung it was held from, never to the bottom.
                $returnTo = $advance->held_from ?: AdvanceStage::PENDING;

                $advance->update([
                    'status'          => $returnTo,
                    'held_from'       => null,
                    // An offer does not survive the answer to it.
                    'proposed_amount' => null,
                ]);

                $this->thread->event($advance, 'hold_cleared',
                    'The employee replied, and the request returned to ' . strtolower(AdvanceStage::label($returnTo)) . '.', $actor);
            }

            return $advance->fresh();
        });
    }

    public function acceptProposal(HrAdvance $advance, User $actor): HrAdvance
    {
        if (! $advance->can_accept_proposal) {
            throw new BusinessException('There is no proposed amount to accept on this advance.', 422);
        }

        return DB::transaction(function () use ($advance, $actor) {
            $agreed   = (float) $advance->proposed_amount;
            $returnTo = $advance->held_from ?: AdvanceStage::PENDING;

            // Accepting settles the AMOUNT, not the approval. The request goes
            // back on its rung and still has to climb the rest of the ladder —
            // agreeing a figure with one approver is not the others' consent.
            $advance->update([
                'status'          => $returnTo,
                'amount_approved' => $agreed,
                'held_from'       => null,
                'proposed_amount' => null,
            ]);

            $this->thread->event($advance, 'proposal_accepted',
                'The employee accepted ' . $this->money($agreed) . '. The request continues from '
                    . strtolower(AdvanceStage::label($returnTo)) . '.',
                $actor, ['agreed_amount' => $agreed]);

            return $advance->fresh();
        });
    }

    public function cancel(HrAdvance $advance, User $actor): HrAdvance
    {
        if (! in_array($advance->status, [AdvanceStage::PENDING, AdvanceStage::ON_HOLD], true)) {
            throw new BusinessException('An advance can only be withdrawn before anybody has approved it.', 422);
        }

        return DB::transaction(function () use ($advance, $actor) {
            $advance->update(['status' => AdvanceStage::CANCELLED, 'held_from' => null, 'proposed_amount' => null]);
            $this->thread->event($advance, 'cancelled', 'The employee withdrew this request.', $actor);

            return $advance->fresh();
        });
    }

    /* ── the ladder ──────────────────────────────────────────────────── */

    /**
     * One rung's approval.
     *
     * The tier is derived from the request's own status, never taken from the
     * caller — a client that could name its tier could name the last one.
     */
    public function approve(HrAdvance $advance, User $actor, ?float $amount = null, ?string $reason = null): HrAdvance
    {
        if ($refusal = $this->tiers->refusalReason($advance, $actor)) {
            throw new BusinessException($refusal, 403);
        }

        $tier = AdvanceStage::nextTier((string) $advance->status);

        // Compared against what is in force now, not the original request, so
        // restoring a figure is a real change and gets written.
        $current = $advance->effectiveAmount();
        $changed = $amount !== null && abs($amount - $current) > 0.005;

        if ($changed && trim((string) $reason) === '') {
            throw new BusinessException('Changing the amount needs a reason.', 422);
        }

        return DB::transaction(function () use ($advance, $actor, $amount, $reason, $changed, $current, $tier) {
            $final    = $amount ?? $current;
            $reaches  = AdvanceStage::REACHES[$tier];
            $complete = $reaches === AdvanceStage::APPROVED;

            $advance->update([
                'status'          => $reaches,
                'amount_approved' => $final,
                'held_from'       => null,
                'proposed_amount' => null,
                'decided_by'      => $actor->id,
                'decided_at'      => $complete ? now() : null,
            ]);

            if ($changed) {
                $this->thread->event($advance, 'amount_changed',
                    'Amount changed from ' . $this->money($current) . ' to ' . $this->money($final)
                        . '. Reason: ' . trim((string) $reason),
                    $actor, ['from' => $current, 'to' => $final, 'reason' => trim((string) $reason), 'tier' => $tier]);
            }

            // Recorded per rung. AdvanceTierService reads these back to enforce
            // that one person does not approve twice, so the tier goes in the meta.
            $this->thread->event($advance, 'tier_approved',
                ucfirst($tier) . ' approved ' . $this->money($final) . '.'
                    . ($complete ? ' The request is ready to disburse.' : ''),
                $actor, ['tier' => $tier, 'amount' => $final]);

            return $advance->fresh();
        });
    }

    public function decline(HrAdvance $advance, User $actor, string $reason): HrAdvance
    {
        $this->assertOpen($advance);

        if (trim($reason) === '') {
            throw new BusinessException('Declining an advance needs a reason.', 422);
        }

        // Declining is available to whoever holds the current rung, on the same
        // terms as approving — otherwise anybody could kill a request they are
        // not responsible for.
        if ($refusal = $this->tiers->refusalReason($advance, $actor)) {
            throw new BusinessException($refusal, 403);
        }

        return DB::transaction(function () use ($advance, $actor, $reason) {
            $advance->update([
                'status'          => AdvanceStage::DECLINED,
                'held_from'       => null,
                'proposed_amount' => null,
                'decided_by'      => $actor->id,
                'decided_at'      => now(),
            ]);

            $this->thread->event($advance, 'declined', 'Declined. Reason: ' . trim($reason), $actor, ['reason' => trim($reason)]);

            return $advance->fresh();
        });
    }

    /** Hold, with a reason, and optionally a different amount to propose. */
    public function hold(HrAdvance $advance, User $actor, string $reason, ?float $proposedAmount = null): HrAdvance
    {
        if (trim($reason) === '') {
            throw new BusinessException('A hold needs a reason — the employee has to know what to do about it.', 422);
        }

        if ($proposedAmount !== null && $proposedAmount <= 0) {
            throw new BusinessException('A proposed amount must be more than zero.', 422);
        }

        if ($refusal = $this->tiers->refusalReason($advance, $actor)) {
            throw new BusinessException($refusal, 403);
        }

        return DB::transaction(function () use ($advance, $actor, $reason, $proposedAmount) {
            // The rung, remembered. Holding an already-held request keeps the
            // ORIGINAL rung rather than recording 'on_hold' as somewhere to return to.
            $heldFrom = $advance->isOnHold() ? $advance->held_from : $advance->status;

            $advance->update([
                'status'          => AdvanceStage::ON_HOLD,
                'held_from'       => $heldFrom,
                'proposed_amount' => $proposedAmount,
            ]);

            $body = 'Held. Reason: ' . trim($reason);
            if ($proposedAmount !== null) {
                $body .= ' Proposed amount: ' . $this->money($proposedAmount) . '.';
            }

            $this->thread->event($advance, 'held', $body, $actor, array_filter([
                'reason'          => trim($reason),
                'proposed_amount' => $proposedAmount,
                'held_from'       => $heldFrom,
            ], fn ($v) => $v !== null));

            return $advance->fresh();
        });
    }

    /* ── money out ───────────────────────────────────────────────────── */

    /**
     * Record that the money actually left.
     *
     * Only from APPROVED — the top of the ladder — and a reference is required
     * for everything but cash, because an unreferenced transfer cannot be matched
     * to a bank statement afterwards.
     */
    public function disburse(HrAdvance $advance, User $actor, string $mode, ?string $reference, ?float $amount = null): HrAdvance
    {
        if ($advance->status !== AdvanceStage::APPROVED) {
            throw new BusinessException(
                $advance->status === AdvanceStage::DISBURSED
                    ? 'This advance has already been disbursed.'
                    : 'An advance can only be disbursed once every tier has approved it.',
                422
            );
        }

        if (! in_array($mode, self::MODES, true)) {
            throw new BusinessException('That is not a payment method we record.', 422);
        }

        if ($mode !== 'cash' && trim((string) $reference) === '') {
            throw new BusinessException('A ' . str_replace('_', ' ', $mode) . ' needs a reference, or it cannot be reconciled later.', 422);
        }

        // Paying more than was approved is not a rounding matter.
        $paid = $amount ?? $advance->effectiveAmount();
        if ($paid > $advance->effectiveAmount() + 0.005) {
            throw new BusinessException('You cannot disburse more than the approved amount.', 422);
        }

        return DB::transaction(function () use ($advance, $actor, $mode, $reference, $paid) {
            $advance->update([
                'status'                 => AdvanceStage::DISBURSED,
                'disbursed_amount'       => $paid,
                'disbursement_mode'      => $mode,
                'disbursement_reference' => trim((string) $reference) ?: null,
                'disbursed_by'           => $actor->id,
                'disbursed_at'           => now(),
            ]);

            $this->thread->event($advance, 'disbursed',
                $this->money($paid) . ' disbursed by ' . str_replace('_', ' ', $mode)
                    . ($reference ? ' (' . trim($reference) . ')' : '') . '.',
                $actor, ['amount' => $paid, 'mode' => $mode, 'reference' => trim((string) $reference) ?: null]);

            return $advance->fresh();
        });
    }

    /* ── bills back ──────────────────────────────────────────────────── */

    /**
     * The employee accounts for what was spent.
     *
     * Allowed while disbursed, which includes after a rejected settlement — the
     * previous attempt stays on the record rather than being replaced.
     */
    public function submitSettlement(HrAdvance $advance, User $actor, array $data): HrAdvanceSettlement
    {
        if ($advance->status !== AdvanceStage::DISBURSED) {
            throw new BusinessException(
                $advance->status === AdvanceStage::SETTLEMENT_SUBMITTED
                    ? 'A settlement for this advance is already under review.'
                    : 'This advance is not waiting to be settled.',
                422
            );
        }

        $spent     = (float) $data['actual_expense'];
        $disbursed = (float) ($advance->disbursed_amount ?? 0);

        return DB::transaction(function () use ($advance, $actor, $data, $spent, $disbursed) {
            // Both stored, both non-negative: exactly one of them can be set.
            $settlement = HrAdvanceSettlement::create([
                'tenant_id'      => $advance->tenant_id,
                'advance_id'     => $advance->id,
                'actual_expense' => $spent,
                'balance_return' => max(0, round($disbursed - $spent, 2)),
                'extra_due'      => max(0, round($spent - $disbursed, 2)),
                'notes'          => $data['notes'] ?? null,
                'status'         => HrAdvanceSettlement::PENDING,
                'submitted_by'   => $actor->id,
            ]);

            $advance->update(['status' => AdvanceStage::SETTLEMENT_SUBMITTED]);

            $this->thread->event($advance, 'settlement_submitted',
                'Settlement submitted: ' . $this->money($spent) . ' spent against ' . $this->money($disbursed) . ' advanced. '
                    . $settlement->case_label . '.',
                $actor, [
                    'actual_expense' => $spent,
                    'balance_return' => (float) $settlement->balance_return,
                    'extra_due'      => (float) $settlement->extra_due,
                    'settlement_id'  => $settlement->id,
                ]);

            return $settlement;
        });
    }

    /** Accept the settlement: the advance is finished. */
    public function acceptSettlement(HrAdvanceSettlement $settlement, User $actor, ?string $remarks = null): HrAdvanceSettlement
    {
        $advance = $this->assertSettlementReviewable($settlement);

        return DB::transaction(function () use ($settlement, $advance, $actor, $remarks) {
            $settlement->update([
                'status'         => HrAdvanceSettlement::ACCEPTED,
                'review_remarks' => $remarks,
                'reviewed_by'    => $actor->id,
                'reviewed_at'    => now(),
            ]);

            $advance->update(['status' => AdvanceStage::SETTLED, 'decided_at' => now()]);

            $this->thread->event($advance, 'settled',
                'Settlement accepted. ' . $settlement->case_label . '.'
                    . ($remarks ? ' ' . trim($remarks) : ''),
                $actor, ['settlement_id' => $settlement->id]);

            return $settlement->fresh();
        });
    }

    /**
     * Send the settlement back. The advance returns to DISBURSED so another can
     * be submitted — and the rejected one is KEPT.
     */
    public function rejectSettlement(HrAdvanceSettlement $settlement, User $actor, string $remarks): HrAdvanceSettlement
    {
        $advance = $this->assertSettlementReviewable($settlement);

        if (trim($remarks) === '') {
            throw new BusinessException('Sending a settlement back needs a reason.', 422);
        }

        return DB::transaction(function () use ($settlement, $advance, $actor, $remarks) {
            $settlement->update([
                'status'         => HrAdvanceSettlement::REJECTED,
                'review_remarks' => trim($remarks),
                'reviewed_by'    => $actor->id,
                'reviewed_at'    => now(),
            ]);

            $advance->update(['status' => AdvanceStage::DISBURSED]);

            $this->thread->event($advance, 'settlement_rejected',
                'Settlement sent back. Reason: ' . trim($remarks),
                $actor, ['settlement_id' => $settlement->id, 'reason' => trim($remarks)]);

            return $settlement->fresh();
        });
    }

    /* ── the ledger the old screen could not show ────────────────────── */

    /**
     * What an employee is currently carrying.
     *
     * The CRM's SangoeTrack screen says outright that it has "no per-employee
     * ledger, so an employee's existing outstanding balance is not shown before
     * a new advance is granted". Granting a second advance to somebody who has
     * not settled the first is a decision, and it should be an informed one.
     */
    public function outstandingFor(HrEmployee $employee): array
    {
        $live = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [AdvanceStage::DISBURSED, AdvanceStage::SETTLEMENT_SUBMITTED])
            ->get();

        return [
            'open_count'         => $live->count(),
            'outstanding_amount' => round((float) $live->sum('disbursed_amount'), 2),
            'oldest_disbursed_at'=> optional($live->min('disbursed_at'))?->toDateString(),
            'in_ladder_count'    => HrAdvance::where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereIn('status', [
                    AdvanceStage::PENDING, AdvanceStage::MANAGER_APPROVED,
                    AdvanceStage::ACCOUNTS_APPROVED, AdvanceStage::APPROVED, AdvanceStage::ON_HOLD,
                ])->count(),
        ];
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function assertSettlementReviewable(HrAdvanceSettlement $settlement): HrAdvance
    {
        if ($settlement->status !== HrAdvanceSettlement::PENDING) {
            throw new BusinessException('This settlement has already been reviewed.', 422);
        }

        $advance = $settlement->advance()->first();

        if (! $advance) {
            throw new BusinessException('That settlement is not attached to an advance.', 422);
        }

        return $advance;
    }

    private function assertOpen(HrAdvance $advance): void
    {
        if ($advance->isDecided()) {
            throw new BusinessException('This advance has already been decided.', 422);
        }
    }

    private function money(float|string $amount): string
    {
        return '₹' . number_format((float) $amount, 2);
    }
}
