<?php

namespace App\Services\Transport;

use App\Exceptions\BusinessException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripAssignment;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\PretripScope;
use App\Support\Transport\TripStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pre-dispatch readiness checklist — SNG-TRN-010 steps 4 and 5.
 *
 * RTM STOS-REQ-OPS-004, "Generate dispatch readiness checklist", acceptance
 * "Missing requirements identified". OPS §28: "Before dispatch, Sangoe creates a
 * readiness checklist." BRW-054: "Checklist generated automatically."
 *
 * ── WHY THIS IS A SEPARATE CLASS FROM AllocationService ───────────────────
 * Step 11 names a different actor for each transition — STT-004 AssignmentService,
 * STT-005 DispatchService — and the module has followed that split since ticket
 * 007 (TripEngine → TransportTripService, AssignmentService → AllocationService).
 * This is STT-005's side of it.
 *
 * ── GENERATION IS AN IDEMPOTENT UPSERT, NOT AN INSERT ─────────────────────
 * generate() may be called any number of times. It reconciles the trip's rows
 * against what policy currently says should exist:
 *
 *   a check that should exist and does not      → created, then evaluated
 *   a check that should exist and does          → re-evaluated in place
 *   a check that exists and no longer applies   → removed
 *
 * The unique index on (tenant_id, trip_id, check_key) is what makes that safe:
 * there is never a second row to reconcile against, so two dispatchers opening
 * the panel at once cannot produce a doubled checklist.
 *
 * ── RE-EVALUATION AND THE HUMAN STAMP ─────────────────────────────────────
 * The subtle rule, and the one worth reading twice:
 *
 *   RE-EVALUATION PRESERVES A COMPLETION ONLY WHILE THE RESULT IS UNCHANGED.
 *
 * A person who stamped "driver documents valid — all 3 present and valid" on
 * Monday confirmed a specific fact. If a certificate lapses overnight, that
 * stamp now vouches for something that is no longer true, so it is cleared and
 * the item returns to the checklist. If the fact has not changed, the stamp
 * stands and the dispatcher is not made to re-confirm the same thing every time
 * the screen refreshes.
 *
 * This is FLEET §16 applied to time rather than to resources: "Available is not
 * Eligible; Eligible is not Ready." Allocation asked whether this crew COULD be
 * assigned. Pre-trip asks whether they are still fit to leave, and the gap
 * between the two is exactly where an insurance policy lapses.
 *
 * ── WHAT IS EVALUATED, AND WHAT IS BORROWED ───────────────────────────────
 * Five checks, and two of them are not re-implemented here — they read the
 * verdicts VehicleEligibilityService and DriverEligibilityService already
 * produce, which is Q3's ruling ("reuse them, do not build a new trip-level
 * document engine") and also the only way the two screens can agree.
 *
 * Note which checks are borrowed and which are NOT. From the driver verdict this
 * takes `licence` and `documents` only; from the vehicle verdict, `documents`
 * only. The other eligibility checks are deliberately left behind, because by
 * pre-trip time they are guaranteed to fail for the right reason: an allocated
 * vehicle's status is `allocated`, not `available`, and an assigned driver's
 * availability is `assigned`, not `available`. Those checks answer "is this
 * resource free to be crewed?" — a question already settled. Copying them in
 * would block every trip in the system on its own allocation.
 */
class PretripService
{
    public function __construct(
        private TransportPolicyService $policies,
        private VehicleEligibilityService $vehicleEligibility,
        private DriverEligibilityService $driverEligibility,
    ) {}

    /**
     * The trip states in which a checklist may be built.
     *
     * INFERRED — no document names an entry state, and this is flagged rather
     * than presented as quoted. Two facts bound it:
     *
     *   SM-TRP gives `allocated` the exit gate "Pre-trip complete", so the work
     *   plainly happens in that state; Step 5's own model agrees
     *   (assigned → precheck).
     *
     *   But RTM OPS-004's acceptance is "MISSING REQUIREMENTS IDENTIFIED", and a
     *   checklist that can only be built once everything is assigned can never
     *   identify a missing assignment. Generating from `approved` is what makes
     *   that acceptance criterion reachable at all.
     *
     * Before `approved` there is no commitment to be ready for; after
     * `pretrip_ok` the gate has already been passed and rebuilding the list
     * would silently un-pass it.
     */
    public const GENERATABLE_FROM = [TripStatus::APPROVED, TripStatus::ALLOCATED];

