# Host integration

**SIRE runs before you integrate anything.** Copy the code, register one service
provider, migrate, and you have a working issue tracker — every one of the
thirteen SDK contracts ships with an implementation SIRE owns.

Integration is then a series of small, independent, reversible steps: point one
config key at one class, and that subsystem starts using your application's
instead of SIRE's. Nothing else changes. You can stop after one, or never start.

```
        your application
              ▲
              │  13 contracts, and nothing else
       ┌──────┴───────────────────────────────────┐
       │  App\Contracts\Sire\Sdk\Sire*Provider    │
       └──────┬───────────────────────────────────┘
              │
        SIRE core — workflow, SLA, QA, releases, quality, AI
```

Outside those thirteen, SIRE reaches exactly **one** class in your codebase: the
framework's base `Controller`, to extend. That is enforced —
`tests/sdk.test.mjs` fails the build if a SIRE file imports anything else of
yours.

## Do them in this order

Not alphabetical. Ordered by what happens when you get it wrong.

| # | Provider | Optional? | Getting it wrong looks like |
|---|---|---|---|
| 1 | **Tenant** | Recommended | **Nothing. Another tenant's data, on a page that renders normally.** |
| 2 | **User** | Recommended | Wrong names on timelines; empty assignment pickers |
| 3 | Authorization | Optional | People see actions they should not, or cannot act at all |
| 4 | Notification | Recommended | Nobody is told anything; the workflow still works |
| 5 | Attachment | Optional | Screenshots fail to upload — loudly |
| 6 | Audit | Optional | Nothing: SIRE keeps its own trail |
| 7 | Notes | Optional | Nothing: SIRE keeps its own comments |
| 8 | Settings | Optional | Nothing: SIRE keeps its own settings |
| 9 | Numbering | Optional | Nothing: SIRE allocates `SIR-000001` itself |
| 10 | SLA | **Rarely** | Nothing: SIRE owns the SLA engine |
| 11 | **Knowledge** | Optional | **Nothing. "No related articles" — identical to a correct empty result.** |
| 12 | Version | Optional | `detected_version` is null on new issues |
| 13 | Context | Recommended | Context says "unknown screen"; reporting still works |

**Only two fail silently**, and they are the two to be careful with. Everything
else announces itself.

**Six are genuinely optional** in the strong sense: SIRE has its own tables and
engines for audit, notes, settings, numbering, SLA and knowledge links. Leaving
them alone is a supported permanent state, not a half-finished installation.

## How to connect one

```php
// config/sire.php
'providers' => [
    'tenant' => \App\Sire\Host\HostTenantProvider::class,   // yours
    'audit'  => \App\Services\Sire\Sdk\SireLocalAuditProvider::class,  // still SIRE's
    // ... the other eleven
],
```

Mixed states are expected. Wire two, ship, continue next quarter.

Stubs to copy: [`../examples/host-adapters/`](../examples/host-adapters/).
Each is a complete class with every method present and throwing until you fill it
in — a stub that returned an empty array would look finished and render as
"nothing here".

## Confirm it

```bash
php artisan sire:doctor
```

Prints which implementation is behind each of the thirteen, which tables exist,
and what is still on SIRE's own. Read it before believing the integration is
done.

---

# 1. Tenant — `SireTenantProvider`

> **FIND IN YOUR APP** — however a request learns which tenant it belongs to. A
> column on the user, a subdomain, a middleware, an impersonation stack.

| Method | Returns | Contract |
|---|---|---|
| `currentTenant()` | `SireTenantIdentity` | **Throws** when there is no tenant |
| `hasTenant()` | `bool` | Whether the above would succeed |
| `exists(int $tenantId)` | `bool` | For console commands given `--tenant` |
| `assertAccess(object $record)` | `void` | **Aborts 404** when not this tenant's |

```php
public function currentTenant(): SireTenantIdentity
{
    $tenantId = <PLACEHOLDER: your tenant resolution>;

    if (empty($tenantId)) {
        throw new \RuntimeException('SIRE: no tenant in scope.');
    }

    return new SireTenantIdentity($tenantId, <PLACEHOLDER: display name, or null>);
}
```

