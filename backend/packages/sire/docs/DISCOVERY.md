# Discovery

```bash
php artisan sire:discover
```

Inspects your application and writes a profile everything else reads.

**Read-only.** It reads the schema, the container, config, `composer.json`,
`package.json` and the filesystem. It writes exactly one file, in
`storage/app/sire/`. No migration runs, no row is touched, no cache is flushed,
no event fires. Safe on production.

## Why it is a separate step

Discovery inspects a database schema. Doing that on a page load would be
indefensible, so it happens once — on demand — and `sire:compatibility`,
`sire:install` and `sire:doctor` all read the cached result.

Re-run it when your application changes shape.

## What it looks at

| Area | What it reads |
|---|---|
| Platform | Laravel version, PHP version, database driver and server version |
| Auth | `config('auth.guards')`, the default guard, guard packages in composer.json |
| User | The auth provider's model, its table, its key, name and role columns |
| Tenancy | Tenant-shaped columns, tenant models, tenancy packages |
| Roles | A roles table, or the distinct values of a role column |
| Permissions | Known permission packages; whether Gate is available |
| Frontend | `package.json` dependencies, route and layout files |
| Integrations | Tables and packages suggesting audit, notes, attachments, settings, KB, versions |
| Scheduler | Whether the scheduler resolves and how many events are registered |

### The one place it reads data

Role **names** — the distinct values of a roles table or a role column, capped
at 100, with no other column selected.

That is deliberate, and it is the only exception. Without it the installer could
only say "type your role names", which invites a typo in exactly the mapping
that decides who reads the defect backlog.

## Confidence, not just values

A bare value would be a lie by omission. `tenant column: tenant_id` reads as
fact whether it came from an exact match or a hopeful guess, and the difference
between those is the difference between a working install and a silent leak.

| | Meaning |
|---|---|
| **HIGH** | Directly observed and unambiguous. A class exists; a column exists. |
| **MEDIUM** | Observed, but more than one reading fits. |
| **LOW** | Inferred from a naming convention alone. Enough to propose, never to apply. |
| **NONE** | Looked for, not found. Normal for every optional integration. |

Every finding also carries its **evidence** — what was actually inspected —
so you can decide whether discovery was right rather than taking its word.

## Tenancy is deliberately pessimistic

A tenant column is **never reported as HIGH**, even when exactly one exists.

`company_id` might identify the tenant. It might identify the customer a user
works for. Inside a single-tenant CRM those are different things that look
identical from the schema, and no inspection can separate them.

So:

- one candidate → **MEDIUM**, with the column named
- several → **LOW**, with all of them listed
- none → **absent**, with the alternative strategies spelled out

None of those is enough to apply automatically, and the installer asks
regardless of confidence.

## What it never contains

No passwords, tokens, API keys, database credentials, cookies, session data or
environment values. Discovery reads **shapes** — class names, column names,
package names — not values.

`sire:host-profile` prints a second-pass redacted version, safe to paste into a
ticket.

## The profile

```
storage/app/sire/host-profile.json
```

`storage/app`, never `public/`. This file describes your authentication,
tenancy and role model — it is a map of the doors.

```bash
php artisan sire:host-profile          # readable, redacted
php artisan sire:host-profile --raw    # with evidence
php artisan sire:discover --show       # every finding, in a table
php artisan sire:discover --json       # machine-readable
```

## When a detector fails

Each runs in its own try/catch. A host with an unreadable schema still gets its
framework, auth and frontend findings; the failure is recorded as a finding of
its own.

A survey that aborts on its first unreadable area is worth less than one that
reports it.

## What it does not do

- It does not modify anything.
- It does not guess a tenant source.
- It does not grant permissions.
- It does not inspect business logic, or try to understand what your models mean.
- It does not phone home.

Automation here is **safe, deterministic, reversible and explainable** — or it
does not happen.
