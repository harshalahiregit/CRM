# Tenancy

## The constraint SIRE was designed around

SIRE assumes **shared database, shared tables, `tenant_id`** — and, critically,
assumes NOTHING protects it. Per the discovery report for the first host
report:

- no global scope — `grep -rn "addGlobalScope" app/` returns **zero** hits
- no tenant middleware, no tenant service, no container binding
- no database row-level security
- `BelongsToSireTenant` provides an **opt-in** `scopeForTenant()` and a `creating` hook
  that fires only when a user is authenticated

**~1,120 hand-written `->forTenant()` calls are what hold the line.** Miss one and
the query silently returns every tenant's rows — no error, no log entry.

SIRE does not attempt to fix that. It is defensive within its own boundary.

## What SIRE does

**1. `tenant_id NOT NULL` on all 20 tables.** The auto-stamp does nothing in a
command, a job or a token-resolved route, so the database refuses the mistake
rather than absorbing it.

**2. Every read chains `->forTenant($tenantId)`.** Enforced by
`tests/tenant-scoping.test.mjs`, which scans every SIRE query and fails the build
on an unscoped one. It carries a self-test proving it can detect a violation, and
an audit proving its model list covers every model in `Models/Sire`.

There is exactly one deliberate cross-tenant read — discovering which tenants to
sweep in the scheduled command — and it carries a `// tenant-sweep:` comment
explaining itself, selects `tenant_id` and nothing else, and is checked by name.

**3. Tenant never comes from the request.** FormRequests strip `tenant_id`,
`user_id` and `reporter_id` before validation — not to ignore them, but so nobody
wires them up later by accident.

**4. Route-bound models are ownership-checked.** Every controller taking a bound
model calls `assertTenantOwnership` first, aborting **404** — existence hiding,
A 403 confirms the record exists, which is the leak.

**5. Aggregates go through one entry point.** `SireInsightsService` and
`SireDashboardService` each have a single `scoped()` method that chains
`->forTenant()` first. An aggregate quietly spanning tenants would be both a breach
and a lie about the numbers.

## The adapter — connect this first

`src/Contracts/SireTenantProvider.php` is the single seam
between SIRE and however your application resolves tenants. The shipped implementation
in `integration/host-adapters/` reads `$request->user()->tenant_id`.

**If that is wrong, this is the only file that changes.**

It throws when no tenant can be established, deliberately:

> A resolver that guesses is worse than one that throws. A wrong tenant id does not
> fail loudly — it returns somebody else's data.

## Out of scope

SIRE requires **no refactor** of the host's tenancy. No global scope is introduced,
no middleware added, no existing query changed. Whether to add real tenant
enforcement application-wide is a foundation decision that belongs to the team, not to a
module — the discovery report flags it as risk R1 and SIRE does not pre-empt it.

## Verify after integration

```sql
SELECT COUNT(*) FROM sire_reports WHERE tenant_id IS NULL;   -- must be 0
```

`php artisan sire:doctor` runs that, and as tenant A:

```
GET /api/sire/reports/{a tenant B id}   → 404, never 200
GET /api/sire/dashboard                 → counts exclude tenant B
```
