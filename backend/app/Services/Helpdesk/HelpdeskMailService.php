<?php

namespace App\Services\Helpdesk;

use App\Mail\Helpdesk\TicketAssignedMail;
use App\Mail\Helpdesk\TicketReceivedMail;
use App\Mail\Helpdesk\TicketReplyMail;
use App\Mail\Helpdesk\TicketStatusUpdateMail;
use App\Models\Helpdesk\Ticket;
use App\Models\Helpdesk\TicketReply;
use App\Models\User;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the customer-facing helpdesk emails (acknowledge on create, deliver
 * staff replies) and the internal assignment notification. Every send is
 * best-effort and swallow-logged: a mail failure must never break the ticket
 * write that triggered it.
 *
 * Customer identity is resolved through the service contract (mock until Zafar's
 * Customer module ships), never an Eloquent join — same module-isolation rule
 * the rest of Helpdesk follows.
 */
class HelpdeskMailService
{
    private CustomerServiceContract $customers;

    public function __construct(?CustomerServiceContract $customers = null)
    {
        $this->customers = $customers ?? new MockCustomerService();
    }

    /** The requester's own address wins; fall back to the linked customer record. */
    public function recipientFor(Ticket $ticket): ?array
    {
        if (! empty($ticket->requester_email)) {
            return ['email' => $ticket->requester_email, 'name' => $ticket->requester_name ?: 'there'];
        }

        if (! empty($ticket->customer_id)) {
            $c = $this->customers->getCustomer((int) $ticket->customer_id, $ticket->tenant_id);
            if ($c && ! empty($c['email'])) {
                return ['email' => $c['email'], 'name' => $c['name'] ?? 'there'];
            }
        }

        return null;
    }

    public function sendAcknowledgement(Ticket $ticket): void
    {
        $to = $this->recipientFor($ticket);
        if (! $to) {
            return; // internal ticket with no external requester — nothing to ack
        }

        $this->safely(fn () => Mail::to($to['email'])->send(new TicketReceivedMail($ticket, $to['name'])),
            "acknowledgement for ticket #{$ticket->id}");
    }

    public function sendStaffReply(Ticket $ticket, TicketReply $reply, string $agentName): void
    {
        $to = $this->recipientFor($ticket);
        if (! $to) {
            return;
        }

        // Cc the addresses the agent added on the reply. Stored as an array on the
        // reply; drop blanks and the primary recipient (no point Cc'ing them the
        // mail they're already the To of). Without this the Cc field was captured
        // and saved but never actually copied anyone — "Cc not working".
        $cc = collect($reply->cc ?? [])
            ->filter(fn ($e) => is_string($e) && filter_var(trim($e), FILTER_VALIDATE_EMAIL))
            ->map(fn ($e) => strtolower(trim($e)))
            ->reject(fn ($e) => $e === strtolower(trim($to['email'])))
            ->unique()
            ->values()
            ->all();

        $this->safely(function () use ($to, $cc, $ticket, $reply, $agentName) {
            $mailer = Mail::to($to['email']);
            if (! empty($cc)) {
                $mailer->cc($cc);
            }
            $mailer->send(new TicketReplyMail($ticket, $reply, $to['name'], $agentName));
        }, "reply email for ticket #{$ticket->id}");
    }

    public function sendStatusUpdate(Ticket $ticket, string $oldStatus, string $newStatus): void
    {
        $to = $this->recipientFor($ticket);
        if (! $to) {
            return;
        }

        $this->safely(fn () => Mail::to($to['email'])->send(new TicketStatusUpdateMail($ticket, $to['name'], $oldStatus, $newStatus)),
            "status-update email for ticket #{$ticket->id}");
    }

    public function sendAssignment(Ticket $ticket, ?int $userId): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        if (! $user || empty($user->email)) {
            return;
        }

        $this->safely(fn () => Mail::to($user->email)->send(new TicketAssignedMail($ticket, $user->name ?: 'there')),
            "assignment email for ticket #{$ticket->id}");
    }

    private function safely(callable $send, string $what): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning("Helpdesk mail failed ({$what}): {$e->getMessage()}");
        }
    }
}
