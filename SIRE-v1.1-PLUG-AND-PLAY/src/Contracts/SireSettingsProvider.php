<?php

namespace Sire\Contracts;

/**
 * SIRE SDK — CONFIGURATION.
 *
 * SIRE owns the KEYS and their meaning; the host may own the storage. Every SIRE
 * setting lives under the `sire.*` namespace, so a host settings table gains one
 * clearly-labelled neighbourhood rather than scattered rows.
 *
 * OPTIONAL. SIRE ships its own per-tenant settings table, so an installation
 * needs no host settings system at all.
 *
 * READS MUST NEVER THROW
 *
 * A missing or misconfigured settings backend means SIRE runs on its documented
 * defaults, every one of which is the safe choice: SLA off, AI off, release
 * gates on. Configuration that cannot be read should quieten SIRE, never break
 * it.
 *
 * Values include arrays — SLA policies, release gates, role rosters — so
 * implementations must round-trip structures, not just scalars. JSON-encode on
 * write, decode on read.
 */
interface SireSettingsProvider
{
    public function get(int $tenantId, string $key, mixed $default = null): mixed;

    public function set(int $tenantId, string $key, mixed $value): void;

    /**
     * Everything under a prefix, for the settings screen and `sire:doctor`.
     *
     * @return array<string, mixed> keyed by full setting name
     */
    public function all(int $tenantId, string $prefix = 'sire.'): array;

    public function forget(int $tenantId, string $key): void;
}