**Read this one twice.** Every other failure in SIRE is loud. This one is silent:
a wrong tenant provider returns another tenant's issues on a page that looks
completely normal.

- Resolve **only** from server-side state. Never a request body, query string,
  route parameter or header.
- `assertAccess()` must abort **404, not 403**. A 403 confirms the record exists,
  which turns any id field into a cross-tenant existence oracle — increment the
  id, watch the status codes, count another tenant's issues.
- `currentTenant()` has no nullable return and no default argument on purpose.
  There is nowhere to put a fallback, because a fallback is the bug.

**Implementing this does not hand tenancy to you.** SIRE still scopes every query
itself and still calls `assertAccess()` on every route-bound record. This
provider only answers *which tenant is this request* — a provider that returns
the wrong one does not bypass SIRE's checks, it lies to them.

**Verify it by hand**, with two tenants and one URL: sign in as A, note an issue
id, sign in as B, request it. You want a 404.

---

# 2. User — `SireUserProvider`

> **FIND IN YOUR APP** — the authenticated user, and how to look one up by id.

| Method | Returns | Contract |
|---|---|---|
| `currentUser()` | `?SireUserIdentity` | Null outside a request is normal |
| `lookup(int $tenantId, int $userId)` | `?SireUserIdentity` | **Tenant-scoped** |
| `lookupMany(int $tenantId, array $userIds)` | `array` | Keyed by id; batched |
| `isActive(int $tenantId, int $userId)` | `bool` | True when you have no such concept |

**SIRE never sees your User model.** `SireUserIdentity` carries four fields — id,
tenantId, displayName, and optionally role — because that is genuinely all SIRE
needs across 22 workflow states, SLA, QA, releases and 13 AI capabilities.

That matters most at the AI boundary. `AiContextSchema` allowlists what may leave
a tenant; a rich User model sitting behind it is a standing invitation for
someone to append `$user->email` to a prompt payload. Four readonly fields cannot
leak a fifth.

```php
public function lookupMany(int $tenantId, array $userIds): array
{
    $rows = <PLACEHOLDER: fetch id, name, role for these ids IN THIS TENANT>;

    return collect($rows)->mapWithKeys(fn ($row) => [
        (int) $row->id => new SireUserIdentity(
            id: (int) $row->id,
            tenantId: $tenantId,
            displayName: $row->name,
            role: $row->role,
        ),
    ])->all();
}
```

`lookupMany` exists because a 200-entry timeline names perhaps six distinct
people. Without it that is 200 queries.

**Tenant-scope the lookups.** An unscoped one turns a SIRE issue page into a way
to enumerate another tenant's staff.

**If your users are an ordinary Eloquent table**, you may not need this at all:
point `config('sire.tables.users')` at it and SIRE's own provider reads three
columns from it.

---

# 3. Authorization — `SireAuthorizationProvider`

> **FIND IN YOUR APP** — the permission check. A Gate, a policy,
> `hasPermission()`, or a role column.

| Method | Returns | Contract |
|---|---|---|
| `can(SireUserIdentity $user, string $capability, ?object $subject)` | `bool` | **False for unknown** — never throws |
| `roster(int $tenantId, string $roster)` | `int[]` | `leads` / `developers` / `qa` |

**SIRE owns the capability vocabulary; you own who holds what.** SIRE never asks
`$user->hasRole('your-qa-lead')` — it asks `can($user, 'sire.qa.execute')`.

Two granularities, and you may mix them:

**Canonical** — 22 capabilities, each tied to specific transitions. Full list in
[AUTHORIZATION.md](AUTHORIZATION.md).

**Coarse** — nine broad grants for blunter role systems. Granting one grants
everything beneath it:

| Coarse grant | Covers |
|---|---|
| `sire.view` | reading issues and exporting |
| `sire.create` | filing issues |
| `sire.update` | development and reopening |
| `sire.assign` | triage and assignment |
| `sire.qa` | executing QA |
| `sire.approve` | change review and approval |
| `sire.release` | release management and approval |
| `sire.settings` | masters and configuration |
| `sire.manage` | **everything** |

