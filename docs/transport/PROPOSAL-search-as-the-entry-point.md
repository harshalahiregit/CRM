# Proposal — make search the entry point (CTD §4, §5)

**Person 1 · 2026-09-19 · no code written, nothing built.**

I read §4, §5 and everything around them in `6.STOS-CTD v1.0.docx` before writing this, and I
measured every claim below against the running system rather than reasoning about it. Where my
findings differ from the brief I have said so.

---

## What the document actually says

**§4 PRIMARY SEARCH KEY** names **eleven** keys, not ten: `Container Number` as *"the preferred
entry point"*, and then ten more the system *"must also support"* — LR, DO, Transport Order, Trip,
Customer Reference, Vehicle, Driver, Invoice, POD, Internal Consignment ID. It closes with the
sentence that decides the destination question: *"All relevant search paths must ultimately lead to
the same Digital Passport."*

**§5 SEARCH EXAMPLE** shows one identifier typed and the answer appearing — container, status,
customer, order, LR, vehicle, driver, location, temperature, genset, billing, risk, next action.
The word is *"immediately"*.

**§2 CORE PRINCIPLE** is the reason both exist: *"One Container → One Digital Journey… The system
must eliminate fragmented information."*

### One thing the document does not agree with itself about

**§97 SEARCH API lists nine keys, not eleven.** It drops exactly two — **POD Number** and
**Internal Consignment ID** — which are precisely the two raised as missing. And **§150
NON-NEGOTIABLE** is narrower still: it requires only *"Container Number must be a primary search
key"* and *"LR and DO must be searchable."*

So the eleven-key list has one strong statement (§4), one weaker one (§97) that contradicts it, and
a non-negotiable floor (§150) that covers three keys. That matters for what we promise on screen,
and I have raised it as **D-117** rather than picking whichever list suits the build.

*(A correction of my own: `CommandPalette.jsx` has a docblock saying §4 lists "nine entry points".
It lists eleven. I will fix that wording whatever is decided here.)*

---

## The three gaps, measured

### Gap 1 — confirmed, and worse than "buried"

**There is no Transport landing page at all.** `app/routes.jsx:917` reads:

```jsx
<Route index element={<Navigate to="orders" replace />} />
```

Opening the module drops the user on the **Transport Orders list**. There is no entry point to
bury — the slot where one belongs is occupied by a redirect. The container search exists in exactly
two places, and neither is an entry point: a filter box *inside* the Containers list, and ⌘K, which
is invisible until somebody tells you about it.

Against §4's *"preferred entry point"* and §2's *"one connected, searchable journey"*, that is the
single biggest gap between what we built and what the document asked for — and the engine behind it
is already finished.

### Gap 2 — the destination is wrong, but the repoint is **not** why

A vehicle number resolves today and lands on `/app/transport/vehicles`, Fleet's list. A driver
lands on the drivers list. §4 says both should end at the same Digital Passport. That much is
exactly as described.

**The brief attributes this to D-110 — our ids not being Fleet's ids — and that is not the cause.**
I checked the chain rather than assuming it:

```
MH12DEMO01 → transport_vehicles #35 → TRP-2026-000034 → consignment 34 → container 23 → passport
```

It resolves end to end **today**, entirely inside our own id namespace, because
`transport_trips.vehicle_id` and `TransportVehicle.id` are the same space. The repoint is not
required to reach a passport from a plate. The only reason a vehicle stops at Fleet's list is that
`TransportSearchService::vehicle()` returns that path and follows through no further — a decision
made when P1's vehicle pages were unrouted (D-62), and never revisited against §4.

**What genuinely does wait on the repoint:** a plate that exists in *Fleet's* `vehicles` but not in
our placeholder `transport_vehicles`. Today both hold the same two demo trucks, so the distinction
is invisible; once Fleet is the master, a plate we do not hold will not resolve. That is a real
limit and the proposal below states it on screen rather than hiding it.

### Gap 3 — one of the two, not both

| Key | Finding |
|---|---|
| **Internal Consignment ID** | **Already works.** `CNM-2026-000034` resolves to its consignment. If "Internal Consignment ID" means something other than `consignment_number`, no document I can find says what — and that would be a question, not a build. |
| **POD Number** | **There is no field to search.** `trip_documents` — the table that actually holds PODs — has columns for file path, name, mime, size, hash, status and verification, and **no `document_number` at all.** A POD in this system is a file attached to a trip, not a numbered artefact. |

