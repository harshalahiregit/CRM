<?php

namespace Sire\Dto;

/**
 * SIRE SDK — which tenant, and what to call it.
 *
 * Always server-derived. A SireTenantIdentity constructed from a request body is
 * a security bug, not a shortcut: SIRE's tenancy model has no global scope and no
 * row-level security, so the id in this object is the only thing standing between
 * one customer's issues and another's.
 *
 * `name` exists for display only — dashboard headers, notification subjects. No
 * SIRE decision is ever made on it.
 */
final class SireTenantIdentity
{
    /**
     * @param  array<string, mixed> $metadata host-defined; SIRE never interprets it
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name = null,
        public readonly array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            name: $data['name'] ?? null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
