<?php

namespace App\Services\Transport;

use App\Events\Transport\AdvanceRequested;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAdvance;
use App\Models\User;
use App\Support\Transport\AdvanceStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SNG-TRN-011 — advances, against a policy rather than against a form.
 *
 * The ticket's acceptance criterion is "Policy blocks/approves correctly;
 * exceptions escalate", and QA-004 is "Advance exceeds policy → approval or
 * escalation required; no unauthorised payment". Both are about the refusal, so
 * most of what follows is refusals.
 *
 * ── BR-P0-005, THE RULE THIS SERVICE EXISTS FOR ──────────────────────────
 * "Advance request + outstanding balance cannot exceed configured trip
 * exposure." Two halves people miss:
 *
 *   OUTSTANDING BALANCE, not this request alone. Checking each request in
 *   isolation lets four requests of 25% each fund a trip to 100%. Exposure is
 *   the sum of everything already committed on the trip.
 *
 *   COMMITTED INCLUDES APPROVED-BUT-UNPAID. An advance approved this morning
 *   and not yet disbursed is still money promised. Counting only `paid` funds
 *   the same driver twice for the same journey in the window between.
 *
 * ── ARITHMETIC ───────────────────────────────────────────────────────────
 * bcmath throughout, never floats. Step 9 P-007 and DEV §85 both require it,
 * and Step 13's FIN-06 blocks a release on float drift. These figures decide
 * whether money leaves; 0.1 + 0.2 is not an acceptable answer here.
 *
 * ── SEGREGATION ──────────────────────────────────────────────────────────
 * The requester cannot approve their own advance, at any seniority. Copied from
 * AdvanceTierService, which enforces the same rule on the HR ladder for the same
 * reason: "my manager approved it" means nothing if it can be me. BRW §75 and
 * STOS-FIN §129 both ask for it.
 */
class TripAdvanceService
{
    public function __construct(private TransportPolicyService $policies)
    {
    }

    /* ── Reading ──────────────────────────────────────────────────────── */

    public function find(int $id, int $tenantId): TripAdvance
    {
        $advance = TripAdvance::forTenant($tenantId)->find($id);

        if (! $advance) {
            throw new ResourceNotFoundException('Advance');
        }

        return $advance;
    }

    /** Everything already committed against this trip, as a decimal string. */
    public function exposureFor(int $tripId, int $tenantId): string
    {
        return TripAdvance::forTenant($tenantId)
            ->forTrip($tripId)
            ->committed()
            ->get()
            ->reduce(fn (string $sum, TripAdvance $a) => bcadd($sum, $a->committedAmount(), 2), '0.00');
    }

    /**
     * The most that may be committed to this trip, as a decimal string.
     *
     * Two caps, and the LOWER wins. A percentage alone could be escaped by
     * revising the freight upward; an absolute alone ignores the size of the
     * job. `advance.max_amount` of 0 means the absolute cap is switched off, not
     * that nothing may be advanced.
     */
    public function limitFor(TransportTrip $trip, int $tenantId): string
    {
        $policy  = $this->policies->all($tenantId);
        $freight = (string) ($trip->approved_freight ?? '0.00');
        $percent = (string) ($policy['advance.max_percent_of_freight'] ?? 30);

        $byPercent = bcdiv(bcmul($freight, $percent, 4), '100', 2);
        $absolute  = (string) ($policy['advance.max_amount'] ?? '0');

        if (bccomp($absolute, '0.00', 2) === 1 && bccomp($absolute, $byPercent, 2) === -1) {
            return $absolute;
        }

        return $byPercent;
    }

    /* ── Requesting — TRP-P0-007, PERM-006 ────────────────────────────── */

