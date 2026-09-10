<?php

namespace Sire\Dto;

/**
 * SIRE SDK — who someone is, as far as SIRE is concerned.
 *
 * SIRE core never sees the host CRM's User model. It sees this, and only this.
 *
 * WHY THIS IS FOUR FIELDS AND NOT FORTY
 *
 * The whole of SIRE — 22 workflow states, SLA, QA, releases, 13 AI capabilities —
 * needs exactly four things about a person: which record they are, which tenant
 * they belong to, what to call them on screen, and optionally what role they hold.
 * Everything else a CRM keeps about a user (password hash, phone number, manager,
 * last login, preferences) is data SIRE has no business receiving, and passing a
 * whole User model through SIRE would hand it all of that by default.
 *
 * That matters most at the AI boundary. `AiContextSchema` allowlists what may be
 * sent to a provider; a rich User model behind it is a standing invitation for
 * someone to add `$user->email` to a prompt payload. Four readonly fields cannot
 * leak a fifth.
 *
 * `email` is nullable and populated ONLY where the host explicitly chooses to —
 * SIRE itself never requires it, and no SIRE feature reads it.
 */
final class SireUserIdentity
{
    /**
     * @param  int                  $id           the host CRM's user id
     * @param  int                  $tenantId     server-derived, never client-supplied
     * @param  string               $displayName  what appears on a timeline
     * @param  string|null          $role         host role name, if the host exposes one
     * @param  string|null          $email        only when the host deliberately supplies it
     * @param  array<string, mixed> $metadata     host-defined; SIRE never interprets it
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $displayName,
        public readonly ?string $role = null,
        public readonly ?string $email = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * Build from a plain array — the shape a host adapter is most likely to have.
     *
     * @param  array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            tenantId: (int) ($data['tenant_id'] ?? $data['tenantId'] ?? 0),
            displayName: (string) ($data['display_name'] ?? $data['displayName'] ?? $data['name'] ?? 'Unknown'),
            role: $data['role'] ?? null,
            email: $data['email'] ?? null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * What is safe to render on a timeline or a dashboard.
     *
     * Deliberately excludes email and metadata: a SIRE response should not be a
     * way to enumerate a tenant's email addresses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'display_name' => $this->displayName,
            'role'         => $this->role,
        ];
    }

    public function is(?self $other): bool
    {
        return $other !== null && $other->id === $this->id && $other->tenantId === $this->tenantId;
    }
}
