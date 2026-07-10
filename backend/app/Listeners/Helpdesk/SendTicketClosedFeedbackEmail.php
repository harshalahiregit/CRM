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

        // No customer on the ticket → nothing to survey.
        if (empty($ticket->customer_id)) {
            return;
        }

        $customer = $this->customers->getCustomer((int) $ticket->customer_id, $ticket->tenant_id);

        if (! $customer || empty($customer['email'])) {
            Log::info("Helpdesk: ticket #{$ticket->id} closed, but customer has no email — feedback request skipped.");
            return;
        }

        Mail::to($customer['email'])->send(new TicketClosedFeedbackMail($ticket, $customer));
    }
}
