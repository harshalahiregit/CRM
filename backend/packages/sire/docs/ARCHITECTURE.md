# Architecture

## Four layers, enforced

```
  layer  2   ai            gateway · redaction · providers · suggestion store
                           ↑ may READ everything below · nothing may reference it
  layer  1   quality       knowledge      release            ← peers
                           ↑ may reference core and each other
  layer  0   core          workflow · timeline · access · SLA · notifications
                           ↑ may reference only itself and the layer below
  layer -1   integration   the SDK: 13 contracts, 13 shipped implementations,
                           9 value objects. Depends on NOTHING in SIRE;
                           everything may depend on it.
```

Both directions are checked by `tests/module-boundaries.test.mjs`, which reads the
map out of `src/Support/SireModule.php` and scans every class's
imports — **including fully-qualified inline references**, because an import-only
check can be bypassed by omitting the import.

Two load-bearing consequences fall out of the shape:

- **Delete `app/Services/Sire/Ai/` entirely and SIRE still compiles, still runs,
  still ships releases.**
- **Rewire any host subsystem without reading a single SIRE service.** Layer -1
  cannot reach upward, so a provider never depends on the workflow it serves.

`integration` is not an escape hatch that legitimises any dependency. It holds
the fourteen contracts, their fourteen implementations, the value objects they
exchange and the SIRE-owned traits — every one listed by name in
`SireModule::CLASS_MODULE`. A service trying to hide there has to be added to
that map by hand, in the one file whose purpose is being read during review.

A second check proves the reverse direction: no class in the AI layer may call
`save`/`update`/`create`/`delete` on anything but its own tables. That is what
makes "AI never overwrites issue data" a property of the architecture rather than a
rule someone has to remember.

## How AI reaches the timeline without core knowing AI exists

The interesting problem. Core renders the timeline; AI wants to appear on it; core
must not reference AI.

Dependency inversion. Core owns `Contracts\Sire\TimelineContributor`;
`SireTimelineService` accepts an iterable of them, defaulting to none; the AI layer
implements one and it is bound in the container. Core imports the **interface**,
never an implementation. Unbind the contributor and the timeline is exactly what it
was before AI existed.

## Request path

```
React SPA
  └─ services/sireApi.js  →  <HOST_API_CLIENT> (axios + bearer token)
       ↓ HTTPS
  middleware: auth:sanctum → role:admin,staff        ← identity and role only
       ↓
  Api\Sire\* controllers                             ← validate, resolve tenant, delegate
       ↓ int $tenantId, passed explicitly
  Services\Sire\*                                    ← ALL business logic
       ↓ ->forTenant($tenantId) on every read        ← the primary isolation boundary
  Models\Sire\* (BelongsToSireTenant — an OPT-IN scope, not a global one)
       ↓
  MySQL, shared tables, tenant_id NOT NULL           ← no row-level security
```

Tenancy is enforced at the **service query layer** and, for route-bound models, the
controller. It is enforced at neither the middleware nor the ORM nor the database —
which is why every read chains `->forTenant()` by hand and why a static scan checks
that it does.

## The Integration SDK

SIRE talks to its host through **fourteen contracts and nothing else**:

| Contract | Answers | Optional? |
|---|---|---|
| `SireTenantProvider` | Which tenant is this, and does this record belong to it? | recommended |
| `SireUserProvider` | Who is this, and what is this user called? | recommended |
| `SireAuthorizationProvider` | Has the host granted this capability? Who is in this role? | optional |
| `SireNotificationProvider` | Deliver this to these people | recommended |
| `SireAttachmentProvider` | Store, list and remove evidence | optional |
| `SireAuditProvider` | Record and read system events | **optional** |
| `SireNotesProvider` | Record and read human comments | **optional** |
| `SireNumberingProvider` | Allocate the next reference | **optional** |
| `SireSettingsProvider` | Read and write per-tenant configuration | **optional** |
| `SireSlaProvider` | What are this tenant's SLA policies? | **rarely** |
| `SireKnowledgeProvider` | Find, search and draft knowledge articles | optional |
| `SireVersionProvider` | What version is deployed? | optional |
| `SireContextProvider` | What screen is this path? | recommended |
| `SireCustomerProvider` | Which customer does this issue affect? | optional |

Each ships a `SireLocal*` implementation SIRE owns, so **SIRE runs before any
integration work at all**; each has a `Host*` stub in `examples/host-adapters/`.
The binding is one key in `config/sire.php`.

