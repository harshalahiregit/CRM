# The client portal — how much of it already exists

**For the owner. 21 September 2026.** Measured against the running system, not estimated.
No code was written and nothing was built to produce this.

---

## The answer in one paragraph

**Twelve of the sixteen steps already have a working engine behind them, and none of the sixteen
can be shown to a client today.** The operational half of the portal — trips, allocation,
container 360, documents, POD, exceptions, billing, the timeline — is built, tested and has been
walked end to end in a browser this week. What does not exist is the *client-facing* half: there is
no client door into Transport, no way to limit a client to their own records, and no way to hide a
price from them. Those three things are **one shared piece of work sitting underneath every step**,
not sixteen separate pieces. So the honest shape is not "a sixteen-step portal in ten days" and it
is not "nine already work and need a door" either — it is **one substantial foundation plus four
genuinely missing features**, on top of an engine that already runs.

---

## First, the two lists are the same list

STOS-CLP §28 gives **15** steps; MS-001 v1.1's M4 slice gives **16**. They are not different
scopes — v1.1 is finer-grained in two places and adds nothing:

- CLP folds *"Vehicle/Driver & Location Visibility"* into one step; v1.1 splits it into **7 (client
  sees vehicle/driver)** and **10 (location/tracking)**.
- v1.1 promotes **12 (Documents / Evidence)** to a step of its own; in CLP it lives inside the
  documents section rather than the slice list.
- The request steps are split differently — CLP as *(create) + (validate rules)*, v1.1 as
  *(normal) + (urgent + rules)* — but cover the same ground.

**The table below uses v1.1's sixteen**, because that is the plan being delivered against.

---

## The measurement

**READY** = exists, and safe to show once a door is added · **NEEDS SCOPING** = exists, cannot be
shown to a client until filtering and per-customer narrowing exist · **NOT BUILT** = does not exist

| # | Step | Verdict | What is missing |
|---|---|---|---|
| 1 | Client Login / Role Access | **NEEDS SCOPING** | Clients already authenticate to the CRM. They have no Transport access, and none of CLP's six roles exists. |
| 2 | Client Dashboard | **NOT BUILT** | No screen, no aggregate. |
| 3 | Create Normal Transport Request | **NOT BUILT** | No *Transport Request* entity. Orders are raised internally; a client request that becomes an order is a new object and a new handoff. |
| 4 | Urgent Request + contract-rule handling | **NOT BUILT** | Orders carry a `priority` column, and nothing else. **No rate card exists** — the oldest open item on the project, and still unowned. |
| 5 | Transporter Accepts | **NEEDS SCOPING** | Approval is built and works internally. Nothing presents it to a client. |
| 6 | Vehicle + Driver Assigned | **NEEDS SCOPING** | Allocation with eligibility checks is built and walked. Client-visible version needs filtering. |
| 7 | Client Sees Vehicle/Driver | **NEEDS SCOPING** | Data exists. The payload currently carries driver code, licence class and availability — **none of which a customer may see.** |
| 8 | Trip / Container 360 | **NEEDS SCOPING** | Built, and the strongest screen we have. It currently shows the billable amount and everything in the tenant. |
| 9 | Milestone Timeline | **NEEDS SCOPING** | A live event timeline exists (39 registered types, real traffic from all three developers). **M01–M14 has no canonical definition anywhere** — the package's own audit calls this a blocker. |
| 10 | Location / Tracking | **NEEDS SCOPING** | Telemetry is built and publishes correctly, but **nothing reaches a trip** until the vehicle-record repair lands. Then filtering. |
| 11 | Exception / Client Concern | **NEEDS SCOPING** | Raise → acknowledge → resolve is built and has been walked twice. A *client* raising one is a new door. |
| 12 | Documents / Evidence | **NEEDS SCOPING** | Filing, versioning and verification are built. Which documents a client may open is undefined. |
| 13 | Delivery + POD | **NEEDS SCOPING** | Built. Walked end to end this week. |
| 14 | Client Feedback | **NOT BUILT** | Nothing exists. Now specified in the new package for the first time. |
| 15 | Billing Ready / Invoice Status | **NEEDS SCOPING** | Built, and a trip was taken from delivery to paid-and-closed by clicking this week. Needs the heaviest filtering of any screen — freight, costs and margin all sit here. |
| 16 | Audit Trail | **NEEDS SCOPING** | Built, append-only, filterable. Currently shows internal actors and reasons. |

