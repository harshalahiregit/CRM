<?php

namespace App\Listeners\Helpdesk;

use App\Events\Helpdesk\TicketClosed;
use App\Mail\Helpdesk\TicketClosedFeedbackMail;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Helpdesk\Mocks\MockCustomerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * On ticket closure, email the customer a one-click star-rating request.
 *
 * Auto-discovered by Laravel (typed handle() in app/Listeners) — no provider
 * registration needed. The customer address is resolved through the service
 * contract (mocked until Zafar's Customer module ships).
 */
class SendTicketClosedFeedbackEmail
{
    private CustomerServiceContract $customers;

    public function __construct(?CustomerServiceContract $customers = null)
    {
        $this->customers = $customers ?? new MockCustomerService();
    }

    public function handle(TicketClosed $event): void
    {
        $ticket = $event->ticket;

        // Resolve who to survey. A linked customer_id wins (a real customer record
        // via the contract), but a ticket raised through the widget or by inbound
        // email carries only a free-text requester_email — and those customers are
        // exactly the ones worth surveying. This used to bail whenever customer_id
        // was empty, so widget/email tickets were never asked for feedback. The
        // preference order mirrors HelpdeskMailService::recipientFor.
        $recipient = null;

        if (! empty($ticket->customer_id)) {
            $customer = $this->customers->getCustomer((int) $ticket->customer_id, $ticket->tenant_id);
            if ($customer && ! empty($customer['email'])) {
                $recipient = ['email' => $customer['email'], 'name' => $customer['name'] ?? 'there'];
            }
        }

        if (! $recipient && ! empty($ticket->requester_email)) {
            $recipient = ['email' => $ticket->requester_email, 'name' => $ticket->requester_name ?: 'there'];
        }

        if (! $recipient) {
            Log::info("Helpdesk: ticket #{$ticket->id} closed, but no customer/requester email — feedback request skipped.");
            return;
        }

        Mail::to($recipient['email'])->send(new TicketClosedFeedbackMail($ticket, $recipient));
    }
}
