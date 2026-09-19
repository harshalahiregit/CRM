# The 30 September demonstration, walked — MS-001 §14

**Walked in a real browser on 2026-09-18**, immediately after the trip page rebuild, using the
demo tenant's own container `sgoe-402215-9` and the trip carrying it. Not read, not inferred —
clicked, in order, as the script asks.

MS-001 §14: *"The demonstration should use one realistic container/consignment and show the
following sequence."*

---

## The scoreboard

**9 of 14 work end to end. 3 are partial. 2 are not built.**
Of the five remaining gaps, **none is ours.**

*(The first version of this line read "6 / 4 / 4". That was wrong when written — counting the
table gives 7 / 4 / 3 for 18 September. Corrected here rather than quietly restated, because a
scoreboard nobody can reproduce from the table underneath it is worse than no scoreboard.)*

> **Updated 2026-09-19.** Step 3 moved from PARTIAL to **WORKS**, and step 9 from NOT BUILT to
> **WORKS**. Neither needed new permission: step 3's blocker had been cleared by P3 three days
> before this document was written and nobody said so, and step 9's was a ruling rather than
> work. The lesson is in TEAM-CONTRACTS — *check a blocker against the repository, not against
> the register.*

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
| 12 | Show invoice linkage | **PARTIAL** | **P3** — D-106 |
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

### 8 · GPS / temperature / generator event — NOT REACHABLE
P2 shipped telemetry ingestion this week (`TelemetryIngestionService`, batch + idempotency) and
the Fleet screen mentions telemetry. **There is no trip-side or container-side surface**, so the
demonstration cannot get from the container to a GPS or temperature reading. Nothing on our side
is blocking it — it needs a read contract from P2's telemetry to the trip.

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

### 10 · Record delivery, feedback and POD — PARTIAL
**Delivery works** (STT-007, ours, shipped 17 Sept). **POD works** (P3). **Feedback does not
exist anywhere** — no entity, field, API or requirement ID in the package. TM-001 §8 assigns it
to P3 and it is raised in `REQUEST-person3-feedback-is-yours.md`.

### 11 · Billing Ready or exact blocker — WORKS
> **Not ready to invoice** — This trip has no verified POD and no approved exception waiving one.

Exactly what TRP-P0-014 asks for: the blocker, named, not the word "Blocked".

### 12 · Invoice linkage — PARTIAL
The receivable panel is honest about why it is empty:

> No receivable has been opened for this trip. It is created once Accounts has raised the invoice.

But **no invoice can be raised at all** — `TripBill::markInvoiced()` has no caller and no route
(**D-106**, P3's). So the demonstration can show the linkage explained but never demonstrated.

### 13 · Native CIA / management exception — NOT BUILT
No control-room or intelligence screen exists in the Transport navigation. API-012
(`GET /transport/control-room`) is LOCKED in the registry and unbuilt. P3's (TM-001 §8, "Native
CIA / Reporting").

### 14 · Complete timeline / audit history — WORKS
Eleven events on one container, tagged by source (Container / Consignment / Trip), filterable,
newest first, and every status change now reads in English:

> Trip status changed — Draft → Viability pending
> Trip status changed — Allocated → Ready to dispatch
> Trip status changed — Dispatched → In transit

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