Six are optional in the strong sense: SIRE has its own tables and engines for
audit, notes, settings, numbering, SLA and knowledge links. Leaving them alone is
a supported permanent state, not a half-finished installation.

### Contracts exchange value objects, not host models

Binding to interfaces buys nothing if those interfaces pass `\App\Models\User`
across the boundary — the host model is still in SIRE's signatures, and
everything it carries is still reachable. So the SDK defines its own:

```
SireUserIdentity     id · tenantId · displayName · role
SireTenantIdentity   id · name
SireNotification     event · recipient · title · body · url · priority
SireAuditEvent       subject · action · actor · before · after · metadata
SireNote             subject · body · author · internal · edited
SireAttachment       id · name · size · mime · url          (no disk, no path)
SireKnowledgeArticle id · title · excerpt · url · status
SireScreenContext    module · section · screen · entity · confidence
SireCapability       the capability vocabulary
```

`SireUserIdentity` is four fields because that is genuinely all SIRE needs across
22 workflow states, SLA, QA, releases and 13 AI capabilities. It matters most at
the AI boundary: `AiContextSchema` allowlists what may leave a tenant, and a rich
User model behind it is a standing invitation to append `$user->email` to a
prompt payload. Four readonly fields cannot leak a fifth.

`tests/sdk.test.mjs` fails the build if any contract references `App\Models\*`.

### Why interfaces rather than direct calls

SIRE was written without access to the host it would install into. A direct call
to `NotificationEngine::dispatch()` encodes a signature nobody could verify, and
being wrong means editing SIRE's services. An interface encodes only what SIRE
*needs* — which is knowable — and being wrong means editing one class you own.

The same reasoning is why SIRE owns `BelongsToSireTenant`, `RecordsSireAudit`,
`SireApiResponse`, `AssertsSireTenantOwnership`, `ResolvesSireUser` and
`SireException`, and why it ships `sire_settings`, `sire_notes` and
`sire_audit_events`: every one of those was an assumption about a host it could
not check, and each is now a file in this package.

**The result is one remaining host symbol**: the framework's base `Controller`,
to extend. That is enforced, not aspirational — `tests/sdk.test.mjs` scans every
SIRE file and fails the build on any other host import.

## What SIRE reuses, and what it owns

| Concern | Reused from the host, when it has one | SIRE's own fallback |
|---|---|---|
| Evidence files | `SireAttachmentProvider` | a Laravel disk, no table |
| Comments | `SireNotesProvider` | `sire_notes` |
| History | `SireAuditProvider` | `sire_audit_events` |
| Notifications | `SireNotificationProvider` | the log — SIRE never mails uninvited |
| Configuration | `SireSettingsProvider` | `sire_settings` |
| References | `SireNumberingProvider` | `SIR-000412`, allocated under a row lock |
| Knowledge | `SireKnowledgeProvider` | links only; no second knowledge base |
| Identity | `SireUserProvider` | reads the configured users table |
| Tenancy | `SireTenantProvider` | `auth()->user()->tenant_id` |

**Exactly one implementation of each is ever bound.** Binding a host provider
leaves SIRE's table empty; there is never a second system holding real data, and
`php artisan sire:doctor` prints which is which so a half-migrated state is
visible rather than discovered.

### What SIRE will never build

A ticket system, a knowledge base, a customer-facing SLA, an attachment store, a
notification platform, a tenant system or an RBAC layer. Those are the host's,
and SIRE linking to a Helpdesk ticket through `related_type`/`related_id` is the
whole of its involvement.

The fallbacks above are a different thing: small, single-purpose stores that exist
so SIRE does not require four host subsystems before it will start. A host that
has them connects them and SIRE's tables stay empty forever.

## Background work

**Nothing is queued.** The discovery report records no supervisor, no Horizon and
no worker in production — a dispatched job would either run inline on a request
thread or sit in the jobs table forever.

All asynchronous work is two idempotent scheduled commands, both optional, both
chunked, both deriving tenant per row, both logging one greppable summary line.

## Phases

| Phase | Delivers |
|---|---|
| 0 | Report Issue, automatic context capture, screenshots |
| 1 | Engineering workflow, developer/QA views, SLA, notifications, dashboard |
| 2 | Duplicates, root cause, releases, change approval, regression, recurrence, KB, release notes |
| 3 | Release governance; AI foundation and 13 local capabilities |

They are cumulative, and each phase's schema arrives with the code that uses it —
a partial deploy leaves a coherent table rather than a half-populated one.
