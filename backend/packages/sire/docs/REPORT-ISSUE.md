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
    ↓  optional: category · how severe · how urgent · images · expected result
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

## The reporter triages

Category, severity and urgency are set by the **person hitting the bug**, in the
form, at the moment they file it. They are the one it is happening to; a lead
working down a queue a day later is guessing at how badly it blocked them.

The three controls are pre-filled with the **middle** of each scale, computed
from the band count rather than hardcoded — a workspace running three severity
bands and one running six both get a neutral starting point. A default of
"High" is not neutral. It is a claim, and one every reporter who left it alone
would be making by accident.

Triage still owns the final call. What the reporter chose arrives as their view
of it, and the `triage` transition overwrites it if a lead disagrees.

**None of it is required, and none of it may become required.** That is D45, and
`tests/plug-and-play.test.mjs` fails the build if a third `required` rule appears
on the server or if the client's submit gate mentions any of these fields. The
lists come from `GET /api/sire/report-options` — its own endpoint, not the
dashboard's, because the button is on every screen for every staff member and
that payload carries filter-bar rosters the form has no use for. If the request
fails the selects simply do not render and the form still files the issue.

## Screenshots

`navigator.mediaDevices.getDisplayMedia` — the browser's own picker. No
dependency, and its safety property is that the **user** chooses what is shared.

The modal closes for the capture and reopens with the draft preserved, so the
screenshot is of the problem rather than of the report form. The draft includes
the triage fields and every image already attached.

**Capture a part of the screen, or the whole thing.** After the frame is grabbed,
segment capture puts the still image full-screen and the user drags a rectangle
over the part that is wrong. A whole-screen grab of a dense CRM page is mostly
chrome the developer does not need, and it carries whatever else happened to be
visible — another customer's row in the list behind the dialog, a name in the
sidebar. Cropping is a clearer report *and* less incidental data.

The selection overlay is built with DOM calls rather than as a React component,
deliberately: it runs while the report modal is closed, so at the moment it needs
to render there is no SIRE tree to render into. Escape, or a click rather than a
drag, keeps the whole frame — declining is a normal answer, not a failure, and
never costs the capture.

**Several images, not one.** A bug is often three screens: the form, the error,
and the record it landed on. Asking for one means the other two arrive in a chat
message nobody can find later. Up to six, each uploaded separately after the
issue exists, with a thumbnail strip so the reporter can see they captured the
right thing. A failed upload says so and never reads as though the report itself
was lost.

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

## Getting them all out again

Filing is one click. Fixing forty of them one at a time is eighty page loads, and
the fixing was never the slow part — the round trip was.

```
GET  /api/sire/export?module[]=sales&module[]=inventory   one markdown brief
POST /api/sire/reports/transitions                        the fixes, in one call
```

**Grouped by screen, not by ticket.** That is the whole idea. One code change
usually closes several issues, because several people hit the same broken screen;
ordering by screen turns "forty tickets" into "six files to open". Within a
screen the order is severity then age, because once you are in the file you want
the worst thing first.

Each issue carries what somebody would otherwise open six pages to collect: what
broke, what they expected, the route and record they were on, **the API call that
failed**, and the browser. That last one is the single most useful line in the
document — it is the one fact a reporter could never have written down.

**The screenshots ride inside it.** Evidence lives on a private disk behind an
authenticated route, so a plain `![](url)` renders as a broken image everywhere
except a logged-in browser — the one place the reader already had it. So the
brief carries the picture itself as a data URI: there is nothing left to fetch
and nothing to authenticate, and it survives being pasted into an editor, a chat
or a model.

Thumbnails at 640px, not the originals, for two reasons and the second is the
real one. Size, because a brief nobody can paste is a brief nobody uses. And
reading: a 1600px screenshot inline is a page of scrolling between one issue and
the next, when what the picture is for is saying *which* screen broke. The
full-size link sits directly underneath for when you need the error text in it.

The whole export shares a byte budget rather than capping each image, so one
enormous screenshot cannot crowd out twenty small ones — and the brief says
plainly when it ran out instead of quietly dropping the rest. `images=0` gives
links only, for a small file to skim.

It names nobody. No reporters, no assignees, no commentary: the brief exists to
be pasted into an editor or a model, and the fewer people it names the less it
matters where it ends up. It does carry reproduction detail and internal screen
names, so the document says **For developers** on its first line and the UI panel
says so on its face.

`sire.export` gates it — a capability that sat in the vocabulary from the
beginning and was never once checked, because it described an endpoint nobody had
built.

**The way back is not a way around.** Every entry in a bulk transition runs the
same capability check, the same guard and the same required fields as the
single-issue route, and each is audited separately. Each also gets its own
transaction: one missing fix summary must not roll back nineteen good ones and
leave the caller guessing which. The response says what happened to every issue.

**Shrink the pile before reading it.** Duplicate detection is a local engine that
needs `sire:index-issues` in the scheduler — it is registered hourly now. Without
the index it has no neighbours to reason about and abstains on everything, which
reads exactly like "nothing similar exists". Of forty reports, a chunk are one
bug found by five people.

## Why speed is a security property, sort of

An engineer who finds Report Issue slow files a message in a chat channel
instead. That message is not tenant-scoped, not audited, not searchable, and
frequently contains a screenshot with another customer's data in it.

The fast path is the safe path.
