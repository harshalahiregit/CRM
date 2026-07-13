<?php

namespace App\Services\Helpdesk;

use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketPriority;
use App\Models\Helpdesk\TicketStatus;
use Carbon\Carbon;

/**
 * Minimum-viable SLA engine (computed, not stored).
 *
 * SLA due dates are derived from created_at + the ticket priority's target
 * (± accumulated paused time), so they stay correct when targets change. State
 * is one of: ok | at_risk | breached | met | paused.
 *
 * Scope intentionally excludes business-hour calendars, multiple policies per
 * segment, and escalation chains — those are Pro-tier complexity, low first-pass
 * value. Pause reuses the Phase 1 custom-status system (a status flagged
 * sla_paused stops the clock).
 */
class SlaService
{
    /** Per-request, per-tenant config cache (targets + paused/closed status sets). */
    private static array $cache = [];

    private function config(int $tenantId): array
    {
        if (! isset(self::$cache[$tenantId])) {
            $prios = TicketPriority::forTenant($tenantId)->get(['name', 'first_response_hours', 'resolution_hours']);
            $stats = TicketStatus::forTenant($tenantId)->get(['name', 'is_closed_status', 'sla_paused']);
            self::$cache[$tenantId] = [
                'targets' => $prios->mapWithKeys(fn ($p) => [$p->name => [$p->first_response_hours, $p->resolution_hours]])->all(),
                'paused'  => $stats->where('sla_paused', true)->pluck('name')->all(),
                'closed'  => $stats->where('is_closed_status', true)->pluck('name')->all(),
            ];
        }

        return self::$cache[$tenantId];
    }

    /** Compute the SLA snapshot attached to every serialized ticket. */
    public function compute(Ticket $ticket): array
    {
        try {
            $cfg = $this->config($ticket->tenant_id);
            [$frHours, $resHours] = $cfg['targets'][$ticket->priority] ?? [null, null];
            if ($frHours === null && $resHours === null) {
                return ['tracked' => false];
            }

            $now = Carbon::now();
            $created = $ticket->created_at ? Carbon::parse($ticket->created_at) : $now;
            $paused = in_array($ticket->status, $cfg['paused'], true);
            // Use raw timestamps throughout — Carbon 3's diffInSeconds is signed and
            // easy to get backwards. A pause duration is always positive elapsed time.
            $pausedSeconds = (int) $ticket->sla_paused_seconds
                + ($ticket->sla_paused_at ? max(0, $now->getTimestamp() - Carbon::parse($ticket->sla_paused_at)->getTimestamp()) : 0);

            $state = function (Carbon $due, $doneAt, int $targetSeconds) use ($now, $paused) {
                if ($doneAt) {
                    return Carbon::parse($doneAt)->getTimestamp() <= $due->getTimestamp() ? 'met' : 'breached';
                }
                if ($paused) {
                    return 'paused';
                }
                $remaining = $due->getTimestamp() - $now->getTimestamp();
                if ($remaining < 0) {
                    return 'breached';
                }
                if ($remaining < max(3600, (int) round($targetSeconds * 0.2))) {
                    return 'at_risk';
                }

                return 'ok';
            };

            $out = ['tracked' => true, 'paused' => $paused];

            if ($frHours !== null) {
                $due = (clone $created)->addHours($frHours)->addSeconds($pausedSeconds);
                $out['response'] = ['due' => $due->toIso8601String(), 'state' => $state($due, $ticket->first_responded_at, $frHours * 3600)];
            }
            if ($resHours !== null) {
                $due = (clone $created)->addHours($resHours)->addSeconds($pausedSeconds);
                $out['resolution'] = ['due' => $due->toIso8601String(), 'state' => $state($due, $ticket->resolved_at, $resHours * 3600)];
            }

            return $out;
        } catch (\Throwable $e) {
            return ['tracked' => false];
        }
    }

    /** First staff reply stops the first-response clock. */
    public function onStaffReply(Ticket $ticket): void
    {
        if ($ticket->first_responded_at === null) {
            $ticket->forceFill(['first_responded_at' => Carbon::now()])->save();
        }
    }

    /**
     * Apply pause accounting + resolution stamp when a ticket's status changes.
     * Call AFTER the new status is known, passing the real from/to names.
     */
    public function onStatusChange(Ticket $ticket, ?string $from, string $to): void
    {
        $cfg = $this->config($ticket->tenant_id);
        $now = Carbon::now();
        $patch = [];

        $wasPaused = $from !== null && in_array($from, $cfg['paused'], true);
        $nowPaused = in_array($to, $cfg['paused'], true);
        if ($nowPaused && ! $wasPaused && $ticket->sla_paused_at === null) {
            $patch['sla_paused_at'] = $now;
        }
        if (! $nowPaused && $wasPaused && $ticket->sla_paused_at !== null) {
            $elapsed = max(0, $now->getTimestamp() - Carbon::parse($ticket->sla_paused_at)->getTimestamp());
            $patch['sla_paused_seconds'] = (int) $ticket->sla_paused_seconds + $elapsed;
            $patch['sla_paused_at'] = null;
        }

        $isClosed = in_array($to, $cfg['closed'], true);
        if ($isClosed && $ticket->resolved_at === null) {
            $patch['resolved_at'] = $now;
        }
        if (! $isClosed && $ticket->resolved_at !== null) {
            $patch['resolved_at'] = null; // reopened → resolution clock resumes
        }

        if ($patch) {
            $ticket->forceFill($patch)->save();
        }
    }
}