Mapping `sire.manage` to your administrator role is a complete, legitimate
integration. Most hosts start there.

**What this does NOT decide.** Whether a developer may push their own issue to
QA is a product rule and lives in `SireAccessService`. This provider answers only
"has the host granted this" and "who is in this role". An adapter that started
making product decisions would create two authorization systems that can
disagree — and the one behind the integration seam is the one nobody reads.

**Fail closed.** Returning false for everything is a valid working state: SIRE
falls through to its own role rosters and stays usable while you map permissions.

---

# 4. Notification — `SireNotificationProvider`

> **FIND IN YOUR APP** — whatever already sends "this was assigned to you".

| Method | Returns | Contract |
|---|---|---|
| `send(SireNotification $n)` | `void` | Must not throw |
| `sendMany(array $notifications)` | `void` | Batch — one mail transaction, a digest |
| `accepts(int $tenantId, int $userId, string $event)` | `bool` | **True when unknown** |

A `SireNotification` carries event, recipient, title, body, url, priority and
metadata. `url` is SIRE-relative (`/app/sire/cases/412`) — only you know your
domain and deep-link scheme.

```php
public function sendMany(array $notifications): void
{
    try {
        foreach ($notifications as $n) {
            <PLACEHOLDER: your notification dispatch>($n->recipientId, $n->title, $n->body, $n->url);
        }
    } catch (\Throwable $e) {
        report($e);   // delivery must never fail a workflow transition
    }
}
```

**Do not re-derive the audience.** By the time SIRE calls you it has already
removed the actor, de-duplicated recipients, collapsed low-value events into a
one-hour window and suppressed repeat SLA alarms. Recomputing from the payload
double-sends.

The 20 events and their payloads are in [NOTIFICATIONS.md](NOTIFICATIONS.md).

---

# 5. Attachment — `SireAttachmentProvider`

> **FIND IN YOUR APP** — the file service, with its disk, limits and scanning.

| Method | Returns |
|---|---|
| `store(object $owner, UploadedFile $file, SireUserIdentity $user, array $meta)` | `SireAttachment` |
| `listFor(object $owner)` | `SireAttachment[]` |
| `find(int\|string $attachmentId)` | `?SireAttachment` |
| `delete(int\|string $attachmentId, SireUserIdentity $user)` | `void` |

A `SireAttachment` carries id, name, size, mime, url, createdAt, uploadedBy —
and **no disk name, storage key or filesystem path**. SIRE neither needs them nor
should be able to leak them.

**Your implementation must enforce**: tenant isolation, authorization, size and
**sniffed** mime type (an `accept` attribute and a Content-Type header are both
client hints), safe filenames, and no arbitrary path access. Treat the id as an
opaque handle — if ids are paths, `../../.env` is a valid attachment id.

**Until you bind it**, SIRE stores files on a Laravel disk under
`sire/{tenant}/{type}/{id}/` with no table at all. Ownership there is structural:
a listing only ever reads one tenant's directory.

---

# 6. Audit — `SireAuditProvider`

> **FIND IN YOUR APP** — an audit trail, if you have one.

| Method | Returns |
|---|---|
| `record(SireAuditEvent $event)` | `void` — must not throw |
| `recordMany(array $events)` | `void` |
| `for(string $subjectType, int $subjectId, int $tenantId, int $limit)` | `SireAuditEvent[]` |

**Fully optional.** SIRE keeps its own write-once `sire_audit_events` table.
Implement this only to have SIRE's history appear alongside everything else in
your product.

**There is no `update` and no `delete`, and adding one defeats the purpose.**
Release approvals and emergency overrides are defended by this trail. SIRE's own
model throws on both, no endpoint reaches it, and a test asserts no such route
exists.

**Writes must not throw.** A transition that succeeded with a failed audit write
beats a transition that could not happen.

Comments are the opposite case and go through the notes provider, where the
author may edit them. The timeline merges both and tags each `kind` so the
difference stays visible on screen.

---

# 7. Notes — `SireNotesProvider`

> **FIND IN YOUR APP** — a notes or comments system, if you have one.

