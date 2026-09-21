# The 30 September demonstration, walked — MS-001 §14

**Walked in a real browser on 2026-09-18**, immediately after the trip page rebuild, using the
demo tenant's own container `sgoe-402215-9` and the trip carrying it. Not read, not inferred —
clicked, in order, as the script asks.

MS-001 §14: *"The demonstration should use one realistic container/consignment and show the
following sequence."*

---

## The scoreboard

**10 of 14 work end to end. 2 are partial. 2 cannot be shown** — one not reachable (step 8),
one not built (step 13). Of the four remaining gaps, **none is ours.**

*(This line has now been wrong twice, both times by arithmetic rather than by judgement. It first
read "6 / 4 / 4" when the table gave 7 / 4 / 3. On 19 September, moving step 12 out of PARTIAL, it
was restated as "3 partial, 1 not built" — decrementing the wrong bucket. Counted from the table
each time rather than adjusted from the previous line, which is the only method that has not
failed: **10 WORKS, 2 PARTIAL, 1 NOT REACHABLE, 1 NOT BUILT = 14.**)*

> **Updated 2026-09-19 (morning).** Step 3 moved from PARTIAL to **WORKS**, and step 9 from NOT
> BUILT to **WORKS**. Neither needed new permission: step 3's blocker had been cleared by P3 three
> days before this document was written and nobody said so, and step 9's was a ruling rather than
> work. The lesson is in TEAM-CONTRACTS — *check a blocker against the repository, not against
> the register.*
>
> **Updated 2026-09-19 (afternoon).** Person 3 closed **D-106** — a bill can now be marked
> invoiced — and the whole closure chain opened behind it. Trip `TRP-2026-000034` was walked
> **delivered → POD verified → billable → billed → collection_pending → closed** by clicking, and
> step 12 moved from PARTIAL to **WORKS**. That walk also turned up two things this document did
> not expect and now records: there is **no UI control** to mark a bill invoiced, and the
> timeline's early events were **not being written live** (D-115). Both are described below.

| # | Step | Verdict | Whose gap |
|---|---|---|---|
| 1 | Search by Container Number | **WORKS** | — |
| 2 | Open Container 360 / Consignment Passport | **WORKS** | — |
| 3 | Show customer, order, **LR/DO** and trip | **WORKS** ✅ | *was P3 — cleared 16 Sep* |
| 4 | Show recommended vehicle/driver and allocation **reason/score** | **PARTIAL** | score — **P2** |
| 5 | Show compliance and dispatch eligibility | **WORKS** | — |
| 6 | Show document status and handover chain | **WORKS** | — |
| 7 | Show dispatch/trip progression | **WORKS** | — |
| 8 | Show GPS/temperature/generator event | **NOT REACHABLE** | **P2** |
| 9 | Show exception/CAPA where applicable | **WORKS** ✅ | *was P1 — built 18 Sep* |
| 10 | Record delivery, **feedback** and POD | **PARTIAL** | feedback — **P3** |
| 11 | Show Billing Ready or exact blocker | **WORKS** | — |
| 12 | Show invoice linkage | **WORKS** ✅ | *was P3 — closed 19 Sep* |
| 13 | Show native CIA/management exception | **NOT BUILT** | **P3** |
| 14 | Show complete timeline/audit history | **WORKS** | — |

---

## What was actually on screen

### 1 · Search by Container Number — WORKS
Containers page, typed `SGOE4022159`, one hit: `sgoe-402215-9 · matched as SGOE4022159`. The
three spellings of the same number all resolve, and so do a trip, order, consignment, customer
reference, registration and driver name.

### 2 · Open Container 360 — WORKS
One click to `/app/transport/containers/23`.

> **sgoe-402215-9 · In transit**
> On trip TRP-2026-000034, on the road since 18 Sep, 09:44.
> **NEXT** — Record the delivery when it arrives.

### 3 · Customer, order, LR/DO and trip — ~~PARTIAL~~ **WORKS** (19 Sep)
Six of the seven chain nodes render with real data: customer, transport order, consignment,
container, trip, vehicle, driver — each with an **Open** link except the last two (D-110).

