# Adapters

**SIRE runs before you write a single one.** All fourteen contracts ship with an
implementation SIRE owns. This page is for connecting your systems when you want
to — not a checklist to finish before SIRE works.

Full method-by-method reference: [HOST-INTEGRATION.md](HOST-INTEGRATION.md).

## The fourteen

| Contract | Answers | Optional? |
|---|---|---|
| `SireTenantProvider` | Which tenant is this? | **Configure it** |
| `SireUserProvider` | Who is this? | **Configure it** |
| `SireAuthorizationProvider` | Has the host granted this capability? | Recommended |
| `SireNotificationProvider` | Deliver this to these people | Recommended |
| `SireContextProvider` | What screen is this path? | Recommended |
| `SireAttachmentProvider` | Store and list evidence | Optional |
| `SireAuditProvider` | Record and read system events | **Fully optional** |
| `SireNotesProvider` | Record and read comments | **Fully optional** |
| `SireNumberingProvider` | Allocate the next reference | **Fully optional** |
| `SireSettingsProvider` | Per-tenant configuration | **Fully optional** |
| `SireSlaProvider` | This tenant's SLA policy | **Rarely** |
| `SireKnowledgeProvider` | Find and draft articles | **Fully optional** |
| `SireVersionProvider` | What version is deployed? | **Fully optional** |
| `SireCustomerProvider` | Which customer does this issue affect? | **Fully optional** |

**Six are optional in the strong sense**: SIRE owns tables and engines for
audit, notes, settings, numbering, SLA and knowledge links. Never connecting
them is a supported permanent state, not a half-finished installation.

**Two need configuration rather than code.** Tenancy and identity are usually a
config strategy, not an implementation — see [TENANCY.md](TENANCY.md).

## Four states, and the doctor reports which

SIRE ships no adapter that pretends an integration exists.

| State | Meaning |
|---|---|
| **DEFAULT** | SIRE's own implementation is bound and working |
| **HOST-CONFIGURED** | Your class is bound |
| **OPTIONAL** | Nothing bound, nothing needed — the feature degrades by design |
| **UNAVAILABLE** | Bound but failing — the doctor says why |

```bash
php artisan sire:doctor
```

prints the state of all fourteen. "SIRE is installed" and "SIRE is connected to
this application" are different things that look identical from the UI, and this
is where you see the difference.

## Writing one

```bash
cp examples/host-adapters/HostAuditProvider.php app/Sire/Host/
```

Every stub has every method, each throwing until you implement it. That is
deliberate: a stub returning an empty array would look finished and render as
"nothing here", while one that throws names itself and the config key to revert
to.

Implement, then bind:

```php
// config/sire-host.php
'providers' => [
    'audit' => \App\Sire\Host\HostAuditProvider::class,
],
```

Nothing else changes. No SIRE service, controller or model.

## Order

1. **Tenant** — before anything else. The only silent failure.
2. **User** — names on timelines, assignment pickers.
3. **Authorization** — if you have a permission system worth using.
4. **Notification** — SIRE decides *who*; you decide *how*.
5. Everything else, whenever.

## Contracts exchange value objects

Binding to interfaces buys nothing if those interfaces pass `\App\Models\User`
across the boundary. So the SDK defines its own:

```
SireUserIdentity     id · tenantId · displayName · role
SireTenantIdentity   id · name
SireNotification     event · recipient · title · body · url · priority
SireAuditEvent       subject · action · actor · before · after · metadata
SireNote             subject · body · author · internal · edited
SireAttachment       id · name · size · mime · url        (no disk, no path)
SireKnowledgeArticle id · title · excerpt · url · status
SireScreenContext    module · section · screen · entity · confidence
```

`tests/sdk.test.mjs` fails the build if any contract references `App\Models\*`.

## What SIRE will never build

A ticket system, a knowledge base, a customer-facing SLA, an attachment store, a
notification platform, a tenant system or an RBAC layer. Those are yours.

The three fallback tables are a different thing: small, single-purpose stores so
SIRE does not require four of your subsystems before it will start. Connect
yours and they stay empty forever — exactly one implementation of each is ever
bound, so there is never a second system holding real data.