    /**
     * Build or refresh a trip's checklist, then evaluate every row.
     *
     * @return Collection<int,TripPretripCheck> ordered as OPS §28 orders them
     */
    public function generate(TransportTrip $trip, int $tenantId, ?User $actor = null): Collection
    {
        $this->assertGeneratable($trip);

        $policy     = $this->policies->all($tenantId);
        $assignment = $this->activeAssignment($trip, $tenantId);
        $applicable = $this->applicableChecks($trip, $assignment, $policy);

        // One transaction: a half-built checklist would read as BLOCKED or
        // IN_PROGRESS for whoever looked between the writes. STOS-DB §192.
        DB::transaction(function () use ($trip, $tenantId, $actor, $applicable, $assignment, $policy) {
            $existing = TripPretripCheck::forTenant($tenantId)->forTrip($trip->id)->get()
                ->keyBy('check_key');

            // Rows policy no longer asks for. Removing them is not destroying
            // history — the audit trail keeps what they said, and leaving a
            // disabled check on the list would block a trip on a rule the
            // tenant has switched off.
            $stale = $existing->keys()->diff(array_keys($applicable));
            if ($stale->isNotEmpty()) {
                TripPretripCheck::forTenant($tenantId)->forTrip($trip->id)
                    ->whereIn('check_key', $stale->all())->delete();

                $trip->audit('transport.pretrip.checks_removed', $actor, context: [
                    'trip_number' => $trip->trip_number,
                    'checks'      => $stale->values()->all(),
                    'reason'      => 'no longer applicable under current policy',
                ]);
            }

            $revoked = [];

            foreach ($applicable as $key => $isCritical) {
                $row = $existing->get($key) ?? new TripPretripCheck([
                    'tenant_id'  => $tenantId,
                    'trip_id'    => $trip->id,
                    'check_key'  => $key,
                    'created_by' => $actor?->id,
                ]);

                [$result, $detail] = $this->evaluate($key, $trip, $assignment, $tenantId, $isCritical, $policy);

                if ($entry = $this->applyResult($row, $result, $detail, $isCritical, $actor)) {
                    $revoked[] = $entry;
                }
            }

            // Someone confirmed these, and the fact they confirmed has since
            // changed underneath them. That is worth its own line: an auditor
            // asking "who signed this off and why does it no longer hold?" must
            // not have to diff two checklists to find out.
            if ($revoked !== []) {
                $trip->audit('transport.pretrip.confirmations_revoked', $actor, context: [
                    'trip_number' => $trip->trip_number,
                    'reason'      => 'the evaluated result changed after the check had been confirmed',
                    'revoked'     => $revoked,
                ]);
            }
        });

        $checks = $this->checksFor($trip, $tenantId);

        $trip->audit('transport.pretrip.generated', $actor, context: [
            'rule'        => PretripScope::OPS_004,
            'trip_number' => $trip->trip_number,
            'readiness'   => TripPretripCheck::readinessOf($checks),
            'checks'      => $checks->map(fn (TripPretripCheck $c) => [
                'key' => $c->check_key, 'critical' => $c->is_critical,
                'result' => $c->result, 'detail' => $c->detail,
                'completed' => $c->isCompleted(),
            ])->all(),
        ]);

        return $checks;
    }

    /**
     * A trip's checklist, in OPS §28's category order.
     *
     * Ordering is done in PHP against PretripCheckKey::ALL rather than in SQL,
     * because the document's order is not alphabetical and is not a column.
     *
     * @return Collection<int,TripPretripCheck>
     */
    public function checksFor(TransportTrip $trip, int $tenantId): Collection
    {
        $order = array_flip(PretripCheckKey::ALL);

        $rows = TripPretripCheck::forTenant($tenantId)->forTrip($trip->id)->get()
            ->sortBy(fn (TripPretripCheck $c) => $order[$c->check_key] ?? PHP_INT_MAX)
            ->values();

        return new Collection($rows->all());
    }

