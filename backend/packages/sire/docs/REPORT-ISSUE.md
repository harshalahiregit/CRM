# Report Issue

The feature everything else exists to serve. Get this wrong and nobody files
issues; get it right and your backlog reflects reality.

```
ANY SCREEN
    ↓  click Report Issue
SIRE ALREADY KNOWS
    module · section · screen · route · entity type · entity id
    entity label · app version · environment · browser · timestamp
    recent failed requests (metadata only)
    ↓
USER TYPES A TITLE AND WHAT HAPPENED     ← the only required fields
    ↓  optional: expected result, screenshot, priority
SUBMIT
    ↓
SIR-000412
```

**Two required fields** — a title (5 characters) and a description (10). A test
asserts it, naming severity, category, priority, module, screenshot, assignee and
environment as things that must never gate submission.

Every extra required field is a person deciding not to report the bug, and the
additions are always well-meant. Decision **D45** — keep it at two.

## Three levels of context detection

### Level 1 — the screen declares itself

The strongest, and it needs no PHP:

```js
import { SireContext } from './lib/sire/host';   // or the context provider

const done = SireContext.register({
  module: 'sales',
  section: 'leads',
  screen: 'lead-details',
  entityType: 'lead',
  entityId: 10452,
  entityLabel: 'Acme Corp — renewal',
});

// when the screen goes away
done();
```

Or declaratively:

```jsx
<SireContext module="sales" section="leads" screen="lead-details"
             entityType="lead" entityId={lead.id} />
```

**Call the returned function.** A stale entry means someone on Billing files an
issue against Leads — with high confidence, and nothing to indicate it is wrong.

The registry is framework-free, so a route guard, a saga or a legacy island can
declare context too. Not every host routes through React, and telling those
hosts to restructure their application to suit SIRE is not an integration story.

### Level 2 — the route map

Data, in config, with no code change:

```php
// config/sire-host.php
'route_map' => [
    ['pattern' => '/app/sales/leads/:id', 'module' => 'sales',
     'section' => 'leads', 'screen' => 'lead-details', 'entity_type' => 'lead'],
],
```

Patterns are matched most-specific-first, so `/projects/:id/tasks/:id` wins over
`/projects/:id`.

Reconcile the shipped map against your real routes:

```bash
node tools/sire-route-audit.mjs resources/js/app/routes.jsx
```

It prints every real route with no pattern and every pattern matching nothing.

### Level 3 — fallback

No match returns `confidence: low` with nulls, and the modal shows an editable
context:

```
Detected:
  Inventory
  Unknown section
  Unknown record          [Correct]
```

**Never block a report because a route is unmapped.** A user who cannot report a
problem is a far worse outcome than an issue filed against an unknown screen.

SIRE says `low` rather than pretending. Confidence is reported honestly:
`declared` → `high` → `medium` → `low`.

## The server is the authority

The browser resolves context first so the modal opens already filled in — but a
client can post any module it likes. What gets **stored** is what
`SireContextProvider::resolve()` derives from the submitted path.

## What is captured, and what never is

**Captured:** module, section, screen, route, entity type and id, app version,
environment, browser and OS, viewport, timestamp, and up to five recent failed
requests — method, path, status, timestamp, correlation reference.

**Never captured:** passwords, tokens, cookies, authorization headers, API
keys, secrets, private environment values, request bodies, query strings.

Redaction runs on capture, and again server-side on receipt. Path segments that
look like keys are masked by a length-plus-digit-density heuristic that
separates identifiers from human-readable slugs.

**Nothing is collected until the user clicks Report Issue** — with one bounded
exception. Failed API requests are recorded as they happen, because a failure
that has already occurred cannot be collected retroactively. That is
event-driven, never polled, capped at five, and metadata only.

## Screenshots

`navigator.mediaDevices.getDisplayMedia` — the browser's own picker. No
dependency, and its safety property is that the **user** chooses what is shared.

The modal closes for the capture and reopens with the draft preserved, so the
screenshot is of the problem rather than of the report form.

Optional. Always optional.

## Mounting it

One line in your authenticated layout — not in `App.jsx`, so it stays off the
login screen and public portals:

```jsx
<main>{children}</main>
<ReportIssueRoot />
```

Plus the provider around your routes, and one line in your API client's error
branch:

```jsx
recordFailedRequest(error);   // metadata only; never throws, never alters the rejection
```

First in the branch, so a failure is still captured when a session-expiry path
redirects away.

**No React?** The Blade widget and the API both work. The experience is thinner —
no screenshot, less context — but an issue filed with a description is worth far
more than one nobody filed.

## Why speed is a security property, sort of

An engineer who finds Report Issue slow files a message in a chat channel
instead. That message is not tenant-scoped, not audited, not searchable, and
frequently contains a screenshot with another customer's data in it.

The fast path is the safe path.
