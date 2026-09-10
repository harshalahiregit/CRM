# Troubleshooting

Start here:

```bash
php artisan sire:doctor
```

Every check says WHAT it looked at, WHY it matters and HOW to fix it.

---

## Every SIRE route returns 503

```
SIRE is not configured: no authentication middleware is set.
```

**Working as designed.** `config('sire.host.auth_middleware')` is empty, and
SIRE refuses everything rather than publishing an engineering backlog to
anonymous traffic.

```php
// config/sire-host.php
'host' => ['auth_middleware' => ['auth:sanctum']],
```

Or run `php artisan sire:install`.

## Everyone gets 403, including administrators

```
SIRE has no login-type mapping configured, so no account has access.
```

No role is mapped to `ADMIN` or `INTERNAL_USER`. A role in no list is denied —
deliberately, because the alternative failure mode is a customer reading the
backlog.

```php
'login_types' => [
    'admin'         => ['super_admin'],
    'internal_user' => ['employee', 'developer', 'qa'],
    'customer'      => ['client'],
    'vendor'        => ['supplier'],
],
```

Use the **exact** role strings your application stores. Matching ignores case
and separators, so `Super Admin` and `super_admin` are the same role.

## "SIRE: no tenant in scope"

The message names the strategy, what it looked for, and whether the request was
authenticated. Read it before changing anything.

| Cause | Fix |
|---|---|
| Wrong strategy | `config('sire.tenant.strategy')` |
| Wrong column | `config('sire.tenant.attribute')` |
| Not multi-tenant | Set strategy to `single_tenant` |
| Console or scheduled work | Expected — pass an explicit tenant id |

## One customer can see another's issues

**Stop. This is the serious one.**

Your tenant strategy is wrong. Confirm with the two-tenant check:

1. Tenant A: create an issue, note the id.
2. Tenant B: request `/api/sire/reports/<id>`.
3. You want **404**.

The usual cause is a column like `company_id` that identifies **the customer a
user works for** rather than the tenant they belong to. From the schema those
look identical.

```bash
php artisan sire:doctor        # shows the configured strategy
```

## Timelines show "Unknown" instead of names

`SireUserProvider` cannot read your users table.

```php
'user' => ['table' => 'users', 'name_field' => 'full_name'],
```

If your users are not an Eloquent table at all, implement `SireUserProvider`.

Note that **existing** audit entries keep the name snapshotted at write time —
they will not retroactively fix themselves, which is correct: the trail records
what was true then.

## Nobody receives notifications

Expected until you connect one. SIRE's default provider **logs** the delivery
decision rather than sending anything, because a module that starts emailing
people the moment it is installed has made an assumption it had no right to
make.

```bash
tail -f storage/logs/laravel.log | grep sire.notify
```

That is also the fastest way to verify the fan-out rules before any inbox is
involved. To actually send, implement `SireNotificationProvider`.

## The knowledge base panel is always empty

**Two indistinguishable causes**, and this is the one integration where that is
true:

1. No knowledge provider is connected — expected, and fine.
2. One is connected but wrong — every call is wrapped so a broken KB degrades to
   "no related articles" rather than breaking an issue page.

**Verify with a search you know should hit.** Never by the absence of errors.

## Report Issue says "Unknown screen"

The route map does not cover that path. **Not a failure** — the report still
submits in one click, with an editable context.

```bash
node tools/sire-route-audit.mjs resources/js/app/routes.jsx
```

Or have the screen declare itself:

```js
SireContext.register({ module: 'sales', screen: 'lead-details', entityId: id });
```

## SIRE screens look unstyled

The host bridge is not configured, so SIRE is using its own plain fallbacks.

```js
SireHost.configure({
  api,
  ui: { Button, Modal, DataTable },
  toast: useToast,
});
```

Everything is optional and partial registration is normal — register your Button
and Modal, and only the tag input still looks plain.

## Migrations fail on a duplicate index name

MySQL caps identifiers at 64 characters. SIRE's longest is 28, so this is almost
always a **collision with an existing index of the same name** in your database.
Rename yours, or the SIRE one — every SIRE index name is explicit in the
migration.

## SLA never warns or breaches

Two possibilities.

**No policy configured.** SIRE reports every issue `ON_TRACK` with no target,
which is the correct reading of "not configured" — inventing a deadline would
breach issues against a target nobody agreed to.

```php
settings()->set($tenantId, 'sire.sla.policies', [
    ['match' => ['type' => 'bug', 'priority' => 'p1'], 'ack_minutes' => 15, 'resolve_minutes' => 240],
]);
```

**No scheduler.** SLA state is still computed on read, so dashboards are
correct; only proactive notices are lost.

```bash
crontab -l | grep schedule:run
```

## AI suggestions never appear

AI is **off by default**. That is not a bug.

```php
settings()->set($tenantId, 'sire.ai.enabled', true);
settings()->set($tenantId, 'sire.ai.capabilities', ['classification', 'duplicates']);
```

```bash
php artisan sire:index-issues --tenant=<id>
```

Duplicate detection needs the index built before it can find anything.

## `sire:architecture` fails after I edited SIRE

You added a host dependency to SIRE core. Move it behind a contract — see
[ADAPTERS.md](ADAPTERS.md).

If you meant to fork SIRE for one application, that is a legitimate choice; the
check exists to make it a **deliberate** one rather than something that happens
gradually.

## Installing twice

Safe. `sire:install` is idempotent — the generated config is rewritten in full
rather than appended to, and a second run offers to reconfigure.

```bash
php artisan sire:install --dry-run       # what would change
php artisan sire:install --reconfigure   # re-ask everything
```

---

## Still stuck

```bash
php artisan sire:doctor --json > doctor.json
php artisan sire:host-profile > profile.json
```

Both are redacted and safe to share. Neither contains passwords, tokens, keys or
credentials.