    /**
     * The full readiness picture for one trip — the shape both the API and the
     * screen consume.
     *
     * Mirrors the eligibility verdict's shape on purpose: a dispatcher looking at
     * allocation blockers and pre-trip blockers on the same page should not have
     * to learn two vocabularies.
     *
     * @return array<string,mixed>
     */
    public function readiness(TransportTrip $trip, int $tenantId): array
    {
        $checks = $this->checksFor($trip, $tenantId);
        $status = TripPretripCheck::readinessOf($checks);

        return [
            'trip_id'        => (int) $trip->id,
            'trip_number'    => $trip->trip_number,
            'trip_status'    => $trip->status,
            'status'         => $status,
            'status_label'   => PretripReadiness::label($status),
            'ready'          => $status === PretripReadiness::READY,
            'generatable'    => in_array($trip->status, self::GENERATABLE_FROM, true),
            'total'          => $checks->count(),
            'completed'      => $checks->filter(fn (TripPretripCheck $c) => $c->isCompleted())->count(),
            'blockers'       => TripPretripCheck::blockersOf($checks),
            'warnings'       => TripPretripCheck::warningsOf($checks),

            // The SAME sentence the gate would refuse with, computed here so a
            // screen never has to attempt the transition just to find out why it
            // cannot. UX §35: never merely show Blocked — say why, what is
            // missing, and what happens after. Null when the trip may proceed.
            'blocking_message' => $status === PretripReadiness::READY ? null : $this->refusalMessage(
                $status,
                TripPretripCheck::blockersOf($checks),
                $checks->filter(fn (TripPretripCheck $c) => ! $c->isCompleted())
                    ->map(fn (TripPretripCheck $c) => $c->label())->values()->all(),
            ),
            'checks'         => $checks->map(fn (TripPretripCheck $c) => [
                'id'             => (int) $c->id,
                'key'            => $c->check_key,
                'label'          => $c->label(),
                'category'       => $c->category(),
                'category_label' => $c->categoryLabel(),
                'critical'       => (bool) $c->is_critical,
                'result'         => $c->result,
                'result_label'   => $c->resultLabel(),
                'detail'         => $c->detail,
                'remarks'        => $c->remarks,
                'blocks'         => $c->blocks(),
                'warning'        => $c->isWarning(),
                'completed'      => $c->isCompleted(),
                'completed_at'   => $c->completed_at?->toIso8601String(),
                'completed_by'   => $c->completed_by,
                'evaluated_at'   => $c->evaluated_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /* ── Step 6 · the gate, and STT-005's precondition work ──────────── */

    /**
     * Move an allocated trip to pretrip_ok once its checklist is READY.
     *
     * ── THE REGISTRY ROW THIS IMPLEMENTS ──────────────────────────────────
     *   STT-005 | SM-TRP | allocated → dispatched | trigger "Pass pre-trip"
     *           | actor DispatchService | precondition "ALL CHECKS PASSED"
     *           | side effect "Record departure readiness" | Audit Yes | LOCKED
     *
     * Under the Q1 ruling of 2026-09-09 that row is served by Step 9's two
     * edges, and this method owns the first of them. It implements STT-005's
     * precondition and its side effect; the destination belongs to the dispatch
     * ticket that D-18 says does not yet exist. Nothing here writes `dispatched`.
     *
     * ── THE GATE, AND THE FOUR DOCUMENTS THAT AGREE ON IT ─────────────────
     *   BRW-046  "Vehicle cannot dispatch until all mandatory dispatch checks pass."
     *   BRW-052  "Critical failure: Dispatch blocked."
     *   OPS §30  "Any critical failure blocks dispatch."
     *   CMP §159 OPS asks CMP "Is this transaction compliant for dispatch?" and
     *            "CMP responds deterministically."
     *
     * Deterministically is the operative word, and it is why the gate reads the
     * stored checklist rather than re-evaluating on the spot: the answer a
     * dispatcher was shown is the answer the gate acts on. A gate that re-ran the
     * checks itself could refuse a trip whose screen said READY a second earlier,
     * with nothing on that screen to explain why.
     *
     * ── WHY THE ACTOR IS THIS CLASS AND NOT A DispatchService ─────────────
     * Step 11 names DispatchService as STT-005's actor, and the module has
     * followed the registry's actor naming since ticket 007. A DispatchService
     * is the right home for the SECOND edge, where dispatch is actually
     * confirmed. Creating it now, to hold one method that does not dispatch
     * anything, would name a class after work this ticket is not doing.
     */
    public function passPretrip(TransportTrip $trip, int $tenantId, ?User $actor = null): TransportTrip
    {
        $this->assertTransitionAllowed($trip);

        $checks = $this->checksFor($trip, $tenantId);
        $status = TripPretripCheck::readinessOf($checks);

        if (! PretripReadiness::permitsTransition($status)) {
            $this->refuse($trip, $checks, $status, $tenantId, $actor);
        }

        // STOS-DB §192 — the state change and its audit are one act.
        return DB::transaction(function () use ($trip, $checks, $tenantId, $actor) {
            $from = $trip->status;

            $trip->forceFill([
                'status'     => TripStatus::PRETRIP_OK,
                'updated_by' => $actor?->id,
            ])->save();

            // STT-005's "Audit Yes", and the same evidence discipline as ticket
            // 009's allocation audit: record WHAT WAS TRUE at the moment of the
            // transition, not merely that it happened. A row saying "passed"
            // proves nothing a week later; a row carrying every check, its
            // result, its criticality and who confirmed it can be re-read.
            $trip->auditTransition(
                'transport.trip.status_changed',
                $from,
                TripStatus::PRETRIP_OK,
                $actor,
                [
                    'rule'        => PretripScope::BRW_DISPATCH_READINESS,
                    'transition'  => PretripScope::STATE_EDGE_OWNED,
                    'registry'    => PretripScope::STT_005.' (precondition work only — destination deferred, D-18)',
                    'sources'     => 'BRW-046; BRW-052; OPS §30; CMP §159',
                    'trip_number' => $trip->trip_number,
                    'readiness'   => PretripReadiness::READY,
                    'checks'      => $checks->map(fn (TripPretripCheck $c) => [
                        'key'          => $c->check_key,
                        'label'        => $c->label(),
                        'critical'     => (bool) $c->is_critical,
                        'result'       => $c->result,
                        'detail'       => $c->detail,
                        'remarks'      => $c->remarks,
                        'completed_by' => $c->completed_by,
                        'completed_at' => $c->completed_at?->toIso8601String(),
                        'evaluated_at' => $c->evaluated_at?->toIso8601String(),
                    ])->all(),
                    'warnings' => TripPretripCheck::warningsOf($checks),
                ],
            );

            Log::channel('transport')->info('Pre-trip passed', [
                'trip_id' => $trip->id, 'tenant_id' => $tenantId,
                'user_id' => $actor?->id, 'checks' => $checks->count(),
            ]);

            return $trip->fresh();
        });
    }

    /**
     * Refuse the transition, leaving a record of why.
     *
     * Mirrors AllocationService::assertEligible(): the audit row is written
     * BEFORE the exception and OUTSIDE any transaction, so a refusal leaves
     * evidence rather than nothing. BR-P0-003's "allocation conflict log" was the
     * precedent; the same argument applies to a dispatch block, which is exactly
     * the event an auditor asks about after an incident.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int,TripPretripCheck>  $checks
     */
    private function refuse(
        TransportTrip $trip,
        $checks,
        string $status,
        int $tenantId,
        ?User $actor,
    ): never {
        $blockers   = TripPretripCheck::blockersOf($checks);
        $incomplete = $checks->filter(fn (TripPretripCheck $c) => ! $c->isCompleted())
            ->map(fn (TripPretripCheck $c) => $c->label())->values()->all();

        $trip->audit('transport.pretrip.refused', $actor, context: [
            'rule'        => PretripScope::BRW_DISPATCH_READINESS,
            'sources'     => 'BRW-046; BRW-052; OPS §30; CMP §159',
            'trip_number' => $trip->trip_number,
            'readiness'   => $status,
            'blockers'    => $blockers,
            'incomplete'  => $incomplete,
            'checks'      => $checks->map(fn (TripPretripCheck $c) => [
                'key' => $c->check_key, 'critical' => (bool) $c->is_critical,
                'result' => $c->result, 'detail' => $c->detail,
                'completed' => $c->isCompleted(),
            ])->all(),
        ]);

        Log::channel('transport')->info('Pre-trip refused', [
            'trip_id' => $trip->id, 'tenant_id' => $tenantId,
            'user_id' => $actor?->id, 'readiness' => $status,
        ]);

        throw new BusinessException($this->refusalMessage($status, $blockers, $incomplete), 422);
    }

    /**
     * BRW-048, verbatim: "If dispatch fails, Sangoe must display exact reason."
     * Its own example is "Dispatch blocked — Driver licence expired", and OPS §30
     * goes further by pairing the reason with the fix: "DISPATCH BLOCKED /
     * Resolve: Assign compliant driver."
     *
     * So each branch below names the failure AND the next action. UX §35's rule
     * is that a screen must never merely show Blocked.
     *
     * @param  array<int,string>  $blockers
     * @param  array<int,string>  $incomplete
     */
    private function refusalMessage(string $status, array $blockers, array $incomplete): string
    {
        if ($status === PretripReadiness::NOT_STARTED) {
            return 'Pre-trip checks have not been run for this trip. '
                .'Generate the checklist and complete every item before dispatch.';
        }

        if ($blockers !== []) {
            return 'Dispatch blocked — '.implode(' ', $blockers)
                .' Resolve the failed checks, then re-run the checklist.';
        }

        return 'Pre-trip checks are not complete. Still to confirm: '
            .implode(', ', $incomplete).'.';
    }

    private function assertTransitionAllowed(TransportTrip $trip): void
    {
        if (TripStatus::canTransition($trip->status, TripStatus::PRETRIP_OK)) {
            return;
        }

        if ($trip->status === TripStatus::PRETRIP_OK) {
            // Not an error worth an exception's tone — the caller is asking for
            // a state the trip is already in. Still refused, so nothing re-audits
            // a transition that already happened.
            throw new BusinessException(
                'This trip has already passed its pre-trip checks.',
                422,
            );
        }

        throw new BusinessException(
            'A trip that is '.TripStatus::label($trip->status).' cannot pass pre-trip checks. '
            .'A vehicle and driver must be allocated first.',
            422,
        );
    }

    /**
     * Invalidate a trip's checklist because the crew it certified has changed.
     *
     * ── WHAT "INVALIDATE" MEANS HERE, AND WHY ─────────────────────────────
     * Every row is reset to `pending`, its evaluation and its human stamp are
     * cleared, and the rows STAY. Three decisions, each with a reason:
     *
     *   NOT DELETED. Audit entries are written against these rows by
     *   complete(); deleting them would leave those entries pointing at a
     *   subject that no longer exists — the same referential failure D-14 was
     *   raised for, and the opposite of the evidence discipline this module has
     *   followed since ticket 009.
     *
     *   THE WHOLE RUN, not just the crew-dependent items. A checklist certifies
     *   one trip-with-crew configuration. Letting `commercial.order_approved`
     *   keep its stamp would be defensible in isolation — the order did not
     *   change — but it would mean a re-crewed trip carries confirmations made
     *   about a different vehicle and a different driver. On a safety gate, whole
     *   is the safer rule and the easier one to explain. The cost is one extra
     *   confirmation.
     *
     *   REMARKS SURVIVE. What a person wrote is theirs, not ours to discard.
     *
     * Afterwards every row is pending, so readinessOf() reads NOT_STARTED and
     * the gate refuses with "Pre-trip checks have not been run for this trip."
     * Regeneration is required and is never automatic: generate() re-evaluates
     * from scratch, which is Step 4's reconciliation doing exactly the job it
     * already does.
     */
    public function invalidate(TransportTrip $trip, int $tenantId, ?User $actor = null, ?string $reason = null): int
    {
        $checks = TripPretripCheck::forTenant($tenantId)->forTrip($trip->id)->get();

        if ($checks->isEmpty()) {
            return 0;
        }

        $detail = $reason ?? 'The vehicle or driver changed after this check was recorded.';

        // Captured before the reset. The whole point of the audit line is to say
        // what was thrown away, so reading it back afterwards would record
        // NOT_STARTED every time and prove nothing.
        $was = TripPretripCheck::readinessOf($checks);

        DB::transaction(function () use ($checks, $detail, $actor) {
            foreach ($checks as $check) {
                $check->forceFill([
                    'result'       => PretripResult::PENDING,
                    'detail'       => $detail.' Re-run the checklist.',
                    'evaluated_at' => null,
                    'completed_by' => null,
                    'completed_at' => null,
                    'updated_by'   => $actor?->id,
                    // remarks deliberately untouched — see the docblock.
                ])->save();
            }
        });

        $trip->audit('transport.pretrip.invalidated', $actor, context: [
            'trip_number' => $trip->trip_number,
            'reason'      => $detail,
            'checks'      => $checks->pluck('check_key')->all(),
            'was'         => $was,
        ]);

        Log::channel('transport')->info('Pre-trip checklist invalidated', [
            'trip_id' => $trip->id, 'tenant_id' => $tenantId,
            'user_id' => $actor?->id, 'checks' => $checks->count(),
        ]);

        return $checks->count();
    }

    /* ── Step 5 · completion, which is the acceptance criterion ──────── */

    /**
     * Record that a person has completed one check.
     *
     * THIS METHOD IS THE ACCEPTANCE CRITERION. The ticket's entire stated AC is
     * "Checklist completion is time/user stamped", and completed_by/completed_at
     * are that sentence. Everything else in this ticket exists so that this stamp
     * means something.
     *
     * ── WHAT A PERSON MAY AND MAY NOT SET ─────────────────────────────────
     * They confirm; they do not grade. All five generated checks are evaluated
     * by the system from data it can read, so a person supplying their own
     * pass/fail would be asserting something the system can already see — and if
     * they disagreed with it, that would be an OVERRIDE, which is P1 in all
     * three places the package raises it (RTM CMP-007, BRW-049, PLN-007).
     *
     * FRS TRP-P0-005's "Supervisor sign-off for critical failures" is that same
     * override: OPS §30 blocks on a critical failure and §31 says only authorized
     * roles may release it. So a blocked check cannot be signed away here, and
     * saying so plainly is better than a stamp that quietly does nothing.
     *
     * What they may add is a remark (OPS §41).
     *
     * ── WHY A BLOCKED CHECK CAN STILL BE COMPLETED ────────────────────────
     * It can — completion and outcome are different axes. Marking a failed check
     * as seen is how a supervisor works through the list, and OPS §29 needs the
     * distinction: a checklist where every item has been looked at and one has
     * failed is BLOCKED, not IN_PROGRESS. Completing it never changes the result
     * and never unblocks the trip.
     */
    public function complete(
        TripPretripCheck $check,
        int $tenantId,
        ?User $actor = null,
        ?string $remarks = null,
    ): TripPretripCheck {
        $this->assertOwnedBy($check, $tenantId);

        if ($check->isPending()) {
            // Nothing evaluated it, so there is nothing to confirm. In practice
            // this means generate() has not run since the row appeared.
            throw new BusinessException(
                $check->label().' has not been evaluated yet. Refresh the checklist and try again.',
                422,
            );
        }

        $wasCompleted = $check->isCompleted();

        $check->forceFill([
            'completed_by' => $actor?->id,
            'completed_at' => now(),
            'remarks'      => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : $check->remarks,
            'updated_by'   => $actor?->id,
        ])->save();

        $check->audit(
            $wasCompleted ? 'transport.pretrip.check_recompleted' : 'transport.pretrip.check_completed',
            $actor,
            context: [
                'rule'        => PretripScope::OPS_004,
                'trip_id'     => (int) $check->trip_id,
                'check'       => $check->check_key,
                'check_label' => $check->label(),
                'critical'    => (bool) $check->is_critical,
                'result'      => $check->result,
                'detail'      => $check->detail,
                'remarks'     => $check->remarks,
                'blocks'      => $check->blocks(),
            ],
        );

        return $check->fresh();
    }

    /**
     * Complete several checks in one act — the shape Step 5's own API sketch
     * uses ("POST /prechecks | check_items, evidence").
     *
     * All-or-nothing: a half-applied submission would leave the checklist in a
     * state the submitter never saw and cannot reason about.
     *
     * @param  array<int,array{id:int,remarks?:string|null}>  $items
     * @return Collection<int,TripPretripCheck>
     */
    public function completeMany(TransportTrip $trip, array $items, int $tenantId, ?User $actor = null): Collection
    {
        DB::transaction(function () use ($trip, $items, $tenantId, $actor) {
            foreach ($items as $item) {
                $check = TripPretripCheck::forTenant($tenantId)
                    ->forTrip($trip->id)
                    ->find($item['id'] ?? 0);

                if ($check === null) {
                    // 404-shaped facts are never leaked as "exists elsewhere".
                    throw new BusinessException('That pre-trip check is not on this trip.', 422);
                }

                $this->complete($check, $tenantId, $actor, $item['remarks'] ?? null);
            }
        });

        return $this->checksFor($trip, $tenantId);
    }

    /**
     * A check reached by id must belong to the tenant that asked for it.
     *
     * Belt and braces: every caller already resolves rows through forTenant(),
     * but this method is the one a controller could reach with a route-model
     * binding, and cross-tenant reads are the failure this module guards hardest.
     */
    private function assertOwnedBy(TripPretripCheck $check, int $tenantId): void
    {
        if ((int) $check->tenant_id !== $tenantId) {
            throw new BusinessException('That pre-trip check is not on this trip.', 422);
        }
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function assertGeneratable(TransportTrip $trip): void
    {
        if (in_array($trip->status, self::GENERATABLE_FROM, true)) {
            return;
        }

        // BRWM §70's tone rule: say what is wrong AND what to do.
        throw new BusinessException(
            'Pre-trip checks cannot be prepared for a trip that is '
            .TripStatus::label($trip->status).'. A trip must be approved or allocated first.',
            422,
        );
    }

    private function activeAssignment(TransportTrip $trip, int $tenantId): ?TripAssignment
    {
        return TripAssignment::forTenant($tenantId)
            ->forTrip($trip->id)
            ->active()
            ->with(['vehicle', 'driver'])
            ->first();
    }

    /**
     * Which checks this trip should carry, and whether each one blocks.
     *
     * FRS TRP-P0-005's "Mandatory checklist by vehicle/service type" resolves
     * here: the vehicle type comes from whatever vehicle is currently assigned,
     * the service type from the trip's order.
     *
     * @param  array<string,mixed>  $policy
     * @return array<string,bool>   check_key => is_critical, in OPS §28 order
     */
    private function applicableChecks(TransportTrip $trip, ?TripAssignment $assignment, array $policy): array
    {
        $vehicleType = $assignment?->vehicle?->vehicle_type;
        $serviceType = $trip->order?->service_type;

        $applicable = [];

        // PretripCheckKey::ALL, not ::GENERATED, so that if a tenant's policy is
        // ever widened the order still follows the document. Policy is what
        // decides membership; a non-generated key can never be enabled (the
        // policy service refuses it), so this cannot pull in an unevaluatable row.
        foreach (PretripCheckKey::ALL as $key) {
            if ($this->policies->pretripCheckApplies($policy, $key, $vehicleType, $serviceType)) {
                $applicable[$key] = $this->policies->pretripCheckIsCritical($policy, $key);
            }
        }

        return $applicable;
    }

    /**
     * Write an evaluation onto a row, preserving or clearing the human stamp.
     *
     * See the class docblock — this is where "re-evaluation preserves a
     * completion only while the result is unchanged" actually happens.
     */
    private function applyResult(
        TripPretripCheck $row,
        string $result,
        string $detail,
        bool $isCritical,
        ?User $actor,
    ): ?array {
        $resultChanged = $row->exists && $row->result !== $result;
        $was           = $row->result;
        $revoked       = null;

        $row->forceFill([
            'is_critical'  => $isCritical,
            'result'       => $result,
            'detail'       => $detail,
            'evaluated_at' => now(),
            'updated_by'   => $actor?->id,
        ]);

        if ($resultChanged && $row->isCompleted()) {
            // Report it as well as doing it. A person's confirmation being
            // revoked is a fact about THEIR act, and leaving it inferable only
            // by diffing two `generated` audit rows is the same shape of gap
            // that ticket 009's refused allocations had. See generate().
            $revoked = [
                'check'        => $row->check_key,
                'label'        => $row->label(),
                'was_result'   => $was,
                'now_result'   => $result,
                'detail'       => $detail,
                'completed_by' => $row->completed_by,
                'completed_at' => $row->completed_at?->toIso8601String(),
            ];

            $row->forceFill([
                'completed_by' => null,
                'completed_at' => null,
                // The remark is kept. It was a note about this check, not a
                // vouching for the old result, and discarding what a person
                // wrote is not ours to do.
            ]);
        }

        $row->save();

        return $revoked;
    }

    /**
     * Evaluate one check.
     *
     * Returns [result, detail]. The detail is BRW-048's "exact reason" and is
     * written in BRWM §70's tone — what is wrong, and what to do about it.
     *
     * @param  array<string,mixed>  $policy
     * @return array{0:string,1:string}
     */
    private function evaluate(
        string $key,
        TransportTrip $trip,
        ?TripAssignment $assignment,
        int $tenantId,
        bool $isCritical,
        array $policy,
    ): array {
        return match ($key) {
            PretripCheckKey::ORDER_APPROVED     => $this->evaluateOrderApproved($trip, $isCritical),
            PretripCheckKey::DRIVER_ASSIGNED    => $this->evaluateDriverAssigned($assignment, $isCritical),
            PretripCheckKey::VEHICLE_ASSIGNED   => $this->evaluateVehicleAssigned($assignment, $isCritical),
            PretripCheckKey::DRIVER_DOCUMENTS   => $this->evaluateDriverDocuments($assignment, $trip, $tenantId, $isCritical, $policy),
            PretripCheckKey::VEHICLE_COMPLIANCE => $this->evaluateVehicleCompliance($assignment, $trip, $tenantId, $isCritical, $policy),

            // Unreachable by construction: the policy service refuses to enable
            // any key outside ::GENERATED. This arm exists so that a future
            // widening fails loudly here rather than silently marking an
            // unevaluated check as passed.
            default => throw new \LogicException("No evaluator for pre-trip check {$key}"),
        };
    }

    /** OPS §28 Commercial, "Order approved"; BRW-047 "order approval". */
    private function evaluateOrderApproved(TransportTrip $trip, bool $isCritical): array
    {
        $order = $trip->order;

        if ($order === null) {
            // Defensive, not a normal shape. FLD-005 marks order_id nullable but
            // SNG-TRN-007 deliberately made the column NOT NULL (CTR-004,
            // "cannot create orphan trip") and recorded that as the one place
            // Step 11 contradicts itself. So this branch only fires if the order
            // row is deleted out from under the trip.
            return [
                PretripResult::forFailure($isCritical),
                'This trip has no transport order. Link an approved order before dispatch.',
            ];
        }

        // isTripEligible() is the order model's own name for "approved", and
        // reusing it keeps this check and SNG-TRN-007's trip-creation gate from
        // ever disagreeing about what an approved order is.
        if ($order->isTripEligible()) {
            return [PretripResult::PASS, 'Order '.$order->order_number.' is approved.'];
        }

        return [
            PretripResult::forFailure($isCritical),
            'Order '.$order->order_number.' is '.$order->statusLabel()
            .' — it must be approved before the trip can dispatch.',
        ];
    }

    /** OPS §28 Driver, "Assigned"; BRW-046. */
    private function evaluateDriverAssigned(?TripAssignment $assignment, bool $isCritical): array
    {
        $driver = $assignment?->driver;

        if ($driver === null) {
            return [
                PretripResult::forFailure($isCritical),
                'No driver is assigned to this trip. Allocate a driver before dispatch.',
            ];
        }

        return [PretripResult::PASS, $driver->displayName().' is assigned.'];
    }

    /** OPS §28 Vehicle, "Assigned"; BRW-046. */
    private function evaluateVehicleAssigned(?TripAssignment $assignment, bool $isCritical): array
    {
        $vehicle = $assignment?->vehicle;

        if ($vehicle === null) {
            return [
                PretripResult::forFailure($isCritical),
                'No vehicle is assigned to this trip. Allocate a vehicle before dispatch.',
            ];
        }

        return [PretripResult::PASS, $vehicle->displayName().' is assigned.'];
    }

    /**
     * OPS §28 Driver, "Valid documents"; BRW-047 "driver compliance";
     * BR-P0-004; CMP §182 (Expiry → Non-Compliant → Dispatch Block).
     *
     * Borrows the `licence` and `documents` checks from the driver eligibility
     * verdict. See the class docblock for why the other three are left behind.
     */
    private function evaluateDriverDocuments(
        ?TripAssignment $assignment,
        TransportTrip $trip,
        int $tenantId,
        bool $isCritical,
        array $policy,
    ): array {
        $driver = $assignment?->driver;

        if ($driver === null) {
            return [
                PretripResult::forFailure($isCritical),
                'No driver is assigned, so their documents cannot be verified.',
            ];
        }

        $verdict = $this->driverEligibility->evaluate($driver, $trip, $tenantId, $policy);

        return $this->fromBorrowedChecks(
            $verdict, ['licence', 'documents'], $driver->displayName(), $isCritical, $tenantId, $policy,
        );
    }

    /**
     * OPS §28 Vehicle, "Compliance valid"; RTM CMP-006 ("Dispatch control");
     * QA-003; FLEET §14.
     */
    private function evaluateVehicleCompliance(
        ?TripAssignment $assignment,
        TransportTrip $trip,
        int $tenantId,
        bool $isCritical,
        array $policy,
    ): array {
        $vehicle = $assignment?->vehicle;

        if ($vehicle === null) {
            return [
                PretripResult::forFailure($isCritical),
                'No vehicle is assigned, so its compliance cannot be verified.',
            ];
        }

        $verdict = $this->vehicleEligibility->evaluate($vehicle, $trip, $tenantId, $policy);

        return $this->fromBorrowedChecks(
            $verdict, ['documents'], $vehicle->displayName(), $isCritical, $tenantId, $policy,
        );
    }

    /**
     * Fold selected eligibility checks into one pre-trip result.
     *
     * A failure among the borrowed checks fails the pre-trip item; the details
     * are joined so BRW-048's "exact reason" survives intact rather than being
     * replaced by a summary. A pass that carries an expiry warning becomes
     * PASS_WARNING, which is UX §36's distinction — a warning is not a block.
     *
     * @param  array<string,mixed>  $verdict
     * @param  array<int,string>    $keys
     * @return array{0:string,1:string}
     */
    private function fromBorrowedChecks(
        array $verdict,
        array $keys,
        string $subject,
        bool $isCritical,
        int $tenantId,
        array $policy,
    ): array {
        $borrowed = array_values(array_filter(
            $verdict['checks'] ?? [],
            fn (array $c) => in_array($c['key'], $keys, true),
        ));

        $failed = array_values(array_filter($borrowed, fn (array $c) => ! $c['passed']));

        if ($failed !== []) {
            return [
                PretripResult::forFailure($isCritical),
                $subject.' — '.implode(' ', array_column($failed, 'detail')),
            ];
        }

        // FLEET §13 / CMP §18: valid today, lapsing inside the tenant's window.
        // Worth saying, not worth blocking.
        $expiring = $verdict['expiring_soon'] ?? [];
        if (! empty($expiring)) {
            return [
                PretripResult::PASS_WARNING,
                $subject.' — valid now, but expiring soon: '.$this->describeExpiring($expiring)
                .'. Renew before this becomes a block.',
            ];
        }

        return [PretripResult::PASS, $subject.' — '.implode(' ', array_column($borrowed, 'detail'))];
    }

    /** @param array<int,mixed> $expiring */
    private function describeExpiring(array $expiring): string
    {
        $labels = array_map(
            fn ($e) => is_array($e) ? (string) ($e['label'] ?? $e['document_type'] ?? 'document') : (string) $e,
            $expiring,
        );

        return implode(', ', $labels);
    }
}
