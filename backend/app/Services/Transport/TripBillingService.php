<?php

namespace App\Services\Transport;

use App\Events\Transport\BillingPrepared;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\User;
use App\Support\Transport\TripBillStatus;
use App\Support\Transport\TripStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SNG-TRN-015 — the billing trigger.
 *
 * The acceptance criterion is five words long and the whole ticket is in them:
 * **"No billing without defined preconditions."** So this service is mostly a
 * gate, and the gate is 014's:
 *
 *   STT-009 | pod_verified -> billable | trigger "Billing validation"
 *           | actor BillingEngine | guard "No blockers"
 *           | side effect "Create billing draft" | audited | LOCKED
 *
 * ── WHAT THIS SERVICE DOES NOT DO, AND MUST NOT ──────────────────────────
 * It does not create an invoice, number one, price one or post anything to a
 * ledger. Step 11 is explicit about the division:
 *
 *   EVT-010  InvoicePosted       Producer: Accounts
 *   EVT-011  CollectionRecorded  Producer: Accounts/Collections
 *
 * and FORBID-002 / LOCK-004 forbid Transport writing accounting entries at all.
 * What it produces is a `trip_bills` row with `invoice_id` NULL — the linkage
 * FLD-016 shapes — plus an event. Accounts fills the invoice in.
 *
 * ── THE PRECONDITIONS, IN ORDER ──────────────────────────────────────────
 *   1. the trip exists in this tenant                     (enumeration rule)
 *   2. it has not already been billed                     (one bill per trip)
 *   3. POD verified, or an approved exception waives it   (014's criterion)
 *   4. it is standing in `pod_verified`                   (STT-009's source)
 *
 * Rule 4 is checked LAST and is the one that cannot pass today: STT-006 and
 * STT-007 are P1's and unwired, so no trip reaches `delivered`, let alone
 * `pod_verified`. Ordering it last is what makes the refusal useful — somebody
 * gets told "there is no verified POD" when that is the real problem, rather
 * than a state-machine message that sends them looking in the wrong place.
 *
 * ── MONEY ────────────────────────────────────────────────────────────────
 * `billable_amount` is frozen from the trip's approved freight at the moment
 * billing is prepared. Read live instead, a later amendment to the trip would
 * silently restate an invoice Accounts has already raised. bcmath, never a
 * float — FIN-06.
 */
class TripBillingService
{
    public function __construct(private TripDocumentService $documents)
    {
    }

    /* ── Reading ──────────────────────────────────────────────────────── */

    public function forTrip(int $tripId, int $tenantId): ?TripBill
    {
        return TripBill::forTenant($tenantId)->forTrip($tripId)->first();
    }

    /**
     * Why this trip may or may not be billed — without doing anything.
     *
     * A pure read, so a screen can show the reason before offering the button.
     * It returns the same `reason` the refusal would carry, so the explanation
     * a user sees beforehand matches the one they would get after.
     *
     * @return array{preparable: bool, reason: string, already_billed: bool, amount: string|null, basis: string|null}
     */
    public function readiness(TransportTrip $trip, int $tenantId): array
    {
        $existing = $this->forTrip($trip->id, $tenantId);

        if ($existing) {
            return [
                'preparable' => false,
                'reason' => $existing->isInvoiced()
                    ? 'This trip has already been invoiced.'
                    : 'Billing has already been prepared for this trip.',
                'already_billed' => true,
                'amount' => (string) $existing->billable_amount,
                'basis'  => $existing->basis,
            ];
        }

        $pod = $this->documents->billingReadiness($trip, $tenantId);

        if (! $pod['billable']) {
            return [
                'preparable' => false, 'reason' => $pod['reason'],
                'already_billed' => false, 'amount' => null, 'basis' => null,
            ];
        }

        if ($trip->status !== TripStatus::POD_VERIFIED) {
            return [
                'preparable' => false,
                'reason' => $this->wrongStateReason($trip, $pod),
                'already_billed' => false, 'amount' => null,
                'basis' => $this->basisFrom($pod),
            ];
        }

        return [
            'preparable' => true,
            'reason' => 'Ready to prepare billing.',
            'already_billed' => false,
            'amount' => $this->amountFor($trip),
            'basis'  => $this->basisFrom($pod),
        ];
    }

    /* ── Writing ──────────────────────────────────────────────────────── */

    /**
     * API-010 — prepare customer billing. STT-009.
     *
     * Idempotent by the unique index: preparing twice returns the existing row
     * rather than creating a second linkage that would invite a second invoice.
     * "Duplicate callbacks" is a listed edge case for this ticket.
     */
    public function prepare(TransportTrip $trip, int $tenantId, ?User $actor = null): TripBill
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }

        $existing = $this->forTrip($trip->id, $tenantId);
        if ($existing) {
            return $existing;
        }

        $pod = $this->documents->billingReadiness($trip, $tenantId);

        if (! $pod['billable']) {
            throw new BusinessException($pod['reason']);
        }

        if ($trip->status !== TripStatus::POD_VERIFIED) {
            throw new BusinessException($this->wrongStateReason($trip, $pod));
        }

        $amount = $this->amountFor($trip);

        try {
            $bill = DB::transaction(function () use ($trip, $tenantId, $actor, $amount, $pod) {
                $bill = TripBill::create([
                    'tenant_id'       => $tenantId,
                    'trip_id'         => $trip->id,
                    'billable_amount' => $amount,
                    'currency'        => $trip->currency ?? 'INR',
                    'status'          => TripBillStatus::INITIAL,
                    'basis'           => $this->basisFrom($pod),
                    'prepared_by'     => $actor?->id,
                    'prepared_at'     => now(),
                    'created_by'      => $actor?->id,
                ]);

                // STT-009's own transition. Checked against the machine rather
                // than forced, so an edge that is ever removed fails loudly.
                if (TripStatus::canTransition((string) $trip->status, TripStatus::BILLABLE)) {
                    $from = (string) $trip->status;
                    $trip->forceFill(['status' => TripStatus::BILLABLE, 'updated_by' => $actor?->id])->save();
                    $trip->auditTransition('transport.trip.billable', $from, TripStatus::BILLABLE, $actor);
                }

                $bill->audit('transport.billing.prepared', $actor, new: [
                    'trip_id' => $trip->id, 'amount' => $amount, 'basis' => $bill->basis,
                ]);

                return $bill;
            });
        } catch (QueryException $e) {
            // A concurrent prepare won the unique index. Return the winner —
            // the trip is billed either way, and erroring would have the caller
            // retry against a row that already exists.
            $winner = $this->forTrip($trip->id, $tenantId);
            if ($winner) {
                return $winner;
            }
            throw $e;
        }

        BillingPrepared::dispatch($bill);

        Log::channel('transport')->info('Trip billing prepared', [
            'bill_id' => $bill->id, 'trip_id' => $trip->id, 'amount' => $amount,
            'basis' => $bill->basis, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $bill->fresh();
    }

    /**
     * Accounts has raised the invoice — STT-010, `billable → billed`.
     *
     * ── WHY THIS EXISTS, AND WHY IT SHOULD HAVE ON THE 17th ─────────────
     * `TripBill::markInvoiced()` was written as "the one door Accounts calls"
     * and then given no route, so nothing in the system could open it. The
     * consequence ran three states deep and was not visible from here:
     * `billable → billed` never fired, so `collection_pending` was unreachable,
     * so STT-012 could never close a trip. P1 built closure on top of a step
     * that had no caller. Recorded as D-106.
     *
     * The lesson, which is now in TEAM-CONTRACTS: a handover is not complete
     * when the method exists. It is complete when the other side can reach it.
     *
     * ── STILL NOT AN INVOICE ────────────────────────────────────────────
     * This records that Accounts raised one and moves the trip's state. It
     * writes no ledger entry and creates no invoice — FORBID-002, LOCK-004.
     * EVT-010 `InvoicePosted` is theirs to emit, before or after calling this.
     *
     * Idempotent, because a posting webhook retries: the same invoice id twice
     * returns the existing row untouched. A DIFFERENT id against an already
     * invoiced bill is refused — a trip is invoiced once, and silently
     * repointing it would orphan the first invoice with nothing recording that
     * it happened.
     */
    public function markInvoiced(
        TransportTrip $trip,
        int $invoiceId,
        int $tenantId,
        ?User $actor = null,
    ): TripBill {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }

        $bill = $this->forTrip($trip->id, $tenantId);

        if (! $bill) {
            throw new BusinessException(
                'Billing has not been prepared for this trip yet, so there is nothing to invoice.'
            );
        }

        if ($bill->isInvoiced()) {
            if ((int) $bill->invoice_id === $invoiceId) {
                return $bill;                       // the retry case
            }

            throw new BusinessException(
                'This trip is already linked to invoice '.$bill->invoice_id
                .'. A trip is invoiced once.'
            );
        }

        $bill->markInvoiced($invoiceId, $actor?->id);

        $bill->audit('transport.billing.invoiced', $actor, new: [
            'trip_id' => $trip->id, 'invoice_id' => $invoiceId,
        ]);

        Log::channel('transport')->info('Trip marked invoiced', [
            'bill_id' => $bill->id, 'trip_id' => $trip->id, 'invoice_id' => $invoiceId,
            'tenant_id' => $tenantId, 'user_id' => $actor?->id,
        ]);

        return $bill->fresh();
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    /**
     * What the trip is worth, frozen.
     *
     * The agreed freight and nothing else. Costs are NOT subtracted here —
     * a customer is billed what was agreed, not what the haul happened to cost;
     * cost against revenue is SNG-TRN-018's margin, a different question.
     */
    private function amountFor(TransportTrip $trip): string
    {
        return bcadd((string) ($trip->approved_freight ?? '0'), '0.00', 2);
    }

    /** Which arm of 014's criterion let this through — recorded, not inferred later. */
    private function basisFrom(array $pod): string
    {
        return $pod['waived'] ? 'exception_waiver' : 'verified_pod';
    }

    /**
     * Why a trip with a good POD still is not billable.
     *
     * ── FOUND BY TESTING AGAINST A REAL SERVER, NOT BY THE SUITE ────────
     * This used to say "Billing is prepared once its POD has been verified"
     * for EVERY wrong state. Which reads as nonsense to the one person most
     * likely to see it — somebody who has just verified the POD, watched the
     * documents panel turn green, and is now told to go and verify the POD.
     *
     * The unit tests never caught it because they put the trip in the right
     * state before asserting; only walking the real flow in order exposed it.
     *
     * The real cause is that nothing writes `delivered`: STT-006 and STT-007
     * are P1's and unwired (C-09), so a trip cannot reach `pod_verified` no
     * matter how good its paperwork is. Saying so is more useful than a
     * state name, because it tells the reader this is not theirs to fix.
     */
    private function wrongStateReason(TransportTrip $trip, array $pod): string
    {
        $state = TripStatus::label((string) $trip->status);

        // The POD is fine; the trip simply has not travelled.
        if ($pod['billable']) {
            return "The paperwork is in order, but this trip is still {$state} — "
                .'it has to be recorded as delivered before it can be billed.';
        }

        return "This trip is {$state}. Billing is prepared once its POD has been verified.";
    }
}
