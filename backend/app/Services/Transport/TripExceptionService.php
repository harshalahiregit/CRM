<?php

namespace App\Services\Transport;

use App\Events\Transport\TripExceptionRaised;
use App\Services\Transport\TripEventRecorder;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TripException;
use App\Models\User;
use App\Support\Transport\ExceptionCategory;
use App\Support\Transport\ExceptionScope;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionStatus;
use App\Support\Transport\TransportDocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The exception register — SNG-TRN-013's lifecycle, finally.
 *
 * ── THE TWO EDGES, AND ONLY THE TWO ─────────────────────────────────────
 *   STT-015  open → acknowledged   "Assign owner"      side effect: SLA starts
 *   STT-016  acknowledged → resolved "Resolve exception" side effect: SLA stops
 *
 * Step 9's seven statuses are the vocabulary; Step 11 LOCKS these two edges and
 * no others; everything between stays declared and unreachable. That is the
 * standing rule in TEAM-CONTRACTS, and applying it is what unblocked D-29 —
 * see the ruling there for why Step 11 contradicting itself resolves without
 * either half of Step 11 losing.
 *
 * ── RAISING IS MANUAL, AND THAT IS THE HONEST STATE ─────────────────────
 * ExceptionScope::AUTOMATIC_SOURCES lists every rule that would raise an
 * exception without a person and what each is waiting for. All three are
 * unreachable: BR-P0-008 and BR-P0-009 need the cost model (SNG-TRN-012),
 * BR-P0-010 needs a GPS event and there is no GPS (SNG-TRN-020). So this ships
 * with manual raising only — which is exactly what API-007 ("Raise exception")
 * is, and not a reduced version of something else.
 *
 * ── BR-P0-011 — RESOLUTION EVIDENCE IS MANDATORY ────────────────────────
 * "No critical exception can be marked resolved without resolution evidence."
 * The owner's Q4 ruling made that evidence note-only: BR-P0-011 asks for
 * "note/photo/document", a note is buildable today, and photo and document need
 * file upload which Transport does not have (D-22).
 *
 * The rule names CRITICAL. A note is required on EVERY resolution here, and
 * that is deliberate: an exception resolved with no account of how is a row
 * that tells the next reader nothing, whatever its severity. Being stricter
 * than a Hard rule is safe; being looser is not.
 *
 * ── AND THE WAIVER IS NOT BUILT, WHICH IS NOT THE SAME AS NOT EXISTING ──
 * BR-P0-011's override is "Owner waiver" and FRS TRP-P0-012 names `waived` as
 * an outcome. Neither is built (D-30), and `waived` is out of the vocabulary
 * because Step 9 does not contain it. Every refusal below therefore says the
 * waiver is NOT BUILT YET — never that no waiver exists. A user told "this
 * cannot be waived" when their own rule book says it can is being misled by our
 * software about their own business.
 */
class TripExceptionService
{
    public function __construct(
        private TransportPolicyService $policy,
    ) {
    }

