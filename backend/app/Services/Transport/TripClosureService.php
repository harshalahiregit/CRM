<?php

namespace App\Services\Transport;

use App\Events\Transport\TripClosed;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripBill;
use App\Models\Transport\TripCollection;
use App\Models\User;
use App\Support\Transport\ClosureScope;
use App\Support\Transport\TripStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STT-012 — closing a trip. API-009, CTR-013, PERM-005, EVT-012, BR-P0-017.
 *
 * ── READ THIS FIRST: THE ENDPOINT IS PLUMBED, NOT REACHABLE — D-106 ───────
 * `collection_pending` is the only state STT-012 leaves from, and no user can
 * reach it. STT-010 (`billable → billed`) runs through
 * TripBill::markInvoiced(), which has no caller and no route; every other
 * mention of that method in the codebase is a comment.
 *
 * `trip_bills` is P3's table and the standing rule is not to fix another
 * developer's file to make our own work reachable. So this class is built to
 * the registry and tested including its refusals, and the coverage document
 * marks it PLUMBED — never BUILT. The day P3 adds one route it becomes live
 * with no change here.
 *
 * ── THE CONTROLS ARE THE POINT, NOT THE STATE CHANGE ──────────────────────
 * FRS TRP-P0-014's rule is "no SILENT closure with unresolved critical
 * exceptions", and its acceptance is "User sees exactly why a trip is blocked".
 * Two of the five controls STT-012 names CANNOT BE EVALUATED:
 *
 *   settlement  `trip_settlements` does not exist (SNG-TRN-017)
 *   exceptions  `trip_exceptions` has a schema and a vocabulary but no model
 *
 * A control that cannot run REPORTS THAT IT DID NOT RUN. It is never counted as
 * a pass and never silently skipped — letting an unrunnable check default to
 * true is precisely what "no silent closure" forbids, and it would mean the
 * system's strongest claim ("this trip is closed and clean") rested on two
 * checks nobody performed.
 *
 * Every control returns a SENTENCE, not a boolean, because a screen that says
 * only "Blocked" fails UX §35 and BRW-048 as well as TRP-P0-014.
 *
 * ── BR-P0-017'S WAIVER IS DEFERRED, AND THE MESSAGE MUST SAY SO ───────────
 * Every other override this module has refused was UNSPECIFIED. BR-P0-017 is
 * not: the rule names the waiver and names the Owner role that may exercise it.
 * This is a specified behaviour we have not built yet, not an invented one we
 * are refusing — so the refusal says the waiver is NOT BUILT, never that no
 * waiver exists. A user told "this cannot be waived" when the rule says it can
 * is being misled by our screen. See ClosureScope::WAIVER_MESSAGE.
 *
 * ── NO REOPENING ─────────────────────────────────────────────────────────
 * `closed` is SM-TRP's only terminal state and no document defines a reverse.
 * That terminality is also this edge's idempotency, which is what stands in for
 * EVT-012's `close_version` (D-107): a second close cannot transition.
 */
class TripClosureService
{
    public function __construct(
        private TripDocumentService $documents,
    ) {
    }