**READY: 0 · NEEDS SCOPING: 12 · NOT BUILT: 4**

**Nothing is READY, and not because twelve engines are weak.** Every one of the twelve fails the
same two tests — there is no client door, and there is no way to make a screen safe. Those are
shared, which is the good news and the bad news at once.

---

## What stands between it and a client

### The door — 89 routes, one gate, no exceptions

Every Transport route sits behind `role:admin,staff`, and a test (`TransportRouteExposureTest`)
fails the build if any route escapes it. **A client cannot reach a single Transport endpoint
today**, by design and by enforcement.

That much is genuinely a door. The next two are not.

### The permission model already knows what a customer is — and grants it almost nothing

A CRM client maps to a `customer` role in the Transport matrix. That role is granted **2 of the 41
permission keys**: view a trip, view an order. Both marked *own*.

Everything else the portal needs — containers, documents, exceptions, billing, the timeline — is
granted to six internal roles and **not** to a customer. Container 360, the step everyone assumes
is ready, is one of them. Opening those up means new matrix rows, and under our own rules a new
permission needs a registry entry or a written ruling.

### a) CLP's six client roles — we can express roughly one of them

CLP §3 names **Client Admin, Operations, Warehouse/Gate, Quality/Compliance, Finance and
Management**, and requires permissions to be *organisation-, branch-, role-, transaction- and
document-type aware*.

Today Transport has **one** customer role and no notion of a branch, a client user, or a document
type a role may open. So this is not "add five roles to a list". It is a second axis — *which
screens and which fields* — on top of the one we already know is missing. We could express the
existence of six roles; we could not express what any of them is allowed to see.

### b) What D-46 actually costs — and it is not a footnote

D-46 says the per-customer narrowing is *declared and implemented nowhere*. Measured:

- `SCOPE_OWN` and `SCOPE_ASSIGNED` exist in the matrix and **no production code path in Transport
  consumes either.** Every one of the 41 permissions answers *"may this role touch this area"* and
  none answers *"which rows"*.
- **No field-level filtering exists anywhere in Transport** — not one endpoint, service or
  resource hides a field by role.
- The surface it has to cover: **89 routes, 19 controllers, 28 services.**

So a client-facing screen needs two things that do not exist: a filter on *which records* and a
filter on *which fields*. Until both exist, "this step already works" is true of the engine and
false of the product. **That is why the table above has no READY rows.**

### c) Is Person 3's work reusable?

He built `ScopeResolver` and `DataScope` for HR this week, and his docblock describes D-46 almost
word for word — *"scope existed, was computed correctly, and was consulted in a single place that
rendered menus."* He has solved this problem class next door.

**The pattern is reusable. The code is not.** All six of its public methods are employee-shaped —
they return employee ids, act on an employee, or resolve one — and `HrEmployee` appears nine times
in the file. `DataScope` is `global / own / department / branch / team`: an employee hierarchy.
Ours is a customer axis, where *own* means "my company's consignments", not "my staff record".

So: a precedent worth copying and a colleague worth including, not a library to import. The
valuable part is that he has already proved the shape works — a small primitive that answers
*which rows* once, and a helper that applies it to a query, so each module is one line rather than
a fresh copy of the rule.

---

## My honest read: it is a building, but only one

If the question is *"is the remaining work a door or a building?"* — **it is a building.** A client
door, a second permission axis, per-customer row narrowing and field-level filtering across 89
routes is not a fortnight's work, and calling it a door would be the kind of optimism that gets
discovered on the 29th.

But it is **one** building, not sixteen. Twelve of the steps are waiting on the same foundation,
and once it exists they arrive close together rather than one at a time — because the engines
behind them are already built, already tested, and have been walked end to end in a browser this
week. That is a materially better position than the one the conversation assumes.

The four NOT BUILT steps are a separate matter, and one of them is the real problem: **step 4
depends on the rate card, which no document specifies and no one owns.** A booking portal whose
pricing rules do not exist cannot be finished by anyone, at any speed. Steps 2, 3 and 14 are
ordinary features. Step 4 is a decision nobody has made.

**So the sentence I would put in front of a meeting is this:** the operational system is real and
demonstrable today; the portal is a presentation layer that does not exist yet; the work between
them is one foundation, mostly security rather than features; and the one thing that could stop it
regardless of effort is the rate card.
