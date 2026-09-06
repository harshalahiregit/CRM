<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\User;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims, and the conversation around them.
 *
 * The admin's actions are the same three at every point a claim is open — accept,
 * decline, hold — regardless of how it got there. A claim that has been held twice
 * and answered twice offers exactly what a fresh one does; the difference is that
 * the thread above it shows what was asked and answered.
 *
 * A cleared hold returns the claim to the shared queue rather than to the admin
 * who held it. Routing it back would preserve context at the cost of stalling
 * whenever that person is away, and the thread carries the context anyway.
 */
class ReimbursementService
{
    public function __construct(private RequestThreadService $thread)
    {
    }

    /* ── the employee's side ─────────────────────────────────────────── */

    public function submit(HrEmployee $employee, array $data, User $actor): HrReimbursement
    {
        return DB::transaction(function () use ($employee, $data, $actor) {
            $claim = HrReimbursement::create([
                'tenant_id'      => $employee->tenant_id,
                'employee_id'    => $employee->id,
                'title'          => $data['title'],
                'description'    => $data['description'] ?? null,
                'category'       => $data['category'] ?? null,
                'expense_date'   => $data['expense_date'],
                'amount_claimed' => $data['amount_claimed'],
                'status'         => ReimbursementStatus::PENDING,
            ]);

            $this->thread->event(
                $claim,
                'submitted',
                'Claim submitted for ' . $this->money($claim->amount_claimed) . '.',
                $actor,
                ['amount_claimed' => (float) $claim->amount_claimed]
            );

            return $claim;
        });
    }

    /**
     * The employee answers a hold. This is what clears it.
     *
     * Clearing on the reply rather than by an admin action means a claim cannot
     * sit held because nobody noticed the answer arrived.
     */
    public function reply(HrReimbursement $claim, User $actor, string $body): HrReimbursement
    {
        $this->assertOpen($claim);

        return DB::transaction(function () use ($claim, $actor, $body) {
            $this->thread->message($claim, $actor, $body);

            if ($claim->isOnHold()) {
                $returnTo = $claim->held_from ?: ReimbursementStatus::PENDING;

                $claim->update([
                    'status'          => $returnTo,
                    'held_from'       => null,
                    // The offer does not survive the reply. If the admin still
                    // wants that figure they can propose it again, having read
                    // what was just said — an offer left standing against new
                    // information is an offer nobody has reconsidered.
                    'proposed_amount' => null,
                ]);

                $this->thread->event($claim, 'hold_cleared', 'The employee replied, and the claim returned to the queue.', $actor);
            }

            return $claim->fresh();
        });
    }

    /**
     * The employee accepts the amount proposed in a hold.
     *
     * A real action with its own record, not a message saying "ok" — an agreement
     * nobody can act on later is not an agreement.
     */
    public function acceptProposal(HrReimbursement $claim, User $actor): HrReimbursement
    {
        if (! $claim->can_accept_proposal) {
            throw new BusinessException('There is no proposed amount to accept on this claim.', 422);
        }

        return DB::transaction(function () use ($claim, $actor) {
            $agreed = (float) $claim->proposed_amount;

            $claim->update([
                'status'          => ReimbursementStatus::APPROVED,
                'amount_approved' => $agreed,
                'held_from'       => null,
                'proposed_amount' => null,
                'decided_at'      => now(),
            ]);

            $this->thread->event(
                $claim,
                'proposal_accepted',
                'The employee accepted ' . $this->money($agreed) . ', and the claim was approved at that amount.',
                $actor,
                ['agreed_amount' => $agreed]
            );

            return $claim->fresh();
        });
    }

    /* ── the admin's side: the same three, always ────────────────────── */