    /**
     * Can this trip be closed, and if not, exactly why — without attempting it.
     *
     * TRP-P0-014's acceptance criterion is that a user SEES the blockers, so
     * this is the same computation `close()` gates on, called by the same
     * method. A screen must never have to infer a reason from a 422 it has not
     * triggered yet.
     *
     * @return array{closable:bool, state:string, reachable:bool, controls:array, blockers:array, not_checked:array, waiver:string}
     */
    public function readiness(TransportTrip $trip, int $tenantId): array
    {
        $this->assertTenant($trip, $tenantId);

        $controls = [
            $this->podControl($trip, $tenantId),
            $this->billingControl($trip, $tenantId),
            $this->collectionControl($trip, $tenantId),
            $this->settlementControl(),
            $this->exceptionControl(),
        ];

        $blockers   = array_values(array_filter($controls, fn (array $c) => $c['state'] === 'failed'));
        $notChecked = array_values(array_filter($controls, fn (array $c) => $c['state'] === 'not_checked'));

        $atTheDoor = TripStatus::canTransition((string) $trip->status, TripStatus::CLOSED);

        return [
            'closable' => $atTheDoor && $blockers === [],
            'state'    => (string) $trip->status,
            'state_label' => $trip->statusLabel(),

            // D-106, on the payload rather than only in a docblock. A client
            // that renders a Close button needs to know the state is currently
            // unoccupiable, and why, or it will show a control nobody can use.
            'reachable' => ClosureScope::REACHABLE,
            'reachable_note' => ClosureScope::REACHABLE ? null : ClosureScope::UNREACHABLE_BECAUSE,

            'at_the_door' => $atTheDoor,
            'controls'    => $controls,
            'blockers'    => $blockers,

            // Separate from blockers ON PURPOSE. These do not block closure —
            // nothing can evaluate them — but they must be visible, because the
            // difference between "checked and passed" and "could not be checked"
            // is the whole of TRP-P0-014's rule.
            'not_checked' => $notChecked,

            'waiver' => ClosureScope::WAIVER_MESSAGE,
        ];
    }

    /**
     * STT-012 — `collection_pending → closed`.
     *
     * CTR-013: "Closure controls run first". They do, literally — readiness()
     * is evaluated before the edge is checked, so a trip that is in the wrong
     * state AND has failing controls is told about the controls too.
     */
    public function close(TransportTrip $trip, ?string $reason, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTenant($trip, $tenantId);

        $from = $trip->status;
        $to   = TripStatus::CLOSED;

        // CTR-013 | closure_reason | REQUIRED | non-empty. Checked here as well
        // as in the FormRequest: a trip that closes with no recorded reason is
        // a trip nobody can account for later, and the contract says required.
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new BusinessException(
                'A closure reason is required. Say why this trip is being closed — '
                .'settled in full, written off, or superseded.',
                422
            );
        }

        $readiness = $this->readiness($trip, $tenantId);

        if ($readiness['blockers'] !== []) {
            throw new BusinessException(
                'This trip cannot be closed yet. '
                .implode(' ', array_column($readiness['blockers'], 'message'))
                .' '.ClosureScope::WAIVER_MESSAGE,
                422
            );
        }

        if (! TripStatus::canTransition($from, $to)) {
            throw new BusinessException(
                $from === TripStatus::CLOSED
                    ? 'This trip was already closed on '.$trip->closed_at?->format('j M Y, H:i').'.'
                    : 'Only a trip awaiting collection can be closed. This trip is '.$trip->statusLabel().'.',
                422
            );
        }

        $closed = DB::transaction(function () use ($trip, $from, $to, $reason, $readiness, $actor) {
            $trip->forceFill([
                'status'         => $to,
                'closed_at'      => now(),
                'closed_by'      => $actor?->id,
                'closure_reason' => $reason,
                'updated_by'     => $actor?->id,
            ])->save();

            $trip->auditTransition(
                'transport.trip.status_changed',
                $from,
                $to,
                $actor,
                [
                    'rule'          => ClosureScope::BR_017,
                    'transition'    => ClosureScope::EDGE,
                    'registry'      => ClosureScope::STT_012.'; '.ClosureScope::API_009.'; '.ClosureScope::EVT_012,
                    'authorization' => ClosureScope::AUTHORIZATION,
                    'sources'       => 'STT-012; API-009; CTR-013; PERM-005; EVT-012; BR-P0-017; FRS TRP-P0-014',
                    'trip_number'   => $trip->trip_number,
                    'closure_reason' => $reason,

                    // THE MOST IMPORTANT LINE IN THIS AUDIT ROW. Which controls
                    // actually ran, so nobody reading a closed trip six months
                    // from now mistakes it for one that passed all five.
                    'controls'      => array_combine(
                        array_column($readiness['controls'], 'key'),
                        array_column($readiness['controls'], 'state'),
                    ),
                    'not_checked'   => array_column($readiness['not_checked'], 'key'),
                    'waiver'        => ClosureScope::WAIVER_DEFERRED,

                    // STT-012's side effect, and what happened to it.
                    'profit_snapshot' => ClosureScope::SNAPSHOT_PROFIT,
                ],
            );

            return $trip->fresh();
        });