| Method | Returns |
|---|---|
| `add(object $subject, string $body, SireUserIdentity $author, bool $internal)` | `SireNote` |
| `listFor(object $subject, ?SireUserIdentity $viewer, int $limit)` | `SireNote[]` |
| `find(int\|string $noteId)` | `?SireNote` |
| `update(int\|string $noteId, string $body, SireUserIdentity $user)` | `SireNote` |
| `delete(int\|string $noteId, SireUserIdentity $user)` | `void` |

**Fully optional.** SIRE keeps its own `sire_notes` table.

**`find()` must return `subjectType`, `subjectId` and `tenantId`.** Note ids are
a global sequence, and SIRE uses those three to check that the note in a URL
really belongs to the report in that same URL. Without them,
`/sire/reports/1/comments/999` edits a note attached to an invoice in another
tenant.

Return `updatedAt` **only when the note was genuinely edited** — SIRE renders an
"edited" marker from its presence.

---

# 8. Settings — `SireSettingsProvider`

> **FIND IN YOUR APP** — a per-tenant settings store, if you have one.

| Method | Returns |
|---|---|
| `get(int $tenantId, string $key, mixed $default)` | `mixed` — **must not throw** |
| `set(int $tenantId, string $key, mixed $value)` | `void` |
| `all(int $tenantId, string $prefix)` | `array` |
| `forget(int $tenantId, string $key)` | `void` |

**Fully optional.** SIRE keeps its own `sire_settings` table.

All SIRE keys live under `sire.*`. Values include arrays — SLA policies, release
gates, role rosters — so round-trip structures, not just scalars.

**Never throw on read.** A missing settings backend means SIRE runs on documented
defaults, all of which are the safe choice: SLA off, AI off, gates on.

---

# 9. Numbering — `SireNumberingProvider`

> **FIND IN YOUR APP** — whatever produces `INV-2026-0041`.

| Method | Returns |
|---|---|
| `next(int $tenantId, string $series)` | `string` — unique per tenant, **never reused** |

Series: `sire_report`, `sire_recurrence`.

**Fully optional.** SIRE allocates `SIR-000412` itself, and that is the expected
choice. Implement this only to have SIRE references come from the same allocator
as your invoices.

**Concurrency is the whole problem.** Two people clicking Report Issue in the
same second is ordinary. `MAX(number) + 1` is the obvious implementation and it
is wrong. Gaps are fine; reuse is not.

---

# 10. SLA — `SireSlaProvider`

> **FIND IN YOUR APP** — a service-level configuration screen, if one exists.

| Method | Returns |
|---|---|
| `policies(int $tenantId)` | `array[]` — `[]` means "not configured" |
| `warningThreshold(int $tenantId)` | `float` strictly between 0 and 1 |
| `pauseStates(int $tenantId)` | `string[]` |
| `businessCalendar(int $tenantId)` | `?array` — null means a 24/7 clock |

**You almost certainly should not implement this.** SIRE owns SLA behaviour
outright — which policy wins, when the clock pauses, how warning and breach are
derived, how alerts dedupe. All of it is in `SireSlaService`, verified by a
15-case decision table.

This supplies **inputs only**, for a host that already has a service-level screen
and wants SIRE to read from it. Otherwise configure SLA through `sire.sla.*`
settings and leave this alone.

A policy row:

```php
['match' => ['type' => 'bug', 'severity' => 'critical'],  // any subset
 'ack_minutes' => 30, 'resolve_minutes' => 480]
```

Most-specific match wins; ties break toward the earlier row. Returning `[]` makes
SIRE report every issue ON_TRACK with no target — the right reading of "not
configured". Inventing a default deadline would breach issues against a target
nobody agreed to.

---

# 11. Knowledge — `SireKnowledgeProvider`

> **FIND IN YOUR APP** — the knowledge base.

| Method | Returns |
|---|---|
| `find(int $tenantId, int\|string $articleId)` | `?SireKnowledgeArticle` |
| `search(int $tenantId, string $query, int $limit)` | `SireKnowledgeArticle[]` — **tenant-scoped** |
| `createDraft(int $tenantId, array $attributes, SireUserIdentity $author)` | `SireKnowledgeArticle` — **never publishes** |
| `isAvailable()` | `bool` — drives what the UI offers |

