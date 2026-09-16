<?php

namespace Sire\Adapters\Defaults;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sire\Contracts\SireTenantProvider;
use Sire\Contracts\SireUserProvider;
use Sire\Dto\SireUserIdentity;
use Sire\Dto\SireDirectoryEntry;
use Sire\Support\SireLoginType;

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

    /**
     * Everyone in this tenant who may be given engineering work.
     *
     * The role filter comes from config('sire.login_types'), NOT from a literal
     * list of role names. That distinction is the whole reason this is safe to
     * ship: the installer discovers those names from the host and a human
     * confirms them, so this asks "who can reach SIRE at all" rather than
     * guessing that a host calls its people 'admin' and 'staff'.
     *
     * Deleted users are excluded where the table soft-deletes; leavers should not
     * appear in an assignment picker. History is untouched -- a person who left
     * still did what the timeline says they did.
     */
    public function directory(int $tenantId, ?string $search = null, int $limit = 200): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        $c = config('sire.user');

        // Role names come from config('sire.login_types'), NOT from a literal
        // list. The installer discovers them from the host and a human confirms
        // them, so this asks "who does this application know about" rather than
        // guessing that everyone calls their people 'admin' and 'staff'.
        $kinds = [
            SireDirectoryEntry::KIND_STAFF => SireLoginType::ENGINEERING,
            // Customer contacts are offered too, under their own heading. An
            // issue can belong to the client who raised it, and a dropdown that
            // MIXES them is how a production defect gets assigned to a customer
            // by mistake -- which is why kind exists rather than one flat list.
            SireDirectoryEntry::KIND_CUSTOMER => [SireLoginType::CUSTOMER],
        ];

        $roleToKind = [];
        foreach ($kinds as $kind => $types) {
            foreach ($types as $type) {
                foreach ((array) config("sire.login_types.{$type}", []) as $role) {
                    $roleToKind[mb_strtolower((string) $role)] = $kind;
                }
            }
        }

        if ($roleToKind === []) {
            // Nothing declared means nothing can be said about who works here.
            // The caller falls back to the rosters, which is the behaviour this
            // extends rather than replaces.
            return [];
        }

        // Staff Management columns, used only when the host actually has them.
        $table = (string) $c['table'];
        $extra = array_values(array_filter(
            ['department', 'designation'],
            fn (string $column) => Schema::hasColumn($table, $column),
        ));

        try {
            $query = DB::table($table)->whereIn($c['role_field'], array_keys($roleToKind));

            if ($column = $this->tenantColumn()) {
                $query->where($column, $tenantId);
            }

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if ($search !== null && trim($search) !== '') {
                $query->where($c['name_field'], 'like', '%'.trim($search).'%');
            }

            $rows = $query
                ->orderBy($c['name_field'])
                ->limit(max(1, min($limit, 500)))
                ->get(array_merge([$c['id_field'], $c['name_field'], $c['role_field']], $extra));
        } catch (\Throwable $e) {
            // An assignment picker that cannot load is a nuisance; one that takes
            // the issue page down with it is an outage.
            report($e);

            return [];
        }

        return $rows->map(function ($row) use ($c, $roleToKind) {
            $role = $row->{$c['role_field']} ?? null;

            return new SireDirectoryEntry(
                id: (int) $row->{$c['id_field']},
                displayName: (string) ($row->{$c['name_field']} ?? ''),
                kind: $roleToKind[mb_strtolower((string) $role)] ?? SireDirectoryEntry::KIND_STAFF,
                role: $role,
                department: $row->department ?? null,
                designation: $row->designation ?? null,
                company: $row->company ?? null,
            );
        })->all();
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