        // EVT-012, after the commit — no listener may see a closure that was
        // rolled back. Emitted though neither consumer exists; see TripClosed.
        TripClosed::dispatch($closed, array_combine(
            array_column($readiness['controls'], 'key'),
            array_column($readiness['controls'], 'state'),
        ));

        Log::channel('transport')->info('Trip closed', [
            'trip_id' => $closed->id, 'tenant_id' => $tenantId, 'user_id' => $actor?->id,
            'from' => $from, 'to' => $to,
            // Recorded on every closure so the deferral is visible in the logs
            // as well as the code — the same treatment D-64 gets on approval.
            'controls_not_checked' => array_column($readiness['not_checked'], 'key'),
            'profit_snapshot'      => ClosureScope::SNAPSHOT_PROFIT,
        ]);

        return $closed;
    }

    /* ── The five controls ───────────────────────────────────────────── */

    /** STT-012's "POD" — P3's own verdict, not a second implementation of it. */
    private function podControl(TransportTrip $trip, int $tenantId): array
    {
        $verdict = $this->documents->billingReadiness($trip, $tenantId);

        return $this->control('pod', 'Proof of delivery',
            $verdict['billable'] ? 'passed' : 'failed',
            $verdict['reason'],
        );
    }

    /** STT-012's "billing" — a prepared bill that Accounts has invoiced. */
    private function billingControl(TransportTrip $trip, int $tenantId): array
    {
        $bill = TripBill::forTenant($tenantId)->forTrip($trip->id)->first();

        if (! $bill) {
            return $this->control('billing', 'Billing', 'failed',
                'This trip has no prepared bill, so there is nothing to close against.');
        }

        if (! $bill->isInvoiced()) {
            return $this->control('billing', 'Billing', 'failed',
                'The bill for this trip has not been invoiced by Accounts yet.');
        }

        return $this->control('billing', 'Billing', 'passed',
            'Invoiced by Accounts.');
    }

    /** STT-012's "Settlement", read as the customer's side: is it collected? */
    private function collectionControl(TransportTrip $trip, int $tenantId): array
    {
        $collection = TripCollection::forTenant($tenantId)->forTrip($trip->id)->first();

        if (! $collection) {
            return $this->control('collection', 'Collection', 'failed',
                'No collection has been opened for this trip.');
        }

        if (! $collection->isSettled()) {
            return $this->control('collection', 'Collection', 'failed',
                'The customer still owes '.$collection->currency.' '.$collection->outstanding.' on this trip.');
        }

        return $this->control('collection', 'Collection', 'passed',
            'Paid in full.');
    }

    /**
     * STT-012's "Settlement", read as the SUPPLIER's side — and it cannot run.
     *
     * TRP-P0-017 (supplier/driver settlement) is SNG-TRN-017 and
     * `trip_settlements` does not exist. Reported as not checked, never as a
     * pass. The ambiguity in STT-012's own wording — "Settlement" could mean
     * either side — is why both are listed rather than one chosen.
     */
    private function settlementControl(): array
    {
        return $this->control('settlement', 'Supplier settlement', 'not_checked',
            ClosureScope::NOT_BUILT_REASONS['settlement']);
    }

    /**
     * TRP-P0-014's "no silent closure with unresolved critical exceptions".
     *
     * `trip_exceptions` has a schema and a vocabulary and NO MODEL — SNG-TRN-013
     * shipped the first two and stopped. The table is empty, so a naive count
     * would return zero and this control would "pass" while checking nothing.
     * That is the single most misleading thing this class could do, so it
     * reports not checked instead.
     */
    private function exceptionControl(): array
    {
        return $this->control('exceptions', 'Open exceptions', 'not_checked',
            ClosureScope::NOT_BUILT_REASONS['exceptions']);
    }

    /** @return array{key:string,label:string,state:string,message:string} */
    private function control(string $key, string $label, string $state, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'message' => $message];
    }

    private function assertTenant(TransportTrip $trip, int $tenantId): void
    {
        if ((int) $trip->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Trip');
        }
    }
}