~~**LR/DO is absent.**~~ **Built 19 September.** The chain now carries both, in the position
CTD §5's worked search example puts them — Container, Status, Customer, Transport Order, **LR**,
Vehicle, Driver:

> CUSTOMER · TRANSPORT ORDER · CONSIGNMENT · **LR / BILTY LR-2026-0042** · **DELIVERY ORDER
> DO-2026-0099** · CONTAINER *(you are here)* · TRIP · VEHICLE · DRIVER

**And the blocker had already cleared when this document said it had not.** D-41 ruled on
12 September that an LR and a DO stay documents rather than getting tables of their own, which
needed a consignment to be something a document can be filed against. **P3 shipped exactly that
on 16 September** — `TransportDocumentEntity::CONSIGNMENT`, `DELIVERY_ORDER`, and the two routes
— two days before this walk recorded the step as blocked on him. Nobody announced it; we did not
announce the trip lifecycle to them either.

What P1 then built on top of it: the capture panel on the consignment (ORD-005/006, "LR
traceable", "DO traceable"), the two chain nodes (CTD-004/005, "LR visible", "DO visible"), and
**LR/DO as search keys** — which CTD §150 lists among its NON-NEGOTIABLE REQUIREMENTS and §4
lists among the entry points that "must ultimately lead to the same Digital Passport".

### 4 · Recommended vehicle/driver and allocation reason/score — PARTIAL
The candidate picker is genuinely good on the **reason** half:

> READY TO ASSIGN · 1 — MH 14 DEMO 02 · Trailer 20ft · 18 t · Available · Eligible
> CANNOT BE USED RIGHT NOW · 1 — MH 12 DEMO 01 · **Why not?**
> *On TRP-2026-000034 — back in 2 days, 20 Sep*
> *Vehicle is Allocated — only an Available or Idle vehicle can be allocated.*

**There is no score.** No number, no ranking, no weighting shown. Allocation scoring is P2's
engine; what we render is eligibility with reasons, which is the half we own.

### 5 · Compliance and dispatch eligibility — WORKS
`READY TO LEAVE? · Ready · 5 of 5 checks confirmed`, and on the trip page the same verdict
re-derived live at the moment of dispatch (BRW-046). A blocked trip names the failing check,
what it says now and what it said before.

### 6 · Document status and handover chain — WORKS
P3's panel renders and leads with the answer:

> **Not ready to bill** — This trip has no verified POD and no approved exception waiving one.

Five document types offered, upload path present.

### 7 · Dispatch/trip progression — WORKS
The rebuilt trip page. Seven-step tracker, `YOU ARE HERE` on **On the road**, and one sentence
with one button:

> **On the road since 18 Sept 2026, due 20 Sept 2026.** [Record delivery]

Done stages carry their outcome on the collapsed line.

### 8 · GPS / temperature / generator event — NOT REACHABLE, for a different reason than before
**Re-walked 19 Sep**, after P2's `TripTimelinePublisher` landed (`79815f29`). The verdict does not
change; the reason does, and the old reason would have sent somebody looking in the wrong place.

The read contract **exists now**. P2 publishes `gps.activated`, `genset.on/off`,
`temperature.reading` and `temperature.excursion` into `trip_events` — our timeline, through our
recorder, emitting on change rather than per ping. Driven directly, it produces exactly what this
step asks for:

> `gps.activated` — Tracking active on MH12DEMO01
> `temperature.reading` — 4.2°C on MH12DEMO01

**But nothing reaches a trip.** `TripTimelinePublisher::openTripFor()` matches
`transport_trips.vehicle_id` against a **Fleet** `Vehicle` id, and that column holds a
`transport_vehicles` id — P1's placeholder table, which is D-100(c)'s unfinished repoint. Two id
spaces, no foreign key, compared as raw integers. Every reading currently falls into the
"no trip for this vehicle" branch and is dropped.

Worth knowing which kind of failure it is, so it was constructed rather than assumed: with a trip
whose `vehicle_id` was made to collide with a Fleet id, **both events landed on it, naming a truck
that was not that trip's truck.** Silent today because the ranges do not overlap; a wrong-join the
day they do. That is **D-116**, P2's, raised in
`NOTE-person2-telemetry-joins-on-the-wrong-vehicle-id.md`. The constructed rows were removed.

Nothing on our side blocks it. It is one join away.

### 9 · Exception / CAPA — ~~NOT BUILT~~ **WORKS** (18 Sep)
Nothing on the trip page mentions an exception. `trip_exceptions` has a schema and a vocabulary
and **no model**: SNG-TRN-013 shipped the first two and stopped, blocked on **D-29** (six
different exception lifecycles across the documents, Step 11 contradicting itself) and **D-30**
(`waived` is required by FRS TRP-P0-012 and exists in no enum).

~~Both are awaiting an owner ruling.~~ **Both were ruled on 18 September and neither needed a new
decision.** D-29 dissolved under the standing Step 9 / Step 11 rule already in TEAM-CONTRACTS;
D-30 deferred `waived` the way BR-P0-017's waiver is deferred. The register is built: raise →
acknowledge → resolve, on the trip page.

CAPA itself remains P3's (Quality, TM-001 §8).

**Walked properly on 19 September**, which had not been done — the 18th recorded that the register
existed, not that it ran. On `TRP-2026-000035`, by clicking, in one sitting:

| Click | Result on screen |
|---|---|
| Raise an exception → *Tyre burst on NH-48…* | `EXC-2026-000002` · Low · Resource · **Open**, due 20 Sept |
| Acknowledge & own it | **Acknowledged** (one click — no form, correctly) |
| Resolve → *Tyre replaced at 11:20…* | **Resolved**, `Open 0` |

The counters at the top (`Open` / `Critical open` / `Overdue`) track each transition, and the
resolution note is kept on the card rather than replacing the original complaint.

It also proved this morning's D-115 fix from the user's side: the trip's timeline now reads
`exception.raised → exception.acknowledged → exception.resolved`, all three **LIVE**. Until today
the middle one was written by nothing, so an exception appeared and disappeared with nothing
between — and the SLA clock, which starts at acknowledgement, started invisibly.

### 10 · Record delivery, feedback and POD — PARTIAL
**Delivery works** (STT-007, ours, shipped 17 Sept). **POD works** (P3). **Feedback does not
exist anywhere** — no entity, field, API or requirement ID in the package. TM-001 §8 assigns it
to P3 and it is raised in `REQUEST-person3-feedback-is-yours.md`.

### 11 · Billing Ready or exact blocker — WORKS
> **Not ready to invoice** — This trip has no verified POD and no approved exception waiving one.

Exactly what TRP-P0-014 asks for: the blocker, named, not the word "Blocked".

### 12 · Invoice linkage — ~~PARTIAL~~ **WORKS** (19 Sep)
This step was PARTIAL because `TripBill::markInvoiced()` had no caller and no route (**D-106**).
Person 3 shipped the route on 19 September and the whole chain behind it opened.

Walked on `TRP-2026-000034`, clicking, in one sitting:

| Click | Result on screen |
|---|---|
| Verify POD | **POD verified** |
| Prepare billing | **Billable** |
| *(no button — see below)* | **Billed**, invoice `90210` linked |
| Open receivable | **Collection pending** |
| Record a receipt — ₹68,500, ref `UTR-2026-77120` | Outstanding **₹0.00**, **Settled** |
| Close this trip | **Closed** |

The receivable panel that used to explain its own emptiness now carries the real figures, and
step 12's invoice linkage is visible rather than described.

#### The one gap the walk found: nothing clicks the invoice route
`POST /transport/trips/{id}/bill/invoiced` exists and works — the row above went through it — but
**no control anywhere in the UI calls it.** `BillingPanel.jsx` has exactly one button (*Prepare
billing*), and `transportApi.js` has no method for the endpoint. The trip above reached **Billed**
because the walk posted to the route by hand.

So the demonstration currently cannot get past *Billable* by clicking alone. The route is finance's
and the panel is Person 3's, so the control is theirs to add; it is written up in
`NOTE-person3-d106-landed-and-the-button-is-missing.md` rather than built here.

#### The walk also proved the closure controls
All five were read on screen at `collection_pending`, and two had been claiming they could not run:

| Control | Verdict | Note |
|---|---|---|
| Proof of delivery | **Passed** | A verified POD is on file. |
| Billing | **Passed** | Invoiced by Accounts. |
| Collection | **Passed** | Paid in full. |
| Supplier settlement | **Not checked** | Still true — no `trip_settlements` table (SNG-TRN-017). |
| Open exceptions | **Passed** | **Now genuinely runs.** |

Open exceptions was the correction. It had gone on reporting "not built" for a day after the
exception register landed on 18 September, which meant **TRP-P0-014 — no silent closure with
unresolved critical exceptions — was not being enforced while the screen said the check could not
run.** It now blocks on an open critical exception and says which one. Supplier settlement is the
only control left that cannot be checked, and re-checking it confirmed the reason still holds.

### 13 · Native CIA / management exception — NOT BUILT
No control-room or intelligence screen exists in the Transport navigation. API-012
(`GET /transport/control-room`) is LOCKED in the registry and unbuilt. P3's (TM-001 §8, "Native
CIA / Reporting").

### 14 · Complete timeline / audit history — WORKS, but it was complete for the wrong reason
Eleven events on one container, tagged by source (Container / Consignment / Trip), filterable,
newest first, and every status change reads in English:

> Trip status changed — Draft → Viability pending
> Trip status changed — Allocated → Ready to dispatch
> Trip status changed — Dispatched → In transit

**This verdict was right about the screen and wrong about why.** Reading trip 43's rows after the
closure walk showed that the first seven were `BACKFILLED` — reconstructed from the audit trail by
`BackfillTripEvents` — and only the last four were written live. `trip.created`, `trip.submitted`,
`vehicle.allocated` and `pretrip.passed` were **not being recorded by anything**. Every existing
trip looked complete because the backfill had filled it in; a trip created the next day would have
had four holes, and nothing would have said so.

That is **D-115**, and it is entirely ours. Ten call sites now emit live, a fresh trip
(`TRP-2026-000036`) was created to confirm it — `trip.created / trip.submitted / trip.approved`,
all three **LIVE** — and `TripEventEmissionTest` pins the set of declared-but-unemitted types so
the next missing emitter fails a test instead of hiding behind a backfill.

The timeline is also no longer only ours. Merging on the same afternoon brought **P3's five
document and billing events** (`e0077b00`) and **P2's four telemetry events** (`79815f29`), all
written through the same recorder. Step 8 — GPS, temperature and generator — is the one to
re-walk next as a result; it is still marked NOT REACHABLE above because it has not been walked
since, and this document does not promote a step it has not clicked.

The general lesson, which is the reason this paragraph is here rather than only in the register:
**a screen that is populated by a migration proves nothing about the code that is supposed to
populate it.** This step was walked, and walking it still did not catch this — it took reading the
rows and asking where each one came from.

---

## What this means for 30 September

**Steps 1 → 7, 9 and 14 run as one unbroken story** on a single real container: search it, open its
passport, read its whole chain, see it is fit to leave, see its paperwork, watch it progress, and
read everything that has happened to it. That is the spine of the demonstration and it holds.

**The five remaining gaps are steps 4, 8, 10, 12 and 13 — and not one of them is ours.**

| | | whose | how far away |
|---|---|---|---|
| 4 | allocation **score** | P2 | their scoring engine; we show eligibility with reasons |
| 8 | GPS / temperature event | P2 | **one change** — three `record()` calls in `TelemetryIngestionService`, see `CONTRACT-trip-events.md` |
| 10 | **feedback** | P3 | unspecified anywhere in the package |
| 12 | invoice linkage | P3 | **one route** — `markInvoiced()` still has no caller, D-106 |
| 13 | native CIA / control room | P3 | API-012, unbuilt |

Two of the five are a single change each, and both are somebody else's to make.

**The highest-value single unblock is D-106**: one route on P3's side turns step 12 from
explained into demonstrated, and makes `closed` reachable, which lights up the end of step 7 as
well.

Nothing in this document is a request to change scope. It is what a person clicking through the
script on 18 September actually saw.
