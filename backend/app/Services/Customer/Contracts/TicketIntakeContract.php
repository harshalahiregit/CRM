<?php

namespace App\Services\Customer\Contracts;

/**
 * Contract for raising a support ticket, owned by Shivam's Helpdesk module.
 *
 * The exact mirror of Helpdesk's CustomerServiceContract, in reverse: that one
 * is Helpdesk declaring what it needs from Customer, this is Customer declaring
 * what it needs from Helpdesk. Customer NEVER writes to the tickets table.
 *
 * Why an interface and not a raw insert. Reading a ticket from the portal is a
 * Schema::hasTable-guarded query builder — safe, because a SELECT cannot get a
 * ticket wrong. Creating one can: HelpdeskService::createTicket() assigns the
 * ticket number, sends the acknowledgement email, routes to the tenant's
 * default department, notifies the ticket managers and starts the SLA clock. An
 * insert from this side would produce a row that looks like a ticket and
 * behaves like none of one. So the portal asks, and Helpdesk does it its way.
 *
 * Ownership, as agreed: Customer owns this interface and the portal call site.
 * Helpdesk owns the implementation and its binding, so the source / department /
 * priority defaults stay Shivam's to change.
 *
 * @see \App\Services\Helpdesk\Contracts\CustomerServiceContract  the other direction
 */
interface TicketIntakeContract
{
    /**
     * Raise a ticket on behalf of a customer contact using the portal.
     *
     * The requester's name and email are passed rather than looked up: Helpdesk
     * cannot read client_contacts across the module boundary, and it needs both
     * to address the acknowledgement email and to show who asked. The portal has
     * them on the authenticated contact already.
     *
     * `$clientContactId` is a reference only — the contact is not a User, so the
     * created ticket has no created_by, the same as widget and email intake.
     *
     * `$priority` is a HINT from the customer, not an instruction: Helpdesk caps
     * it so nobody self-assigns Urgent, and agents re-triage. Null means let
     * Helpdesk choose its default.
     *
     * @return int the new ticket's id
     *
     * @throws \App\Exceptions\BusinessException when intake is unavailable or refused
     */
    public function createFromCustomerPortal(
        int $tenantId,
        int $clientId,
        int $clientContactId,
        string $requesterName,
        string $requesterEmail,
        string $subject,
        string $body,
        ?string $priority = null,
    ): int;
}