**The second silent one.** Every knowledge call in SIRE is wrapped so a missing,
slow or misconfigured KB degrades to "no related articles" rather than breaking
an issue page. The cost is that "no KB connected" and "connected but wrong" look
identical.

**Verify with a search you know should hit** — not by the absence of errors.

**Issue↔article links work regardless.** SIRE stores those itself, so a team can
record "this issue is explained by article 412" even with no searchable KB. Only
search and drafting go quiet.

`search()` surfaces article titles directly to the user, so an unscoped search
leaks another tenant's titles into a SIRE panel.

---

# 12. Version — `SireVersionProvider`

> **FIND IN YOUR APP** — wherever the deployed version is known.

| Method | Returns |
|---|---|
| `current(int $tenantId)` | `?string` |
| `environment()` | `?string` — production / staging / local |
| `known(int $tenantId)` | `string[]` — newest first |
| `normalize(?string $raw)` | `?string` — **null for anything unrecognisable** |

**Optional.** SIRE falls back to `config('sire.version')`, then `APP_VERSION`,
then null.

SIRE tracks four version fields per issue: `detected_version` (automatic),
`affected_version`, `fixed_version`, `released_version`. Only the first comes
from here, and it must be automatic — asking a user which build they were on is
asking them to go and look, and they will guess.

Return null rather than guessing. A null version is honest; a wrong one poisons
every release dashboard.

---

# 13. Context — `SireContextProvider`

> **FIND IN YOUR APP** — the route registry, or wherever screens are declared.

| Method | Returns |
|---|---|
| `resolve(string $path)` | `SireScreenContext` |
| `modules()` | `string[]` |
| `moduleLabel(string $module)` | `string` |
| `routeMap()` | `array[]` |

**This is what makes Report Issue one click instead of a form.** When a user
reports a problem, SIRE must already know which module, section and screen they
were on and which record they were looking at.

**Two ways to answer, and declaring beats inferring.**

**Declare** — the strongest option, and it needs no PHP at all. From your SPA:

```js
import { SireContext } from './context/SireContextProvider';

const done = SireContext.register({
  module: 'sales',
  section: 'leads',
  screen: 'lead-details',
  entityType: 'lead',
  entityId: 10452,
});

// when the screen goes away:
done();
```

or declaratively:

```jsx
<SireContext module="sales" section="leads" screen="lead-details"
             entityType="lead" entityId={lead.id} />
```

A screen that names itself is authoritative in a way no pattern match can be, and
it is the only way to describe a wizard whose step lives in component state.

**Infer** — a route map, as data, with no code change:

```php
// config/sire.php
'route_map' => [
    ['pattern' => '/app/sales/leads/:id', 'module' => 'sales',
     'section' => 'leads', 'screen' => 'lead-details', 'entity_type' => 'lead'],
],
```

Leave it empty and SIRE uses the map generated from
`resources/js/lib/sire/contextRoutes.js`, which ships **seeded, not observed**.
Reconcile it:

```bash
node tools/sire-route-audit.mjs <path-to>/src/app/routes.jsx
node tools/generate-route-map.mjs      # after editing the JS map
```

**The server is the authority.** The browser resolves context first so the modal
opens already filled in, but a client can post any module it likes — what gets
stored is what `resolve()` derives from the submitted path.

**No match is a supported outcome.** Confidence drops to `low`, the modal shows
an editable context, and the report still submits in one click. **Never block a
report because a route is unmapped** — a user who cannot report a problem is far
worse than an issue filed against an unknown screen.

---

## The one remaining assumption

| Assumption | Used for | If yours differs |
|---|---|---|
| `App\Http\Controllers\Controller` exists | Base class for 17 SIRE controllers | Change the `use` line |

That is the entire list. SIRE owns its tenant scoping, audit recording, notes,
settings, numbering, identity, API envelope, ownership guard and exception type,
so none of them has to match anything in your codebase.

`config('sire.tables.users')` and `config('sire.tables.tenants')` let SIRE's own
providers read your users and tenants tables directly — two config entries
instead of two implementations, if your schema is ordinary.
