<?php

namespace Sire\Dto;

/**
 * SIRE SDK — the customer an issue affects, as SIRE is allowed to know them.
 *
 * Four fields, for the same reason SireUserIdentity has four: this object
 * crosses the integration boundary, and a rich customer model behind it is a
 * standing invitation for somebody to append a contact's email or a contract
 * value to something that gets rendered, logged or sent to an AI provider.
 *
 * SIRE never contacts a customer. It has no address, no phone number and no
 * billing relationship here — only enough to say WHOSE problem this is on a
 * defect register read by engineers.
 */
final class SireCustomer
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $name,
        /** A human-facing code where the host has one — an account number, a short name. */
        public readonly ?string $reference = null,
        /** Deep link into the host's own customer screen, for the "open" affordance. */
        public readonly ?string $url = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'reference' => $this->reference,
            'url'       => $this->url,
        ];
    }
}
