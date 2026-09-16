<?php

namespace Sire\Adapters\Defaults;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sire\Contracts\SireTenantProvider;
use Sire\Contracts\SireUserProvider;
use Sire\Dto\SireUserIdentity;

/**
 * The shipped user provider: reads the host's users table through config.
 *
 * Model, table and every field name come from config('sire.user.*'), so a host
 * whose users table is shaped differently is a config edit rather than an
 * implementation. A host whose users are not an Eloquent table at all — an
 * identity service, LDAP, a federated directory — implements SireUserProvider
 * and this class is never loaded.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It selects named columns. Not `SELECT *`. SIRE has no use for a password hash
 * or a preferences blob, and a provider that fetched whole rows would put them
 * one careless `toArray()` away from an API response or an AI payload.
 *
 * `email_field` defaults to NULL, which means SIRE never reads an email address
 * at all. Set it only if a host provider genuinely needs one — no SIRE feature
 * does.
 *
 * Lookups are cached per request and batched. A 200-entry timeline names perhaps
 * six distinct people; without batching that is 200 queries to render one page.
 */
class SireLocalUserProvider implements SireUserProvider
{
    /** @var array<string, SireUserIdentity|null> "tenant:user" => identity (null = known miss) */
    private array $cache = [];

    private ?bool $tableExists = null;

    public function __construct(private readonly SireTenantProvider $tenants)
    {
    }

    public function currentUser(): ?SireUserIdentity
    {
        $user = auth()->user();

        if ($user === null) {
            return null;   // console, scheduler, queue — a normal, expected answer
        }

        // The tenant comes from the TENANT PROVIDER, not from a column on the
        // user. Reading $user->tenant_id here would silently bypass whichever
        // strategy the host configured, and would be wrong for four of the five.
        $tenantId = $this->tenants->hasTenant() ? $this->tenants->currentTenant()->id : 0;

        $c = config('sire.user');

        return new SireUserIdentity(
            id: (int) $user->{$c['id_field'] ?? 'id'},
            tenantId: $tenantId,
            displayName: (string) ($user->{$c['name_field'] ?? 'name'} ?? 'User '.$user->getKey()),
            role: $c['role_field'] ? ($user->{$c['role_field']} ?? null) : null,
            email: $c['email_field'] ? ($user->{$c['email_field']} ?? null) : null,
        );
    }

    public function lookup(int $tenantId, int $userId): ?SireUserIdentity
    {
        $key = $tenantId.':'.$userId;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->lookupMany($tenantId, [$userId])[$userId] ?? null;
    }

    public function lookupMany(int $tenantId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === [] || ! $this->tableExists()) {
            return [];
        }

        $c = config('sire.user');

        try {
            $query = DB::table($c['table'])->whereIn($c['id_field'], $userIds);

            // Tenant-scoped WHEN THE COLUMN EXISTS. Under a relationship or
            // resolver strategy the users table may carry no tenant column at
            // all, and adding a WHERE on a missing column would fail every
            // lookup — so the scope is applied where it is meaningful and the
            // caller's own tenant checks carry the rest.
            $tenantColumn = $this->tenantColumn();

            if ($tenantColumn !== null) {
                $query->where($tenantColumn, $tenantId);
            }

            $select = array_values(array_filter([
                $c['id_field'], $c['name_field'], $c['role_field'],
            ]));

            $rows = $query->get($select);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        $found = [];

        foreach ($rows as $row) {
            $id = (int) $row->{$c['id_field']};

            $identity = new SireUserIdentity(
                id: $id,
                tenantId: $tenantId,
                displayName: (string) ($row->{$c['name_field']} ?? 'User '.$id),
                role: $c['role_field'] ? ($row->{$c['role_field']} ?? null) : null,
            );

            $found[$id] = $identity;
            $this->cache[$tenantId.':'.$id] = $identity;
        }

        // Cache the misses too, or a timeline full of deleted users re-queries
        // for every entry.
        foreach ($userIds as $id) {
            $this->cache[$tenantId.':'.$id] ??= $found[$id] ?? null;
        }

        return $found;
    }

    public function isActive(int $tenantId, int $userId): bool
    {
        // With no host concept of an inactive user, everyone who exists is
        // assignable. SIRE uses this only to keep leavers out of pickers — it
        // never hides history, because a person who left still did the thing the
        // timeline says they did.
        return $this->lookup($tenantId, $userId) !== null;
    }

    /** The tenant column on the users table, or null when there is not one. */
    private function tenantColumn(): ?string
    {
        if ((string) config('sire.tenant.strategy') !== 'user_attribute') {
            return null;
        }

        $column = (string) config('sire.tenant.attribute', 'tenant_id');

        return Schema::hasColumn((string) config('sire.user.table', 'users'), $column) ? $column : null;
    }

    private function tableExists(): bool
    {
        return $this->tableExists ??= Schema::hasTable((string) config('sire.user.table', 'users'));
    }
}
