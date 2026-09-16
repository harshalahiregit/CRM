# Installation

The complete reference. For a gentler walk-through of the same route, use
[JUNIOR-DEVELOPER-START-HERE.md](JUNIOR-DEVELOPER-START-HERE.md).

Installation is **command-driven**. SIRE inspects your application, applies what
it can verify, and asks about the two things it must never guess.

```bash
composer require sangoe/sire
php artisan sire:compatibility
php artisan sire:discover
php artisan sire:install
php artisan sire:doctor
```

---

## Before you start

```bash
php -v && php artisan --version    # PHP 8.2+, Laravel 10+
```

**Take a database backup.** SIRE creates only its own tables, but you are about
to run migrations on a real database.

Read [COMPATIBILITY.md](COMPATIBILITY.md) if your stack is unusual — a **MANUAL**
verdict means one small adapter, not a rejection.

---

# 1 · Install the package

## Composer (preferred)

```bash
composer config repositories.sire path /path/to/SIRE-v1.1-PLUG-AND-PLAY
composer require sangoe/sire:*
```

Laravel package discovery registers `Sire\SireServiceProvider` automatically.

## Copy-install

```bash
cp -r SIRE-v1.1-PLUG-AND-PLAY packages/sire
```

```json
"autoload": { "psr-4": { "Sire\\": "packages/sire/src/" } }
```

```bash
composer dump-autoload
```

Then register the provider by hand — `bootstrap/providers.php` on Laravel 11/12,
`config/app.php` on Laravel 10:

```php
Sire\SireServiceProvider::class,
```

---

# 2 · Compatibility

```bash
php artisan sire:compatibility
```

| Verdict | Meaning |
|---|---|
| **SUPPORTED** | Nothing to do |
| **CONDITIONAL** | Works, with a stated caveat |
| **MANUAL** | Works once you write one adapter — **not a failure** |
| **UNSUPPORTED** | Blocks installation. Rare. |

---

# 3 · Discovery

```bash
php artisan sire:discover        # read-only
php artisan sire:host-profile    # readable, redacted
```

Reads your schema, container, config and filesystem. Writes one file to
`storage/app/sire/host-profile.json`. Modifies nothing.

Every finding carries a **confidence** and its **evidence**, so you can judge
whether discovery was right rather than taking its word.
[DISCOVERY.md](DISCOVERY.md).

---

# 4 · Install

```bash
php artisan sire:install
php artisan sire:install --dry-run       # preview
php artisan sire:install --reconfigure   # re-ask everything
```

It writes `config/sire-host.php`, publishes what needs publishing, and runs
migrations. It **asks** about anything security-sensitive.

## The tenancy question

> Which column identifies the **tenant** — not the customer a user works for?

**The only failure in SIRE with no symptoms.** A wrong answer returns one
customer's issues to another, on a page that renders perfectly normally.

| Strategy | When |
|---|---|
| `user_attribute` | A column on your users table |
| `relationship` | A relation to a tenant model |
| `resolver` | A container service that knows |
| `callable` | Subdomain, header or middleware |
| `single_tenant` | Not multi-tenant at all |

Discovery never reports a tenant column as HIGH confidence, and the installer
asks regardless. [TENANCY.md](TENANCY.md).

## The login-type question

> Which of **your** role names are ADMIN, INTERNAL_USER, CUSTOMER, VENDOR?

The first two get SIRE access. The last two are blocked. **A role in no list
gets nothing** — unmapped means denied, because the opposite failure mode is a
customer reading unfixed-bug reproduction steps.

Matching ignores case and separators, so `Super Admin` and `super_admin` are the
same role.

## Non-interactive installs

```bash
php artisan sire:install --non-interactive --answers=sire-answers.json
```

A missing security-sensitive answer **aborts**. It does not fall back to a
default — that is the entire point of the confirmation gate.

---

# 5 · Migrations

The installer runs them. To check first:

```bash
php artisan migrate:status
php artisan migrate --pretend
```

28 migrations, 22 tables, every one prefixed `sire_`. All reversible, each
dropping only its own table. **No migration alters a table SIRE does not own.**

Three tables — `sire_settings`, `sire_notes`, `sire_audit_events` — are the SDK
fallbacks. They stay empty if you connect your own providers.

---

# 6 · Verify the backend

```bash
php artisan sire:doctor
```

Every check names what it looked at, why it matters, and how to fix it. It also
reports the state of all fourteen contracts:

| | |
|---|---|
| **DEFAULT** | SIRE's own implementation is working |
| **HOST-CONFIGURED** | Your class is bound |
| **OPTIONAL** | Nothing bound, nothing needed |
| **UNAVAILABLE** | Bound but failing — it says why |

"SIRE is installed" and "SIRE is connected to this application" look identical
from the UI. This is where you see the difference.

---

# 7 · Frontend (React)

Skip this entirely if you have no React. The Blade widget and the API both work.

```bash
php artisan vendor:publish --tag=sire-frontend
```

## The host bridge — optional, but this is what makes SIRE look like your product

```js
// once, at bootstrap
import { SireHost } from './lib/sire/host';

SireHost.configure({
  api,                                     // your axios instance
  ui: { Button, Modal, DataTable, ... },   // your components
  toast: useToast,                         // your notifications
});
```

**Every field is optional and partial registration is normal.** SIRE ships
accessible fallbacks for all six components it renders, so it works before you
register anything — it just looks plainer.
[FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md).

## Report Issue

Two lines. The mount goes in your **authenticated layout**, not `App.jsx`, so it
stays off the login screen and public portals:

```jsx
<SireContextProvider><AppRoutes /></SireContextProvider>
```

```jsx
<main>{children}</main>
<ReportIssueRoot />
```

