<?php

namespace Sire\Dto;

/**
 * SIRE SDK — one row in the "who can this be given to" picker.
 *
 * SEPARATE FROM SireUserIdentity ON PURPOSE. That object crosses the AI
 * redaction boundary and is deliberately four readonly fields, so that nobody
 * can append a fifth to a prompt payload. This one never goes near a provider:
 * it exists so an assignment dropdown can say "Priya Sharma — Engineering,
 * Senior Developer" instead of "Priya Sharma", which is the difference between a
 * picker you can use and a list of names you have to already know.
 *
 * `kind` is what lets the client separate the two audiences. Staff and customer
 * contacts are both people an issue can be put against, and a dropdown that
 * mixes them is how somebody assigns a production defect to a client by
 * mistake.
 */
final class SireDirectoryEntry
{
    public const KIND_STAFF    = 'staff';
    public const KIND_CUSTOMER = 'customer';

    public function __construct(
        public readonly int|string $id,
        public readonly string $displayName,
        /** 'staff' or 'customer' — the group the picker renders them under. */
        public readonly string $kind = self::KIND_STAFF,
        public readonly ?string $role = null,
        /** Staff Management fields, where the host records them. */
        public readonly ?string $department = null,
        public readonly ?string $designation = null,
        /** The company, for a customer contact. Null for staff. */
        public readonly ?string $company = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'display_name' => $this->displayName,
            'kind'         => $this->kind,
            'role'         => $this->role,
            'department'   => $this->department,
            'designation'  => $this->designation,
            'company'      => $this->company,
        ];
    }
}