    /**
     * Approve, optionally for a different amount.
     *
     * A different amount REQUIRES a reason. The reason is the part that matters
     * three months later, and it goes on the thread where the employee can see it
     * rather than into a field nobody reads.
     */
    public function approve(HrReimbursement $claim, User $actor, ?float $amount = null, ?string $reason = null): HrReimbursement
    {
        $this->assertOpen($claim);

        // Compared against what is currently in force, not the original claim.
        // SangoeTrack compares against the requested figure, so restoring an
        // amount to the original takes the "unchanged" branch and silently writes
        // nothing while reporting success.
        $current = $claim->amount_approved !== null
            ? (float) $claim->amount_approved
            : (float) $claim->amount_claimed;

        // Money arriving as a float: an exact comparison would report a change
        // between 2500 and 2500.0.
        $changed = $amount !== null && abs($amount - $current) > 0.005;

        if ($changed && trim((string) $reason) === '') {
            throw new BusinessException('Changing the amount needs a reason.', 422);
        }

        return DB::transaction(function () use ($claim, $actor, $amount, $reason, $changed, $current) {
            $final = $amount ?? $current;

            $claim->update([
                'status'          => ReimbursementStatus::APPROVED,
                'amount_approved' => $final,
                'held_from'       => null,
                'proposed_amount' => null,
                'decided_by'      => $actor->id,
                'decided_at'      => now(),
            ]);

            if ($changed) {
                $this->thread->event(
                    $claim,
                    'amount_changed',
                    'Approved amount changed from ' . $this->money($current) . ' to ' . $this->money($final) . '. Reason: ' . trim((string) $reason),
                    $actor,
                    ['from' => $current, 'to' => $final, 'reason' => trim((string) $reason)]
                );
            }

            $this->thread->event(
                $claim,
                'approved',
                'Claim approved for ' . $this->money($final) . '.',
                $actor,
                ['amount' => $final]
            );

            return $claim->fresh();
        });
    }

    public function decline(HrReimbursement $claim, User $actor, string $reason): HrReimbursement
    {
        $this->assertOpen($claim);

        if (trim($reason) === '') {
            throw new BusinessException('Declining a claim needs a reason.', 422);
        }

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $claim->update([
                'status'          => ReimbursementStatus::DECLINED,
                'held_from'       => null,
                'proposed_amount' => null,
                'decided_by'      => $actor->id,
                'decided_at'      => now(),
            ]);

            $this->thread->event($claim, 'declined', 'Claim declined. Reason: ' . trim($reason), $actor, ['reason' => trim($reason)]);

            return $claim->fresh();
        });
    }

    /**
     * Hold, with a reason, and optionally a proposed amount.
     *
     * The reason is always required and always free text. A fixed list would be
     * wrong within a month — the interesting holds are the ones nobody
     * anticipated. The proposed amount is optional structure, and its presence is
     * what turns a question into a counter-offer.
     */
    public function hold(HrReimbursement $claim, User $actor, string $reason, ?float $proposedAmount = null): HrReimbursement
    {
        $this->assertOpen($claim);

        if (trim($reason) === '') {
            throw new BusinessException('A hold needs a reason — the employee has to know what to do about it.', 422);
        }

        if ($proposedAmount !== null && $proposedAmount <= 0) {
            throw new BusinessException('A proposed amount must be more than zero.', 422);
        }

        return DB::transaction(function () use ($claim, $actor, $reason, $proposedAmount) {
            // Remembered so clearing returns it here. A claim held while already
            // held keeps its ORIGINAL origin rather than recording 'on_hold'.
            $heldFrom = $claim->isOnHold() ? $claim->held_from : $claim->status;

            $claim->update([
                'status'          => ReimbursementStatus::ON_HOLD,
                'held_from'       => $heldFrom,
                'proposed_amount' => $proposedAmount,
            ]);

            $body = 'Held. Reason: ' . trim($reason);
            if ($proposedAmount !== null) {
                $body .= ' Proposed amount: ' . $this->money($proposedAmount) . '.';
            }

            $this->thread->event($claim, 'held', $body, $actor, array_filter([
                'reason'          => trim($reason),
                'proposed_amount' => $proposedAmount,
            ], fn ($v) => $v !== null));

            return $claim->fresh();
        });
    }

    /** An admin talking to other admins about this claim. */
    public function note(HrReimbursement $claim, User $actor, string $body): void
    {
        $this->thread->note($claim, $actor, $body);
    }

    private function assertOpen(HrReimbursement $claim): void
    {
        if ($claim->isDecided()) {
            throw new BusinessException('This claim has already been decided.', 422);
        }
    }

    private function money(float|string $amount): string
    {
        return '₹' . number_format((float) $amount, 2);
    }
}