    /**
     * API-007 — raise an exception. The one act that creates a row.
     *
     * @param  array{category:string,severity?:string,cause?:string,trip_id?:int|null,vehicle_id?:int|null,driver_id?:int|null,owner_id?:int|null}  $data
     */
    public function raise(array $data, int $tenantId, ?User $actor = null): TripException
    {
        $category = $this->assertCategory($data['category'] ?? null);
        $severity = $this->assertSeverity($data['severity'] ?? ExceptionSeverity::MEDIUM);

        $trip = null;
        if (! empty($data['trip_id'])) {
            // 404, never 403 — a 422 on another tenant's id confirms it exists.
            $trip = TransportTrip::forTenant($tenantId)->find($data['trip_id']);
            if (! $trip) {
                throw new ResourceNotFoundException('Trip');
            }
        }

        $cause = trim((string) ($data['cause'] ?? ''));
        if ($cause === '') {
            throw new BusinessException(
                'Say what went wrong. An exception with no cause cannot be acted on by whoever picks it up.',
                422
            );
        }

        $slaMinutes = $this->slaMinutesFor($tenantId, $severity);
        $raisedAt   = now();

        $exception = DB::transaction(function () use ($data, $category, $severity, $cause, $trip, $tenantId, $actor, $slaMinutes, $raisedAt) {
            /** @var TripException $e */
            $e = TripException::create([
                'tenant_id'        => $tenantId,
                'exception_number' => TransportDocumentNumber::allocate(
                    'transport_exception',
                    $tenantId,
                    fn () => TripException::nextLocalNumber($tenantId),
                ),
                'trip_id'     => $trip?->id,
                // Carried from the trip when it has them, so the register can be
                // read by vehicle or driver without a join through trips.
                'vehicle_id'  => $data['vehicle_id'] ?? $trip?->vehicle_id,
                'driver_id'   => $data['driver_id'] ?? $trip?->driver_id,
                'category'    => $category,
                'severity'    => $severity,
                'source'      => ExceptionScope::SOURCE_MANUAL,
                'cause'       => $cause,
                // Q6 — manual owner assignment only. OPS §90 wants owners
                // assigned automatically from category, branch, role and an
                // escalation matrix; branch and the matrix have no data model
                // anywhere (D-31), so an owner is a person somebody names.
                'owner_id'    => $data['owner_id'] ?? null,
                'raised_by'   => $actor?->id,
                'raised_at'   => $raisedAt,
                'sla_minutes' => $slaMinutes ?: null,
                'due_at'      => $slaMinutes ? $raisedAt->copy()->addMinutes($slaMinutes) : null,
                'created_by'  => $actor?->id,
                'updated_by'  => $actor?->id,
            ]);

            // status is not fillable — this is the only place it is set, and it
            // is the initial state rather than a transition.
            $e->forceFill(['status' => ExceptionStatus::OPEN])->save();

            $e->audit('transport.exception.raised', $actor, new: [
                'exception_number' => $e->exception_number,
                'category'         => $category,
                'severity'         => $severity,
                'trip_id'          => $trip?->id,
            ], context: [
                'rule'      => ExceptionScope::TICKET,
                'registry'  => 'API-007; EVT-008; DB-010',
                'source'    => ExceptionScope::SOURCE_MANUAL,
                'sla'       => $slaMinutes ? $slaMinutes.' minutes (wall clock — Q5)' : 'none configured for this severity',
                // OPS §87 wants these and nothing can compute them (D-32). Said
                // rather than defaulted to zero.
                'impact_not_computed' => ['financial', 'customer'],
            ]);

            return $e->fresh();
        });

        TripExceptionRaised::dispatch($exception);

        app(TripEventRecorder::class)->record(
            'exception.raised', tenantId: $tenantId, tripId: $trip?->id, actor: $actor,
            summary: ExceptionSeverity::label($severity).' exception — '.ExceptionCategory::label($category),
            detail: ['exception_number' => $exception->exception_number, 'cause' => $cause],
        );

        Log::channel('transport')->info('Transport exception raised', [
            'exception_id' => $exception->id, 'tenant_id' => $tenantId,
            'trip_id' => $trip?->id, 'severity' => $severity, 'category' => $category,
            'user_id' => $actor?->id, 'source' => ExceptionScope::SOURCE_MANUAL,
        ]);

        return $exception;
    }

    /**
     * STT-015 — `open → acknowledged`. "Assign owner", and the SLA timer starts.
     *
     * The owner is the precondition, not a nicety: OPS §90's whole point is that
     * an exception without a named owner is an exception nobody is working on.
     */
    public function acknowledge(TripException $e, ?int $ownerId, int $tenantId, ?User $actor = null): TripException
    {
        $this->assertTenant($e, $tenantId);

        $from = $e->status;
        $to   = ExceptionStatus::ACKNOWLEDGED;

        if (! ExceptionStatus::canTransition($from, $to)) {
            throw new BusinessException(
                $from === ExceptionStatus::ACKNOWLEDGED
                    ? 'This exception has already been acknowledged.'
                    : 'Only an open exception can be acknowledged. This one is '.$e->statusLabel().'.',
                422
            );
        }

        $owner = $ownerId ?? $e->owner_id ?? $actor?->id;
        if (! $owner) {
            throw new BusinessException(
                'Name who owns this exception. Acknowledging it starts its clock, and a clock with nobody attached is not a plan.',
                422
            );
        }

        $acknowledged = DB::transaction(function () use ($e, $from, $to, $owner, $actor) {
            $e->forceFill([
                'status'          => $to,
                'owner_id'        => $owner,
                'acknowledged_by' => $actor?->id,
                'acknowledged_at' => now(),
                'updated_by'      => $actor?->id,
            ])->save();

            $e->auditTransition('transport.exception.status_changed', $from, $to, $actor, [
                'registry'   => ExceptionScope::STT_015,
                'transition' => $from.'->'.$to,
                'owner_id'   => $owner,
                'sla'        => $e->due_at ? 'due '.$e->due_at->toIso8601String() : 'no SLA configured',
                'sources'    => 'Step 11 STT-015; OPS §90',
            ]);

            return $e->fresh();
        });

        // CTD §31. `raise` and `resolve` both recorded; this one did not, so a
        // timeline showed an exception appearing and disappearing with nothing
        // in between — and the SLA clock, which starts HERE, started invisibly.
        // D-115.
        app(TripEventRecorder::class)->record(
            'exception.acknowledged', tenantId: $tenantId, tripId: $acknowledged->trip_id, actor: $actor,
            detail: [
                'exception_number' => $acknowledged->exception_number,
                'owner_id'         => $owner,
                'due_at'           => $acknowledged->due_at?->toIso8601String(),
            ],
        );

        return $acknowledged;
    }