So POD search is **a finding, not a build**, exactly as anticipated. Giving PODs numbers is a
schema change plus a rule about who issues the number and in what format — and no document
specifies either. §97 and §150 both omit POD, which is consistent with it never having been
designed as a numbered entity. **I recommend we do not invent one.**

---

## Measured coverage of all eleven keys

Every row below was run through `TransportSearchService::resolve()` against the live database. LR
and Customer Reference were tested inside a transaction that was rolled back, because the demo data
has neither.

| # | §4 key | Resolves today | Lands on today | Should land on | Status |
|---|---|---|---|---|---|
| 1 | **Container Number** | ✅ | Container 360 | Container 360 | **correct** |
| 2 | **LR Number** | ✅ *(case-insensitive)* | Consignment | Container 360 | destination |
| 3 | **DO Number** | ✅ | Consignment | Container 360 | destination |
| 4 | **Transport Order Number** | ✅ | Order page | Container 360 | destination |
| 5 | **Trip Number** | ✅ | Trip page | *see below* | **deliberate exception** |
| 6 | **Customer Reference** | ✅ | Consignment | Container 360 | destination |
| 7 | **Internal Consignment ID** | ✅ | Consignment drawer | Container 360 | destination |
| 8 | **Vehicle Number** | ✅ | **Fleet list** | Container 360 | destination |
| 9 | **Driver** | ✅ | **Drivers list** | Container 360 | destination |
| 10 | **Invoice Number** | ❌ | — | Container 360 | **waits on Accounts** |
| 11 | **POD Number** | ❌ | — | — | **no field exists** |

**Nine of eleven resolve. Two do not, for different reasons**, and neither is a matter of effort on
our side: the invoice number lives in Accounts and we hold only a numeric `trip_bills.invoice_id`
with no read contract to the number a human would type; the POD has no number anywhere.

**The exception in row 5.** A trip number should keep going to the trip page. Somebody typing
`TRP-2026-000034` is an operator who wants the working screen, not the traceability view, and
sending them to a container passport would be obeying §4's letter against its purpose. I would make
this the one stated exception, with the passport one click away, rather than pretend it does not
exist.

---

## The proposal

### 1 · Where the search lives

**Take the `index` slot of `/app/transport`.** Replace the redirect to `orders` with a landing
page whose entire top half is one search box:

