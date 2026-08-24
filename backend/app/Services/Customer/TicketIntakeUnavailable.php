<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Services\Customer\Contracts\TicketIntakeContract;

/**
 * Placeholder binding for TicketIntakeContract until Helpdesk registers its own.
 *
 * The mirror of Helpdesk\Mocks\MockCustomerService: it exists so the interface
 * always resolves and nothing 500s on a missing binding, and it is meant to be
 * replaced rather than used.
 *
 * It refuses rather than pretending to succeed. A stub that returned a fake
 * ticket id would tell a customer their request had been logged when no ticket
 * exists, no acknowledgement was sent and no agent will ever see it — which is
 * worse than the feature being visibly off.
 *
 * The portal asks `isAvailable()` before showing the form, so in practice a
 * customer never reaches this exception; it is the backstop for a direct POST.
 *
 * Helpdesk's binding wins whichever order the two are registered in: this one
 * is registered with bindIf, and a later plain bind overrides it.
 */
class TicketIntakeUnavailable implements TicketIntakeContract
{
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
        throw new BusinessException(
            'Raising a support ticket from the portal is not available yet. '
            .'Please contact your account manager.',
            503,
        );
    }
}