    /**
     * STT-016 — `acknowledged → resolved`. "Resolution evidence", and the clock stops.
     *
     * BR-P0-011 is a HARD rule and the note is its evidence (Q4). It is required
     * for every severity, not only critical — see the class docblock.
     */
    public function resolve(TripException $e, ?string $note, int $tenantId, ?User $actor = null): TripException
    {
        $this->assertTenant($e, $tenantId);

        $from = $e->status;
        $to   = ExceptionStatus::RESOLVED;

        if (! ExceptionStatus::canTransition($from, $to)) {
            throw new BusinessException(
                $from === ExceptionStatus::OPEN
                    // The likeliest real mistake, and worth naming rather than
                    // answering with "wrong state".
                    ? 'Acknowledge this exception first — somebody has to own it before it can be resolved.'
                    : ($from === ExceptionStatus::RESOLVED
                        ? 'This exception is already resolved.'
                        : 'Only an acknowledged exception can be resolved. This one is '.$e->statusLabel().'.'),
                422
            );
        }

        $note = trim((string) $note);
        if ($note === '') {
            throw new BusinessException(
                'Say how it was resolved. '.ExceptionScope::BR_011_EVIDENCE.' '.ExceptionScope::WAIVER_MESSAGE,
                422
            );
        }

        $resolved = DB::transaction(function () use ($e, $from, $to, $note, $actor) {
            $e->forceFill([
                'status'          => $to,
                'resolution_note' => $note,
                'resolved_by'     => $actor?->id,
                'resolved_at'     => now(),
                'updated_by'      => $actor?->id,
            ])->save();

            $e->auditTransition('transport.exception.status_changed', $from, $to, $actor, [
                'rule'        => 'BR-P0-011',
                'registry'    => ExceptionScope::STT_016,
                'transition'  => $from.'->'.$to,
                'evidence'    => 'note',
                // Q4, on the audit row as well as in the code: what BR-P0-011
                // asked for and what was actually taken.
                'evidence_deferred' => ExceptionScope::RULINGS['Q4'],
                'sla_outcome' => $e->due_at && $e->resolved_at?->greaterThan($e->due_at) ? 'breached' : 'within',
            ]);

            return $e->fresh();
        });

        app(TripEventRecorder::class)->record(
            'exception.resolved', tenantId: $tenantId, tripId: $resolved->trip_id, actor: $actor,
            detail: ['exception_number' => $resolved->exception_number],
        );

        Log::channel('transport')->info('Transport exception resolved', [
            'exception_id' => $resolved->id, 'tenant_id' => $tenantId,
            'user_id' => $actor?->id, 'severity' => $resolved->severity,
        ]);

        return $resolved;
    }

    /* ── Reads ──────────────────────────────────────────────────────── */

    /** @return \Illuminate\Database\Eloquent\Collection<int,TripException> */
    public function forTrip(int $tripId, int $tenantId)
    {
        return TripException::forTenant($tenantId)->forTrip($tripId)
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', [ExceptionStatus::OPEN, ExceptionStatus::ACKNOWLEDGED])
            ->orderByDesc('raised_at')
            ->get();
    }

    /**
     * The trip's exception summary, in the shape a screen needs.
     *
     * `critical_open` exists because it is the one number that changes what
     * somebody does next, and TRP-P0-014's closure control asks for exactly it.
     */
    public function summaryForTrip(int $tripId, int $tenantId): array
    {
        $rows = $this->forTrip($tripId, $tenantId);
        $open = $rows->filter->isOpen();

        return [
            'total'         => $rows->count(),
            'open'          => $open->count(),
            'critical_open' => $open->where('severity', ExceptionSeverity::CRITICAL)->count(),
            'overdue'       => $open->filter(fn (TripException $e) => $e->slaState() === \App\Support\Transport\ExceptionSlaState::OVERDUE)->count(),
        ];
    }

    /* ── Guards ─────────────────────────────────────────────────────── */

    private function assertCategory(?string $category): string
    {
        $c = strtolower(trim((string) $category));

        if (! ExceptionCategory::isValid($c)) {
            throw new BusinessException(
                'Choose one of the eight exception categories: '
                .implode(', ', ExceptionCategory::ALL).'. '
                .'These are OPS §88\'s list and the only one any document defines.',
                422
            );
        }

        return $c;
    }

    private function assertSeverity(?string $severity): string
    {
        $s = strtolower(trim((string) $severity));

        if (! ExceptionSeverity::isValid($s)) {
            throw new BusinessException(
                'Severity must be one of: '.implode(', ', ExceptionSeverity::ALL).' (CTR-011).',
                422
            );
        }

        return $s;
    }

    /** Q5 — wall clock, per severity, from the workspace's own policy. */
    private function slaMinutesFor(int $tenantId, string $severity): int
    {
        return max(0, $this->policy->int($tenantId, 'exception.sla_minutes.'.$severity));
    }

    private function assertTenant(TripException $e, int $tenantId): void
    {
        if ((int) $e->tenant_id !== $tenantId) {
            throw new ResourceNotFoundException('Exception');
        }
    }
}
