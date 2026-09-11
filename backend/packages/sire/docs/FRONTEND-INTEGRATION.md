# Frontend integration

## Layout

```
resources/js/
  components/sire/        ReportIssueRoot, ReportIssueModal, ContextPreview
  context/                SireContextProvider
  lib/sire/               context capture, tokens, workflow mirror, saved views
  modules/sire/           pages + components
  services/sireApi.js     every SIRE fetch call
```

## Three edits to existing host files

All additive. Everything else is new files.

### 1. `<HOST_API_CLIENT>` — one line

```diff
+import { recordFailedRequest } from './sire/requestLog';

 api.interceptors.response.use(
   (response) => response,
   (error) => {
+    recordFailedRequest(error);   // metadata only; never throws, never alters the rejection
     if (isSessionFailure(error)) { clearAuth(); window.location.assign('/login'); }
     return Promise.reject(error);
   },
 );
```

**First statement** in the error branch, so a failure is still recorded when the
session-failure path redirects.

### 2. `<HOST_APP_ROOT>` — wrap the tree

Inside the auth provider (so the user is known) and inside the router (so
navigation is live):

```jsx
<AuthProvider><BrowserRouter>
  <SireContextProvider><AppRoutes /></SireContextProvider>
</BrowserRouter></AuthProvider>
```

### 3. `<HOST_AUTHENTICATED_LAYOUT>` — one line

```jsx
<main>{children}</main>
<ReportIssueRoot />
```

That single line is what makes Report Issue available from every CRM screen. In the
layout rather than `App.jsx` so it stays off login and public portals.

Then add SIRE's routes to `<HOST_ROUTE_REGISTRY>` and one sidebar entry.

---

## The route map — the one thing you must fix

`lib/sire/contextRoutes.js` maps routes to module/section/screen/entity so Report
Issue can say *"Sales / Leads / Lead Details / Lead #10452"* without asking.

> **The shipped entries are SEEDED, NOT REAL.** They were inferred from a module
> inventory, never read from your `routes.jsx`. Expect wrong entries until you
> regenerate the map against your own route tree — in this repository that has
> been done, and the map holds 359 patterns across 27 modules read from
> `frontend/src/app/routes.jsx`.

```bash
node tools/sire-route-audit.mjs <path-to>/src/app/routes.jsx
```

It prints **MISSING** (real routes with no mapping), **RELATIVE** (nested children
whose parent it cannot know) and **STALE** (mappings matching no route).

An unmapped route is **not broken**: detection falls back to the module prefix, so
the module is still right, confidence drops to `low`, and the user is offered a
correction. Fixing the map is a one-file edit.

Adding a screen later is one line:

```js
{ pattern: '/app/sales/forecast', module: 'sales', section: 'forecast', screen: 'forecast-board' },
```

## Or skip the map: let screens declare themselves

Route inference is free and covers most screens. Some it cannot describe at all —
a wizard whose step lives in component state, a console whose entity is chosen in
a dropdown, a modal that is really a page. Those declare themselves, and a
declaration always beats inference.

**Imperatively, from anywhere** — a component, a route guard, a saga, a legacy
island:

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

**Call the returned function.** An un-popped entry means a user who has navigated
to Billing files an issue against Leads — with high confidence, and no sign
anything went wrong.

**Declaratively**, if you are already in a component tree:

```jsx
<SireContext module="sales" section="leads" screen="lead-details"
             entityType="lead" entityId={lead.id} />
```

Both feed the same stack, and the innermost declaration wins. The registry
(`lib/sire/screenContext.js`) is deliberately framework-free: not every host
routes through React, and telling those hosts to restructure their application
to suit SIRE is not an integration story.

Registering costs nothing until it is used — it mutates a module-level array and
notifies nobody. Report Issue reads the stack at the moment it opens, and not
before.

| | |
|---|---|
| `SireContext.register({...})` | declare; returns an unregister function |
| `SireContext.current()` | the innermost declaration |
| `SireContext.all()` | the whole stack — for finding cleanup bugs |
| `SireContext.clear()` | drop everything, for hosts that route imperatively |

---

## Report Issue must stay fast

```
Click → context detected → describe → optional screenshot → submit → SIRE ID
```

**Two required fields** — a title and a description. `tests/plug-and-play.test.mjs`
asserts exactly that, naming severity, category, priority, module, screenshot,
assignee and environment as things that must never gate submission.

Every extra required field is a person deciding not to report the bug, and the
additions are always well-meant. Keep it at two.

## One visual vocabulary

`lib/sire/tokens.js` is the **only file in SIRE that names a colour**. Status,
severity, priority, SLA, risk, test result, release state, similarity and gate
outcome all resolve through it.

```jsx
const t = priorityToken(issue.priority);
<span className={chipClasses(t, 'sm')}>
  {t.marker && <span aria-hidden>{t.marker}</span>}
  {t.label}
</span>
```

**Urgency is never colour alone.** Anything at urgency ≥ 2 carries a marker glyph
and heavier weight, so it survives a colour-blind reader, a greyscale print and a
dense table skimmed at speed. Severity urgency derives from a severity's position
in *your tenant's* scale, so the top band is loudest whatever it is called.

Check the shades against `<HOST_TAILWIND_CONFIG>` — the families are the ones the
report says the CRM uses, but the shades assume a default palette.

## Saved views

localStorage, labelled **"this browser only"** in the UI. The CRM has no per-user
preference store — `tenant_settings` is per tenant and `users` has no preferences
column. When one exists, `lib/sire/savedViews.js` is the only file that changes.

Its keys are namespaced by list, **not by user**: on a shared browser two accounts
see each other's presets. Harmless (filter presets) but worth prefixing with a user
id once one is available client-side.