    /**
     * @param array<string,mixed> $data
     */
    public function request(TransportTrip $trip, array $data, int $tenantId, ?User $actor = null): TripAdvance
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }

        $amount = $this->amount($data['amount_requested'] ?? null);

        $this->assertOneCounterparty($data);

        // The refusal happens here, before a row exists. An over-limit request
        // that is stored and then refused still shows in the queue as something
        // somebody might approve.
        $this->assertWithinExposure($trip, $tenantId, $amount, (bool) ($data['override_limit'] ?? false), $actor);

        return DB::transaction(function () use ($trip, $data, $amount, $tenantId, $actor) {
            /** @var TripAdvance $advance */
            $advance = TripAdvance::create([
                'tenant_id'        => $tenantId,
                'trip_id'          => $trip->id,
                'driver_id'        => $data['driver_id'] ?? null,
                'supplier_id'      => $data['supplier_id'] ?? null,
                'amount_requested' => $amount,
                'currency'         => $data['currency'] ?? $trip->currency ?? 'INR',
                'purpose'          => $data['purpose'] ?? null,
                'payment_method'   => $data['payment_method'] ?? null,
                'status'           => AdvanceStatus::INITIAL,
                'requested_by'     => $actor?->id,
                'limit_overridden' => (bool) ($data['override_limit'] ?? false),
                'created_by'       => $actor?->id,
                'updated_by'       => $actor?->id,
            ]);

            $advance->audit('transport.advance.requested', $actor, new: [
                'trip_id'          => $trip->id,
                'amount_requested' => $amount,
                'status'           => AdvanceStatus::INITIAL,
                'limit_overridden' => $advance->limit_overridden,
            ]);

            // EVT-006. Payload is the registry's three fields and nothing more.
            AdvanceRequested::dispatch($advance);

            Log::channel('transport')->info('Trip advance requested', [
                'advance_id' => $advance->id, 'trip_id' => $trip->id,
                'amount' => $amount, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            ]);

            return $advance->fresh();
        });
    }

    /* ── Deciding — PERM-007 ──────────────────────────────────────────── */

    /**
     * Approve, possibly for less than was asked.
     *
     * @param array<string,mixed> $data
     */
    public function approve(TripAdvance $advance, array $data, int $tenantId, ?User $actor = null): TripAdvance
    {
        $this->assertTenant($advance, $tenantId);
        $this->assertMovable($advance, AdvanceStatus::APPROVED);
        $this->assertNotOwnRequest($advance, $actor);

        $approved = isset($data['amount_approved'])
            ? $this->amount($data['amount_approved'])
            : (string) $advance->amount_requested;

        // TRP-P0-008: "Only approved amount payable." Approving MORE than was
        // asked turns a control into a disbursement channel.
        if (bccomp($approved, (string) $advance->amount_requested, 2) === 1) {
            throw new BusinessException('An advance cannot be approved for more than was requested.', 422);
        }

        if (bccomp($approved, '0.00', 2) !== 1) {
            throw new BusinessException('An approved advance must be greater than zero. Reject it instead.', 422);
        }

        // Re-checked at approval, not only at request. Two requests can both
        // pass the check while pending and together breach the cap once
        // approved — the classic time-of-check race, and the reason exposure is
        // recomputed here against everything currently committed.
        $trip = $advance->trip()->first();
        if ($trip) {
            $this->assertWithinExposure($trip, $tenantId, $approved, (bool) $advance->limit_overridden, $actor);
        }

        return DB::transaction(function () use ($advance, $approved, $data, $actor) {
            $from = (string) $advance->status;

            $advance->forceFill([
                'status'          => AdvanceStatus::APPROVED,
                'amount_approved' => $approved,
                'decided_by'      => $actor?->id,
                'decided_at'      => now(),
                'decision_reason' => $data['decision_reason'] ?? null,
                'updated_by'      => $actor?->id,
            ])->save();

            $advance->auditTransition(
                'transport.advance.approved', $from, AdvanceStatus::APPROVED, $actor,
                context: array_filter([
                    'amount_requested' => (string) $advance->amount_requested,
                    'amount_approved'  => $approved,
                    'reason'           => $data['decision_reason'] ?? null,
                ]),
            );

            return $advance->fresh();
        });
    }

    public function reject(TripAdvance $advance, string $reason, int $tenantId, ?User $actor = null): TripAdvance
    {
        $this->assertTenant($advance, $tenantId);
        $this->assertMovable($advance, AdvanceStatus::REJECTED);
        $this->assertNotOwnRequest($advance, $actor);

        if (trim($reason) === '') {
            throw new BusinessException('A rejection needs a reason the requester can act on.', 422);
        }

        return DB::transaction(function () use ($advance, $reason, $actor) {
            $from = (string) $advance->status;

            $advance->forceFill([
                'status'          => AdvanceStatus::REJECTED,
                'decided_by'      => $actor?->id,
                'decided_at'      => now(),
                'decision_reason' => $reason,
                'updated_by'      => $actor?->id,
            ])->save();

            $advance->auditTransition(
                'transport.advance.rejected', $from, AdvanceStatus::REJECTED, $actor,
                context: ['reason' => $reason],
            );

            return $advance->fresh();
        });
    }

    /* ── Guards ───────────────────────────────────────────────────────── */

    private function assertTenant(TripAdvance $advance, int $tenantId): void
    {
        if ((int) $advance->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Advance');
        }
    }

    /** Only the edges AdvanceStatus has built preconditions for. */
    private function assertMovable(TripAdvance $advance, string $to): void
    {
        $from = (string) $advance->status;

        if (! AdvanceStatus::canMove($from, $to)) {
            throw new BusinessException(
                'An advance that is '.strtolower(AdvanceStatus::label($from))
                .' cannot be '.strtolower(AdvanceStatus::label($to)).'.',
                422
            );
        }
    }

    /** BRW §75 — the person who asked may not be the person who allows it. */
    private function assertNotOwnRequest(TripAdvance $advance, ?User $actor): void
    {
        if ($actor && (int) $advance->requested_by === (int) $actor->id) {
            throw new BusinessException(
                'You cannot decide your own advance request. It needs a second person.',
                422
            );
        }
    }

    /** DB-007 describes "driver/supplier" — one of them, not both, not neither. */
    private function assertOneCounterparty(array $data): void
    {
        $driver   = $data['driver_id'] ?? null;
        $supplier = $data['supplier_id'] ?? null;

        if ((bool) $driver === (bool) $supplier) {
            throw new BusinessException(
                'An advance is paid to a driver or to a supplier — name exactly one.',
                422
            );
        }
    }

    /**
     * BR-P0-005. The message names the numbers, because BRWM §70 asks a blocked
     * action to say what is blocked, why, and what would resolve it.
     */
    private function assertWithinExposure(
        TransportTrip $trip,
        int $tenantId,
        string $amount,
        bool $override,
        ?User $actor,
    ): void {
        $limit    = $this->limitFor($trip, $tenantId);
        $exposure = $this->exposureFor((int) $trip->id, $tenantId);
        $total    = bcadd($exposure, $amount, 2);

        if (bccomp($total, $limit, 2) !== 1) {
            return;
        }

        $policy = $this->policies->all($tenantId);

        if (! $override) {
            throw new BusinessException(sprintf(
                'This trip allows %s in advances and %s is already committed, so %s would exceed it by %s. '
                .'Reduce the amount, or ask an authorised approver to override the limit.',
                $this->money($limit), $this->money($exposure), $this->money($amount),
                $this->money(bcsub($total, $limit, 2))
            ), 422);
        }

        if (! ($policy['advance.override_allowed'] ?? true)) {
            throw new BusinessException(
                'This workspace does not permit the advance limit to be overridden.',
                422
            );
        }

        // An override is a decision somebody made, so it needs somebody. An
        // unauthenticated caller passing override_limit would otherwise lift
        // the cap with no name against it.
        if (! $actor) {
            throw new BusinessException('Overriding the advance limit requires an identified approver.', 422);
        }
    }

    private function amount(mixed $raw): string
    {
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new BusinessException('An advance must be an amount greater than zero.', 422);
        }

        return number_format((float) $raw, 2, '.', '');
    }

    private function money(string $amount): string
    {
        return '₹'.number_format((float) $amount, 2);
    }
}
