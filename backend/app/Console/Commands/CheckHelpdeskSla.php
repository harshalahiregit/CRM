<?php

namespace App\Console\Commands;

use App\Mail\Helpdesk\SlaBreachWarningMail;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketPriority;
use App\Models\Helpdesk\TicketStatus;
use App\Models\User;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Helpdesk\SlaService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Alerts on SLA clocks that are about to run out or already have (REQ-16).
 * SlaService::compute() has always been able to say at_risk|breached, but only
 * ever when someone happened to serialize a ticket — nobody was ever told.
 *
 * Re-alerts at most once per hour per ticket (sla_alert_sent_at is the throttle),
 * so a ticket that sits breached for a week nags hourly rather than every pass.
 *
 * Performance: the SLA state is computed, not stored, so it can't be filtered in
 * SQL. Instead the query is narrowed hard BEFORE any PHP runs — per tenant, to
 * non-closed tickets, on a priority that actually has a target, not alerted in
 * the last hour, and old enough that a clock could plausibly be near expiry.
 * compute() only ever runs on that residue, never on the full table.
 */
class CheckHelpdeskSla extends Command
{
    protected $signature = 'helpdesk:check-sla';

    protected $description = 'Warn agents and ticket managers about at-risk / breached SLA clocks';

    /** An alerted ticket goes quiet for this long before it can nag again. */
    private const REALERT_HOURS = 1;

    public function handle(SlaService $sla, NotificationService $notifications, HelpdeskService $helpdesk): int
    {
        $now = now();
        $alerted = 0;

        // Config is per-tenant (targets and status flags are tenant-scoped), so the
        // narrowing has to be too — a global query can't know which statuses close.
        $tenantIds = TicketPriority::query()->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenantId = (int) $tenantId;

            // No HTTP request here, so point the mailer at THIS tenant's
            // Settings → Email SMTP (not .env) before any breach mail goes out.
            app(\App\Services\Mail\TenantMailConfigurator::class)->applyForTenant($tenantId);

            // Priorities that actually carry a target. A ticket on any other
            // priority is untracked — compute() returns ['tracked' => false].
            $tracked = TicketPriority::forTenant($tenantId)
                ->where(fn ($q) => $q->whereNotNull('first_response_hours')->orWhereNotNull('resolution_hours'))
                ->get(['name', 'first_response_hours', 'resolution_hours']);

            if ($tracked->isEmpty()) {
                continue;
            }

            $closedStatuses = TicketStatus::forTenant($tenantId)
                ->where('is_closed_status', true)->pluck('name')->all();

            // The shortest target in the tenant bounds how young a ticket can be and
            // still be at risk: at_risk starts at 80% of the target elapsed, and
            // pause time only ever pushes a due date LATER. So anything created
            // after this cutoff is provably 'ok' and never needs computing.
            $minTargetHours = $tracked
                ->flatMap(fn ($p) => array_filter([$p->first_response_hours, $p->resolution_hours], fn ($h) => $h !== null))
                ->min();
            $cutoff = $now->copy()->subMinutes((int) floor($minTargetHours * 60 * 0.8));

            Ticket::query()
                ->where('tenant_id', $tenantId)
                ->whereNotIn('status', $closedStatuses)
                ->whereIn('priority', $tracked->pluck('name')->all())
                ->where('created_at', '<=', $cutoff)
                // Both clocks already stopped → nothing left to warn about.
                ->where(fn ($q) => $q->whereNull('first_responded_at')->orWhereNull('resolved_at'))
                ->where(fn ($q) => $q->whereNull('sla_alert_sent_at')
                    ->orWhere('sla_alert_sent_at', '<=', $now->copy()->subHours(self::REALERT_HOURS)))
                ->chunkById(100, function (Collection $tickets) use ($sla, $notifications, $helpdesk, &$alerted) {
                    foreach ($tickets as $ticket) {
                        $breaches = $this->breaches($ticket, $sla);
                        if (! $breaches) {
                            continue;
                        }

                        $this->dispatchAlert($ticket, $breaches, $notifications, $helpdesk);
                        $ticket->forceFill(['sla_alert_sent_at' => now()])->save();
                        $alerted++;
                    }
                });
        }

        $this->info("Helpdesk: {$alerted} ticket(s) alerted on SLA.");

        return self::SUCCESS;
    }

    /**
     * The clocks on this ticket worth shouting about.
     *
     * Only PENDING clocks count. compute() reports a stopped clock as met|breached
     * for history — but a first response that landed late is a fact, not an action,
     * and alerting on it would nag hourly until the ticket closed.
     *
     * @return array<int,array{clock:string,state:string,due:string}>
     */
    private function breaches(Ticket $ticket, SlaService $sla): array
    {
        // Compute explicitly rather than reading $ticket->sla: the $appends hook is
        // being removed, and a scheduled job shouldn't depend on serialization.
        $snapshot = $sla->compute($ticket);

        if (! ($snapshot['tracked'] ?? false) || ($snapshot['paused'] ?? false)) {
            return [];
        }

        $out = [];
        // compute() keys each clock by name, each holding ['due' => iso, 'state' => s].
        $pending = [
            'response'   => $ticket->first_responded_at === null,
            'resolution' => $ticket->resolved_at === null,
        ];

        foreach ($pending as $clock => $isPending) {
            if (! $isPending || ! isset($snapshot[$clock])) {
                continue;
            }
            $state = $snapshot[$clock]['state'] ?? null;
            if (in_array($state, ['at_risk', 'breached'], true)) {
                $out[] = ['clock' => $clock, 'state' => $state, 'due' => $snapshot[$clock]['due']];
            }
        }

        return $out;
    }

    /**
     * Bell + email to the assigned agent and everyone who manages the ticket.
     * Named dispatchAlert, not alert — Command::alert() already exists and is
     * public, so a private alert() here is a fatal signature clash.
     */
    private function dispatchAlert(Ticket $ticket, array $breaches, NotificationService $notifications, HelpdeskService $helpdesk): void
    {
        $breached = collect($breaches)->contains(fn ($b) => $b['state'] === 'breached');
        $label = $breached ? 'SLA breached' : 'SLA at risk';
        $clocks = collect($breaches)->map(fn ($b) => $b['clock'] === 'response' ? 'first response' : 'resolution')->join(' and ');

        // ticketManagerIds() is already public on HelpdeskService and resolves the
        // exact rule set we need (tenant managers + department managers, falling
        // back to admins, filtered to active internal users). Reusing it beats
        // re-deriving those rules here and drifting out of sync.
        $recipients = collect($helpdesk->ticketManagerIds($ticket))
            ->push($ticket->assigned_to)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $users = User::whereIn('id', $recipients)->get(['id', 'name', 'email']);

        foreach ($users as $user) {
            $notifications->notify(
                userId: (int) $user->id,
                tenantId: (int) $ticket->tenant_id,
                type: 'ticket.sla_alert',
                title: "{$label}: ticket #{$ticket->id}",
                message: "The {$clocks} target on \"{$ticket->subject}\" needs attention.",
                link: "/app/helpdesk/tickets/{$ticket->id}",
                // Nobody "did" this — the clock did. No actor to suppress against.
                actorId: null,
            );

            if (empty($user->email)) {
                continue;
            }

            try {
                Mail::to($user->email)->send(
                    new SlaBreachWarningMail($ticket, $user->name ?: 'there', $breaches)
                );
            } catch (\Throwable $e) {
                Log::warning("Helpdesk mail failed (SLA alert for ticket #{$ticket->id}): {$e->getMessage()}");
            }
        }
    }
}
