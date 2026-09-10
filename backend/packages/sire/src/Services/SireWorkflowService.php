<?php

namespace Sire\Services;

use Sire\Exceptions\SireException;
use Sire\Models\Report;
use Sire\Models\ReportApproval;
use Sire\Models\WorkCycle;
use Sire\Dto\SireUserIdentity;
use Sire\Services\SireNotifier;
use Sire\Contracts\SireAuditProvider;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\SireEvents;
use Sire\Support\SireResolution;
use Sire\Support\SireStatus;
use Sire\Support\SireWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * SIRE — the engineering state machine. The ONLY class permitted to write
 * `status`, `track` or `resolution` on a report.
 *
 * Controllers call apply(). They do not check legality, they do not stamp
 * timestamps, and they do not dispatch notifications — doing any of that in a
 * controller is how a workflow ends up with two sets of rules that disagree.
 */
class SireWorkflowService
{
    /** transition action => notification event key. Absent = no notification. */
    private const NOTIFIES = [
        'assign'              => SireEvents::REPORT_ASSIGNED,   // upgraded to REASSIGNED below
        'start_development'   => SireEvents::DEVELOPMENT_STARTED,
        'mark_ready_for_qa'   => SireEvents::READY_FOR_QA,
        'start_qa'            => SireEvents::QA_STARTED,
        'qa_pass'             => SireEvents::QA_PASSED,
        'qa_fail'             => SireEvents::QA_FAILED,
        'release'             => SireEvents::RELEASED,
        'validate_production' => SireEvents::PRODUCTION_VALIDATED,
        'close'               => SireEvents::REPORT_CLOSED,
        'reopen'              => SireEvents::REPORT_REOPENED,
        'hold'                => SireEvents::REPORT_ON_HOLD,
        // wont_fix / rejected / duplicate / cannot_reproduce also end an issue.
        'wont_fix'            => SireEvents::REPORT_CLOSED,
        'reject'              => SireEvents::REPORT_CLOSED,
        'mark_duplicate'      => SireEvents::REPORT_CLOSED,
        'cannot_reproduce'    => SireEvents::REPORT_CLOSED,
    ];

    public function __construct(
        private readonly SireAccessService $access,
        private readonly SireNotifier $notifier,
        private readonly SireSettingsProvider $settings,
        private readonly SireAuditProvider $audit,
    ) {
    }

