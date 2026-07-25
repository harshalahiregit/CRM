<?php

namespace App\Console\Commands;

use App\Mail\Helpdesk\ReminderDueMail;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReminder;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fires due ticket reminders (BUG-19 / REQ-08). Agents have always been able to
 * set "remind me about this ticket at 14:00" — but nothing ever read the rows
 * back, so the whole feature was a silent to-do list. This is the clock.
 *
 * Safe to run repeatedly: notified_at is the idempotency guard, so a due
 * reminder converts to exactly one bell + one email, and a catch-up run after
 * downtime fires each pending row once rather than duplicating.
 */
class RunHelpdeskReminders extends Command
{
    protected $signature = 'helpdesk:run-reminders';

    protected $description = 'Fire due ticket reminders as in-app notifications + email';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        $now = now();

        TicketReminder::query()
            ->whereNull('notified_at')          // never fired
            ->where('is_done', false)           // agent hasn't already ticked it off
            ->where('remind_at', '<=', $now)    // ...and it's due
            // TicketReminder defines only a user() relation (not owned by this
            // module), so the ticket + owner are resolved per chunk by id rather
            // than eager-loaded — one extra indexed query per 200 rows.
            ->chunkById(200, function (Collection $rows) use ($notifications, &$sent) {
                $tickets = Ticket::whereIn('id', $rows->pluck('ticket_id')->unique())
                    ->get(['id', 'subject', 'status', 'priority', 'tenant_id'])->keyBy('id');
                $owners = User::whereIn('id', $rows->pluck('user_id')->unique())
                    ->get(['id', 'name', 'email'])->keyBy('id');

                foreach ($rows as $reminder) {
                    $ticket = $tickets->get($reminder->ticket_id);
                    // The ticket was hard-deleted out from under the reminder (or
                    // soft-deleted, so the default scope hides it). Nothing to point
                    // at — retire the row quietly so it never re-scans.
                    if (! $ticket) {
                        $reminder->forceFill(['notified_at' => now()])->save();
                        continue;
                    }

                    $owner = $owners->get($reminder->user_id);

                    $notifications->notify(
                        userId: (int) $reminder->user_id,
                        tenantId: (int) $reminder->tenant_id,
                        type: 'ticket.reminder',
                        title: "Reminder: ticket #{$ticket->id}",
                        message: $reminder->note ?: $ticket->subject,
                        link: "/app/helpdesk/tickets/{$ticket->id}",
                        // No actorId: the reminder's owner set it for themselves, and
                        // notify() drops self-notification when actor === recipient.
                        actorId: null,
                    );

                    if ($owner && ! empty($owner->email)) {
                        try {
                            Mail::to($owner->email)->send(
                                new ReminderDueMail($reminder, $ticket, $owner->name ?: 'there')
                            );
                        } catch (\Throwable $e) {
                            Log::warning("Helpdesk mail failed (reminder #{$reminder->id}): {$e->getMessage()}");
                        }
                    }

                    // Stamped regardless of delivery — both channels swallow-log, and
                    // a failed send must not re-fire on every pass forever.
                    $reminder->forceFill(['notified_at' => now()])->save();
                    $sent++;
                }
            });

        $this->info("Helpdesk: {$sent} reminder(s) fired.");

        return self::SUCCESS;
    }
}
