<?php

namespace App\Services\Helpdesk;

use App\Services\Customer\Contracts\TicketIntakeContract;

/**
 * Helpdesk's implementation of Customer's TicketIntakeContract.
 *
 * The mirror of CustomerDirectoryService, which satisfies Helpdesk's
 * CustomerServiceContract in the other direction.
 *
 * Deliberately thin. It maps the portal's parameters into the array
 * HelpdeskService::createTicket() already takes and then gets out of the way —
 * numbering, the acknowledgement email, department routing, the ticket-manager
 * notification and the SLA clock all stay inside createTicket(), so a portal
 * ticket is the same object as one raised from the desk. Only the source
 * differs, and that is recorded rather than implied.
 *
 * The defaults live here rather than in Customer because they are Helpdesk's to
 * change: which department triage lands in, how far a customer may raise their
 * own priority, and what `source` a portal ticket carries.
 */
class HelpdeskTicketIntakeService implements TicketIntakeContract
{
    /**
     * The highest priority a customer may set for themselves.
     *
     * A requester who can mark their own ticket Urgent will, and then Urgent
     * stops meaning anything. Agents re-triage; the customer's choice is a hint
     * about impact, not an instruction about queue order.
     */
    private const MAX_CUSTOMER_PRIORITY = 'high';

    /** Ordered low → urgent, so the cap can be applied by position. */
    private const PRIORITY_ORDER = ['low', 'medium', 'high', 'urgent'];

    public function __construct(private HelpdeskService $helpdesk)
    {
    }

    public function createFromCustomerPortal(
        int $tenantId,
        int $clientId,
        int $clientContactId,
        string $requesterName,
        string $requesterEmail,
        string $subject,
        string $body,
        ?string $priority = null,
    ): int {
        $ticket = $this->helpdesk->createTicket([
            'subject'     => $subject,
            'description' => $body,
            'customer_id' => $clientId,

            // The portal contact is a ClientContact, not a User, so there is no
            // staff member who created this. Passing the key explicitly matters:
            // createTicket() falls back to auth()->id() when it is ABSENT, and in
            // a portal request that would be the contact's own id pointing into
            // the users table — a wrong row, not just a missing one.
            'created_by' => null,

            // Who to reply to and who to show as the requester. Helpdesk cannot
            // read client_contacts across the module boundary, so the portal
            // hands these over; createTicket() addresses the acknowledgement
            // email with them.
            'requester_name'  => $requesterName,
            'requester_email' => $requesterEmail,

            // Identifiable and reportable separately from internal, widget and
            // email intake.
            'source' => 'portal',

            // Department is left unset ON PURPOSE. createTicket() falls back to
            // the tenant's configured default, so admins control triage routing
            // from Support Settings and portal tickets behave like email and
            // widget intake rather than being pinned to a hard-coded queue.

            // Unassigned, so it lands in the open queue and notifies the ticket
            // managers rather than going straight to one person's list.
            'assigned_to' => null,

            'priority' => $this->cappedPriority($priority),
        ], $tenantId);

        return (int) $ticket->id;
    }

    /**
     * The customer's hint, clamped to what a customer is allowed to ask for.
     *
     * Null, unknown values and anything above the cap all fall back rather than
     * throwing: the portal already validates the field, and a ticket that fails
     * to be raised because of a priority string would be a worse outcome than
     * one raised at the default.
     */
    private function cappedPriority(?string $priority): string
    {
        $wanted = strtolower((string) $priority);
        $max    = array_search(self::MAX_CUSTOMER_PRIORITY, self::PRIORITY_ORDER, true);
        $at     = array_search($wanted, self::PRIORITY_ORDER, true);

        if ($at === false) {
            return 'medium';
        }

        return self::PRIORITY_ORDER[min($at, $max)];
    }
}