    /**
     * What this user may do to this report, right now. Drives the transition bar:
     * the client renders what this returns and never recomputes the rules.
     */
    public function availableFor(Report $report, SireUserIdentity $user): array
    {
        $out = [];

        foreach (SireWorkflow::actionsFrom($report->status) as $action) {
            $definition = SireWorkflow::transition($action);

            if (! $this->access->can($user, $definition['capability'], $report)) {
                continue;
            }
            if (! $this->passesGuard($definition['guard'] ?? null, $report, $user)) {
                continue;
            }

            $out[] = [
                'action'   => $action,
                'label'    => $definition['label'],
                'to'       => $this->resolveTarget($definition, $report),
                'requires' => $this->unmetRequirements($definition, $report, []),
                'optional' => $definition['optional'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Non-transition actions this user may take right now.
     *
     * The developer and QA panels enable their fields from this. It is a separate
     * list from availableFor() on purpose: one moves the issue, the other records
     * work against it, and conflating them in the payload would make the UI guess.
     */
    public function availableActionsFor(Report $report, SireUserIdentity $user): array
    {
        $out = [];

        foreach (SireWorkflow::ACTIONS as $action => $definition) {
            if (! in_array($report->status, $definition['states'], true)) {
                continue;
            }
            if (! $this->access->can($user, $definition['capability'], $report)) {
                continue;
            }
            if (! $this->passesGuard($definition['guard'] ?? null, $report, $user)) {
                continue;
            }

            $out[] = ['action' => $action, 'label' => $definition['label']];
        }

        return $out;
    }

    /**
     * The last main-path state this issue actually reached, for the progress rail.
     * Read off the audit trail rather than stored: a state the issue passed through
     * is history, and history already lives in audit_logs.
     */
    public function lastMainPathStatus(Report $report): ?string
    {
        $main = [
            SireStatus::NEW, SireStatus::TRIAGED, SireStatus::ASSIGNED, SireStatus::IN_DEVELOPMENT,
            SireStatus::READY_FOR_QA, SireStatus::QA_IN_PROGRESS, SireStatus::QA_PASSED,
            SireStatus::READY_FOR_RELEASE, SireStatus::RELEASED, SireStatus::PRODUCTION_VALIDATED,
            SireStatus::CLOSED,
        ];

        if (in_array($report->status, $main, true)) {
            return $report->status;
        }

        // Where did this issue come from before it went sideways? The audit
        // trail is the only place that knows, so REOPENED and ON_HOLD can send
        // it back to the state it actually left rather than to a guess.
        $reached = collect($this->audit->for(Report::class, (int) $report->id, (int) $report->tenant_id, 50))
            ->map(fn ($event) => $event->metadata['to'] ?? null)
            ->filter(fn ($state) => in_array($state, $main, true));

        return $reached->first();
    }

    /**
     * Apply a transition. Everything happens in one transaction: the status
     * change, the field updates, the work-cycle bookkeeping and the audit entry.
     * A failed notification must not roll back a completed transition, so
     * notifications are dispatched after commit.
     */
    public function apply(Report $report, string $action, SireUserIdentity $user, array $payload = []): Report
    {
        $definition = SireWorkflow::transition($action);

        if ($definition === null) {
            throw new SireException("Unknown workflow action '{$action}'.");
        }

        if (! SireWorkflow::allows($report->status, $action)) {
            throw new SireException(sprintf(
                'Cannot %s an issue that is %s.',
                strtolower($definition['label']),
                SireStatus::label($report->status),
            ));
        }

        $this->access->assert($user, $definition['capability'], $report);

        if (! $this->passesGuard($definition['guard'] ?? null, $report, $user)) {
            throw new SireException('This issue is assigned to someone else. Ask a lead to reassign it first.');
        }

        $missing = $this->unmetRequirements($definition, $report, $payload);
        if ($missing !== []) {
            throw new SireException(
                'Before this step, please provide: '.implode(', ', array_map(
                    static fn (string $f) => str_replace('_', ' ', $f),
                    $missing,
                )).'.',
            );
        }

        $from = $report->status;
        $to   = $this->resolveTarget($definition, $report);

        // Captured BEFORE applyPayload overwrites it: "assigned" and "reassigned"
        // are different events to the person losing the issue.
        $previousAssigneeId = $report->assignee_id ? (int) $report->assignee_id : null;

        $dispatch = DB::transaction(function () use ($report, $action, $definition, $user, $payload, $from, $to) {
            $this->applyPayload($report, $definition, $payload);
            $this->stampTransition($report, $action, $to, $user, $payload);
            $this->maintainSlaPause($report, $from, $to);

            $report->status = $to;
            $report->save();

            $this->closeAndOpenCycles($report, $action, $user, $payload);
            $this->maintainApprovals($report, $action, $user, $payload);

            // System event. Written through the shared audit trail — SIRE adds no
            // history table of its own, and this row is never editable.
            $report->recordAudit(
                sprintf('%s → %s', SireStatus::label($from), SireStatus::label($to)),
                $user,
                $payload['comment'] ?? null,
                ['action' => $action, 'from' => $from, 'to' => $to, 'system' => true],
            );

            $events = [[$action, $to]];

            // qa_pass rests at qa_passed only if the tenant turned auto-advance
            // off; by default the issue moves straight to ready_for_release.
            $auto = $definition['auto_advance'] ?? null;
            if ($auto && $this->settings->get($report->tenant_id, 'sire.auto_ready_for_release', true)) {
                $report->status = $auto;
                $report->save();
                $report->recordAudit(
                    sprintf('%s → %s', SireStatus::label($to), SireStatus::label($auto)),
                    $user,
                    null,
                    ['action' => 'auto_advance', 'from' => $to, 'to' => $auto, 'system' => true, 'automatic' => true],
                );
                $events[] = ['mark_ready_for_release', $auto];
            }

            return $events;
        });

        $fresh = $report->fresh();

        foreach ($dispatch as [$dispatchedAction, $_]) {
            $this->notify($fresh, $dispatchedAction, $user, null, $previousAssigneeId);
        }

        return $fresh;
    }

    /** A non-transition action: records work without moving the issue. */
    public function recordAction(Report $report, string $action, SireUserIdentity $user, array $payload = []): Report
    {
        $definition = SireWorkflow::ACTIONS[$action] ?? null;

        if ($definition === null) {
            throw new SireException("Unknown action '{$action}'.");
        }
        if (! in_array($report->status, $definition['states'], true)) {
            throw new SireException(sprintf(
                '"%s" is not available while an issue is %s.',
                $definition['label'],
                SireStatus::label($report->status),
            ));
        }

        $this->access->assert($user, $definition['capability'], $report);

        if (! $this->passesGuard($definition['guard'] ?? null, $report, $user)) {
            throw new SireException('This issue is assigned to someone else.');
        }

        DB::transaction(function () use ($report, $action, $user, $payload) {
            match ($action) {
                'accept_assignment'       => $report->assignment_accepted_at = now(),
                'add_investigation_notes' => $report->investigation_notes = $payload['investigation_notes'] ?? $report->investigation_notes,
                'add_fix_summary'         => $report->fix_summary = $payload['fix_summary'] ?? $report->fix_summary,
                'submit_dev_testing'      => $report->dev_test_notes = $payload['dev_test_notes'] ?? $report->dev_test_notes,
                'add_qa_notes'            => $report->qa_notes = $payload['qa_notes'] ?? $report->qa_notes,
                default                   => null,
            };
            $report->save();

            $report->recordAudit(
                SireWorkflow::ACTIONS[$action]['label'],
                $user,
                null,
                ['action' => $action, 'system' => true],
            );
        });

        if ($action === 'accept_assignment') {
            $this->notify($report->fresh(), 'accept_assignment', $user, SireEvents::ASSIGNMENT_ACCEPTED);
        }

        return $report->fresh();
    }

    // ------------------------------------------------------------------ guards

    private function passesGuard(?string $guard, Report $report, SireUserIdentity $user): bool
    {
        if ($guard === null) {
            return true;
        }

        return match ($guard) {
            'actor_is_assignee'         => (int) $report->assignee_id === (int) $user->id,
            'actor_is_assignee_or_lead' => (int) $report->assignee_id === (int) $user->id
                                            || $this->access->can($user, 'sire.report.assign', $report),
            default                     => true,
        };
    }

    /** Required fields that are still empty, counting the incoming payload. */
    private function unmetRequirements(array $definition, Report $report, array $payload): array
    {
        $missing = [];

        foreach ($definition['requires'] ?? [] as $field) {
            $incoming = $payload[$field] ?? null;
            $existing = $report->{$field} ?? null;

            if (blank($incoming) && blank($existing)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function resolveTarget(array $definition, Report $report): string
    {
        // resume returns to wherever the hold interrupted, not to a fixed state.
        if ($definition['to'] === '@held_from_status') {
            return $report->held_from_status ?: SireStatus::TRIAGED;
        }

        return $definition['to'];
    }

    // ------------------------------------------------------------- side effects

    private function applyPayload(Report $report, array $definition, array $payload): void
    {
        $writable = array_merge($definition['requires'] ?? [], [
            'assignee_id', 'qa_assignee_id', 'severity_id', 'category_id', 'priority',
            'fix_summary', 'release_ref', 'qa_notes', 'hold_reason',
            'duplicate_of_id', 'resolution_note', 'investigation_notes', 'dev_test_notes',
        ]);

        foreach (array_unique($writable) as $field) {
            if (array_key_exists($field, $payload) && filled($payload[$field])) {
                $report->{$field} = $payload[$field];
            }
        }

        foreach ($definition['clears'] ?? [] as $field) {
            $report->{$field} = null;
        }
    }

    private function stampTransition(Report $report, string $action, string $to, SireUserIdentity $user, array $payload): void
    {
        $now = now();

        // A plain switch, not a match(): several of these set more than one field,
        // and an array-literal match arm reads like a mistake even when it works.
        switch ($action) {
            case 'triage':
                $report->triaged_at = $now;
                $report->triaged_by = $user->id;
                break;

            case 'assign':
                $report->assigned_at = $now;
                $report->assignment_accepted_at = null; // a new assignee has not accepted yet
                break;

            case 'start_development':
                $report->development_started_at ??= $now; // first pickup only
                break;

            case 'mark_ready_for_qa':
                $report->ready_for_qa_at = $now;
                break;

            case 'start_qa':
                $report->qa_started_at = $now;
                $report->qa_assignee_id ??= $user->id;
                break;

            case 'release':
                $report->released_at = $now;
                $report->released_by = $user->id;
                break;

            case 'validate_production':
                $report->production_validated_at = $now;
                $report->validated_by = $user->id;
                break;

            case 'close':
                $report->closed_at = $now;
                $report->closed_by = $user->id;
                break;

            case 'hold':
                // Runs BEFORE $report->status is set to on_hold, so this captures
                // where the issue actually was. resume reads it back.
                $report->held_from_status = $report->status;
                $report->held_at = $now;
                break;

            case 'resume':
                $report->held_from_status = null;
                $report->held_at = null;
                $report->hold_reason = null;
                break;

            case 'reopen':
                $report->reopened_at = $now;
                $report->reopened_by = $user->id;
                $report->reopen_count = (int) $report->reopen_count + 1;
                $report->resolution = null;
                $report->closed_at = null;
                $report->closed_by = null;
                // A reopened issue gets fresh clocks. Carrying the old ones over
                // would mark it breached the instant it reopened, which tells
                // nobody anything.
                $report->sla_started_at = $now;
                $report->acknowledged_at = null;
                $report->sla_paused_minutes = 0;
                $report->sla_paused_since = null;
                $this->notifier->resetSlaNotices($report);
                break;
        }

        // The acknowledgement clock stops the moment the team looks at it.
        if (in_array($action, ['triage', 'assign'], true) && $report->acknowledged_at === null) {
            $report->acknowledged_at = $now;
        }

        // EVERY terminal status stops the resolve clock, not only 'close'. Without
        // this, an issue closed as wont_fix would accrue elapsed time forever.
        if (SireStatus::isTerminal($to) && $report->closed_at === null) {
            $report->closed_at = $now;
            $report->closed_by = $user->id;
        }

        // A terminal status carries the reason it stopped, so "closed" can be
        // counted without asking why and "closed as duplicate" when you do.
        if (isset(SireResolution::FOR_STATUS[$to])) {
            $report->resolution = SireResolution::FOR_STATUS[$to];
        }
    }

    /**
     * Maintain the paused-minutes accumulator.
     *
     * Entering a paused status records when the pause began; leaving one folds the
     * elapsed interval into the total and clears the marker. Storing this is what
     * lets SireSlaService answer in O(1) instead of walking the audit trail for
     * every row of every dashboard tile.
     */
    private function maintainSlaPause(Report $report, string $from, string $to): void
    {
        $pauseStates = (array) $this->settings->get(
            (int) $report->tenant_id,
            'sire.sla.pause_states',
            SireStatus::SLA_PAUSED,
        );

        $wasPaused = in_array($from, $pauseStates, true);
        $isPaused = in_array($to, $pauseStates, true);

        if (! $wasPaused && $isPaused) {
            $report->sla_paused_since = now();

            return;
        }

        if ($wasPaused && ! $isPaused && $report->sla_paused_since) {
            $report->sla_paused_minutes = (int) $report->sla_paused_minutes
                + max(0, $report->sla_paused_since->diffInMinutes(now(), false));
            $report->sla_paused_since = null;
        }
    }

    /**
     * One development cycle per pickup, one QA cycle per run. This is what makes
     * a dev → QA → fail → dev → QA → pass round trip legible after the fact:
     * report columns hold the CURRENT values, cycles hold what each round said.
     */
    private function closeAndOpenCycles(Report $report, string $action, SireUserIdentity $user, array $payload): void
    {
        match ($action) {
            'start_development' => WorkCycle::open($report, WorkCycle::PHASE_DEVELOPMENT, $user),
            'mark_ready_for_qa' => WorkCycle::close($report, WorkCycle::PHASE_DEVELOPMENT, 'submitted', $report->fix_summary),
            'start_qa'          => WorkCycle::open($report, WorkCycle::PHASE_QA, $user),
            'qa_pass'           => WorkCycle::close($report, WorkCycle::PHASE_QA, 'passed', $payload['qa_notes'] ?? $report->qa_notes),
            'qa_fail'           => WorkCycle::close($report, WorkCycle::PHASE_QA, 'failed', $payload['qa_notes'] ?? $report->qa_notes),
            'pull_back_to_development' => WorkCycle::open($report, WorkCycle::PHASE_DEVELOPMENT, $user),
            'unassign'          => WorkCycle::abandon($report),
            default             => null,
        };
    }

    /**
     * Keep the approval register in step with the change track.
     *
     * `request_change_approval` declares `raises_approval` in the workflow
     * definition; this is what makes that declaration real. Without it the change
     * track would transition through APPROVAL with no record of who approved what
     * — the one thing an approval workflow exists to prevent.
     *
     * Uses the purchase/tpv register shape (D6). No sixth approval engine.
     */
    private function maintainApprovals(Report $report, string $action, SireUserIdentity $user, array $payload): void
    {
        $definition = SireWorkflow::transition($action);
        $pending = fn () => ReportApproval::query()
            ->forTenant($report->tenant_id)
            ->where('subject_type', ReportApproval::SUBJECT_REPORT)
            ->where('subject_id', $report->id)
            ->pending();

        if (! empty($definition['raises_approval'])) {
            // One pending request per subject and type; asking twice supersedes
            // rather than stacking, so "which approval let this through" has one
            // answer.
            $pending()->where('approval_type', $definition['raises_approval'])
                ->update(['status' => ReportApproval::CANCELLED, 'decided_at' => now()]);

            ReportApproval::create([
                'tenant_id'     => $report->tenant_id,   // explicit, never ambient
                'approval_type' => $definition['raises_approval'],
                'subject_type'  => ReportApproval::SUBJECT_REPORT,
                'subject_id'    => $report->id,
                'status'        => ReportApproval::PENDING,
                'requested_by'  => $user->id,
                'requested_at'  => now(),
            ]);

            return;
        }

        $decision = match ($action) {
            'approve_change' => ReportApproval::APPROVED,
            'reject_change'  => ReportApproval::REJECTED,
            default          => null,
        };

        if ($decision !== null) {
            $pending()->update([
                'status'     => $decision,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'remarks'    => $payload['resolution_note'] ?? $payload['comment'] ?? null,
            ]);
        }
    }

    /**
     * Notifications go through the EXISTING engine. SIRE registers its event keys
     * in ModuleEventCatalog and calls dispatch(); templates, channel rules,
     * recipients, dedupe and escalation all come from there. No SIRE mail, no
     * SIRE notification table, no second architecture.
     */
    private function notify(
        Report $report,
        string $action,
        SireUserIdentity $actor,
        ?string $override = null,
        ?int $previousAssigneeId = null,
    ): void {
        $event = $override ?? (self::NOTIFIES[$action] ?? null);

        if ($event === null) {
            return;
        }

        $extra = [];

        // Handing an issue from one developer to another is a different event from
        // giving it to its first: the person losing it needs to know too.
        if ($action === 'assign'
            && $previousAssigneeId !== null
            && $previousAssigneeId !== (int) $report->assignee_id) {
            $event = SireEvents::REPORT_REASSIGNED;
            $extra['previous_assignee_id'] = $previousAssigneeId;
        }

        $this->notifier->send($report, $event, $actor, $extra);
    }
}