```
┌──────────────────────────────────────────────────────────────┐
│                                                              │
│   Find anything in Transport                                 │
│                                                              │
│   ┌────────────────────────────────────────────────────┐    │
│   │  Type a container, trip, LR, vehicle…              │    │
│   └────────────────────────────────────────────────────┘    │
│                                                              │
│   Container · LR · Delivery order · Transport order · Trip    │
│   Consignment · Customer reference · Vehicle · Driver         │
│                                                              │
│   Invoice and POD numbers are not searchable yet.             │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

**Why the index slot and not a new route:** an entry point that needs its own URL is not an entry
point. Everyone who opens Transport arrives here already; today they arrive at a list of orders.
This costs one line of routing and makes the first thing a user meets the thing §4 calls the
preferred entry point.

**Why the keys are named under the box:** a search box with no stated vocabulary teaches nobody
what it accepts, and a user who types a POD number and gets nothing concludes the search is broken
rather than that the key is unsupported. Naming the nine that work, and naming the two that do not
**in the same breath**, is the honest version and costs one line of text.

**The box is autofocused**, so the module opens with a cursor in it.

**What else goes on the page:** below the fold, the four lists that are there today (orders, trips,
consignments, containers) as plain links, so nothing becomes harder to reach. **I would not put a
dashboard there.** Counts and charts on the entry page compete with the box for the first glance,
and §4 asks for an entry point, not a summary screen.

### 2 · What happens on an exact match

**Straight through, per §5's "immediately show" — with one honest qualification.**

- **The key identifies exactly one journey** → navigate straight to the Digital Passport. No
  intermediate list, no click. This is nine of the eleven keys in the ordinary case.
- **The key identifies several journeys** → this is real for a vehicle or a driver, which are
  assets with a history rather than a journey. Show a short list headed by what it matched:
  *"MH 12 DEMO 01 — 3 trips"*, most recent first, each row naming its container and status.
  Straight-through here would silently pick one journey out of several, which is guessing.
- **The key identifies a journey with no container** (a consignment of loose cargo — §8 explicitly
  allows *"other cargo references"*) → the consignment view, with a line saying there is no
  container on this consignment. Not a dead end, and not a false passport.

### 3 · What happens on no match

Quote the term back and name the vocabulary — the empty-state pattern already agreed after the
September sweep:

> **Nothing matches "ABCD1234567".**
> You can search a container, LR, delivery order, transport order, trip, consignment, customer
> reference, vehicle or driver number.
> *Invoice and POD numbers cannot be searched yet.*

That last line is the one that matters. Without it, the two unsupported keys look like bugs every
time somebody tries them.

**No fuzzy fallback, no "did you mean".** The resolver is exact-match by design — eight identifier
spaces with no overlap, where a near-miss returning the wrong truck is worse than returning
nothing. That reasoning is already written into `TransportSearchService` and I would not change it
to make the box feel cleverer.

### 4 · What happens for a key that resolves to something other than a container

This is the §4 sentence that the current build fails, and the fix is a **follow-through step**, not
a new resolver. Where a match is not itself a container, carry on down the chain it already knows:

```
vehicle  → current or latest trip → consignment → container → passport
driver   → current or latest trip → consignment → container → passport
LR / DO  → consignment → container → passport
order    → consignment → container → passport
```

Each hop already exists in the codebase; none of them is new logic. Where the chain runs out — a
vehicle with no trips, a consignment with no container — stop at the last real thing and **say
which hop failed**, in a sentence:

> **MH 14 DEMO 02** — no trip is running on this vehicle. Last carried `sgoe-771040-2`, delivered
> 12 September.

That is the honest version of §4. It reaches the passport whenever a passport exists and explains
itself when one does not, instead of quietly landing the user on a list.

### 5 · Do ⌘K and the box share one resolver?

**They already do, and the new box must join them rather than start a third.**

`CommandPalette.jsx:74` calls `transportSearchApi.resolve(term)` → `GET /transport/search` →
`TransportSearchService::resolve()`. The Containers page's filter box is the odd one out; it is a
*list filter*, a different job, and it can stay as it is.

So there is one resolver and it stays one. Everything proposed above — the follow-through to the
passport, the several-journeys case, the two unsupported keys — belongs **in the service**, not in
the page. If any of it is implemented in the landing page, ⌘K will answer differently from the box
within a month, which is the drift the brief is right to be worried about.

**One consequence worth stating plainly:** improving the resolver improves ⌘K for free. The
follow-through in §4 above will make ⌘K land on passports too, which is what §4 asks of *every*
path, including that one.

---

## What I would not do

- **Not invent a POD number.** No document defines who issues it or in what format. It is a finding
  (D-117), and inventing one would be the exact thing Hard Rule 1 forbids.
- **Not fake the vehicle path after the repoint.** When Fleet becomes the master, a plate we do not
  hold will not resolve, and the screen should say *"that vehicle is in Fleet; Transport has no trip
  on it"* rather than return nothing and look broken.
- **Not send a trip number to a container passport.** Stated as the one deliberate exception above.
- **Not add a dashboard to the landing page.** §4 asks for an entry point.

---

## Sequencing, if approved

| | Work | Depends on |
|---|---|---|
| 1 | Follow-through to the passport in `TransportSearchService` (vehicle, driver, LR/DO, order) + the several-journeys and chain-ran-out cases | nothing |
| 2 | The landing page on the `index` slot, using the same endpoint | nothing |
| 3 | The empty state naming the two unsupported keys | nothing |
| 4 | Invoice number | a read contract from Accounts |
| 5 | POD number | a ruling that PODs get numbers at all |

**Items 1–3 are unblocked and are the whole of the owner's request.** Items 4 and 5 are other
people's decisions and are named on screen in the meantime rather than left to look like faults.

---

## The three questions I need answered before building

1. **The trip-number exception** — do you agree a trip number keeps going to the trip page, or
   should §4 be followed literally and send it to the passport?
2. **"Internal Consignment ID"** — I read this as `consignment_number`, which already works. If it
   means a different identifier, I need to know which, because nothing in the package defines it.
3. **POD numbers** — confirm we are *not* inventing one, and that "POD Number is not searchable" is
   the answer we give until somebody specifies it.

**Nothing has been built. No files changed other than this proposal and the D-117 entry.**