Plus one line in your API client's error branch — first in the branch, so a
failure is still captured when a session-expiry path redirects away:

```jsx
recordFailedRequest(error);   // metadata only; never throws, never alters the rejection
```

Shown in full in [`../examples/02-frontend-wiring.jsx`](../examples/02-frontend-wiring.jsx).

## Routes

Nine pages, added to your existing authenticated route table:

```jsx
import DashboardPage            from './modules/sire/pages/DashboardPage';
import MyWorkPage               from './modules/sire/pages/MyWorkPage';
import IssueDetailPage          from './modules/sire/pages/IssueDetailPage';
import QualityDashboardPage     from './modules/sire/pages/QualityDashboardPage';
import ReleaseBoardPage         from './modules/sire/pages/ReleaseBoardPage';
import ReleaseDetailPage        from './modules/sire/pages/ReleaseDetailPage';
import RecurrenceDetailPage     from './modules/sire/pages/RecurrenceDetailPage';
import ReleaseNotesPage         from './modules/sire/pages/ReleaseNotesPage';
import EngineeringInsightsPage  from './modules/sire/pages/EngineeringInsightsPage';

{ path: 'sire',                 element: <DashboardPage /> },          // register + tiles
{ path: 'sire/my-work',         element: <MyWorkPage /> },             // developer + QA queues
{ path: 'sire/cases/:id',       element: <IssueDetailPage /> },
{ path: 'sire/quality',         element: <QualityDashboardPage /> },
{ path: 'sire/releases',        element: <ReleaseBoardPage /> },
{ path: 'sire/releases/:id',    element: <ReleaseDetailPage /> },      // gates + override
{ path: 'sire/recurring/:id',   element: <RecurrenceDetailPage /> },
{ path: 'sire/release-notes',   element: <ReleaseNotesPage /> },
{ path: 'sire/insights',        element: <EngineeringInsightsPage /> },
```

**The paths under `/app/sire/` are not free choices.** SIRE components link to
each other directly — `IssueTable` to `sire/cases/:id`, `RecurringList` to
`sire/recurring/:id`, `ReleaseBoardPage` to `sire/releases/:id`. Mount them
elsewhere and those links render blank pages, which looks like "nothing
happened" rather than an error. `tests/frontend-imports.test.mjs` checks both
directions — no unrouted page, no link without a route.

`MyWorkPage` carries both queues, chosen by `?queue=development|qa`, so "my QA
work" is a URL somebody can send.

## Navigation

Add SIRE to your sidebar, visible to internal staff only. Reuse whatever
permission check the rest of your navigation already uses.

## Build

```bash
npm run build
```

---

# 8 · Verify by hand — **do not skip**

## Tenant isolation

The doctor cannot check this. Only you can.

1. Tenant A: create an issue, note the id
2. Tenant B: `GET /api/sire/reports/<id>`

| Result | Meaning |
|---|---|
| **404** | Correct |
| **200** | Tenant strategy is wrong — **stop and fix it** |
| **403** | Nearly right, but it confirms the record exists. Make it 404. |

Skip only if you answered `single_tenant`.

## The four login types

| Account | Expected |
|---|---|
| Admin | Full access |
| Internal user | Access, fewer actions |
| **Customer** | **403** |
| **Vendor** | **403** |

## One issue, end to end

Report → triage → assign → develop → QA → release.

```bash
php artisan sire:doctor    # once more, with real data present
```

---

# 9 · Optional, afterwards

## Connect your own subsystems

```bash
cp examples/host-adapters/HostAuditProvider.php app/Sire/Host/
```

Implement it, then name it in `config/sire-host.php`:

```php
'providers' => ['audit' => \App\Sire\Host\HostAuditProvider::class],
```

Nothing else changes. **Six are permanently optional** — audit, notes, settings,
numbering, SLA, knowledge base. [ADAPTERS.md](ADAPTERS.md).

## Reconcile the route map

Worth an hour. SIRE ships route→context patterns that were written rather than
read from your registry:

```bash
node tools/sire-route-audit.mjs resources/js/app/routes.jsx
```

It prints every real route with no pattern and every pattern matching nothing.
Edit `resources/js/lib/sire/contextRoutes.js`, then regenerate:

```bash
node tools/generate-route-map.mjs
```

**Do not hand-edit** `src/Support/SireRouteMap.php` — it is generated, and
`tests/route-map-sync.test.mjs` fails the build if the two disagree.

## Scheduler

Optional. Without it, SLA state is still computed on read — dashboards stay
correct and only proactive warning and breach notices are lost.

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## AI

**Off by default**, and SIRE is fully functional without it. All thirteen
capabilities compute locally with no vendor.

```php
settings()->set($tenantId, 'sire.ai.enabled', true);
settings()->set($tenantId, 'sire.ai.capabilities', ['classification', 'duplicates']);
```

```bash
php artisan sire:index-issues --tenant=<id>
```

Duplicate detection needs the index built first. [AI.md](AI.md).

---

# Rolling back

```bash
php artisan sire:uninstall              # disables SIRE, keeps every issue
php artisan sire:uninstall --dry-run    # preview
php artisan sire:uninstall --purge      # deletes data, after a typed confirmation
```

Disabling is the default and keeps all data. `--force` does **not** bypass the
purge confirmation, and no path touches a table without the `sire_` prefix.
[UNINSTALL.md](UNINSTALL.md) · [ROLLBACK.md](ROLLBACK.md).

---

# If something is wrong

```bash
php artisan sire:doctor           # what is broken, and how to fix it
php artisan sire:architecture     # is SIRE core still CRM-agnostic?
```

[TROUBLESHOOTING.md](TROUBLESHOOTING.md)
