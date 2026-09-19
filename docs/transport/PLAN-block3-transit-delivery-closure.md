# Block 3 — Transit, Delivery, Closure

**Person 1 (Core & Operations). Plan only — no code written.**
Posted 2026-09-17. MS-001 puts this at **19–20 Sep, "OPS — Dispatch workflow, delivery,
closure, timeline"**, so we are two days early on the milestone plan.

---

## 1. Requirement IDs up front

Everything below is quoted. Where a document is silent it is marked SILENT and becomes a
question in §7, not a guess.

| ID | Source | Says |
|---|---|---|
| `STT-006` | Step 11 State_Transitions, **LOCKED** | `dispatched → in_transit` · trigger "Dispatch vehicle" · actor TripEngine · precondition **"Dispatch confirmed"** · side effect "Start monitoring" · audited |
| `STT-007` | Step 11 State_Transitions, **LOCKED** | `in_transit → delivered` · trigger "Delivery confirmation" · actor TripEngine · precondition **"Destination event"** · side effect **"Request POD"** · audited |
| `STT-012` | Step 11 State_Transitions, **LOCKED** | `collection_pending → closed` · trigger "Close trip" · actor TripEngine · precondition **"Settlement/POD/billing controls pass"** · side effect **"Snapshot profit"** · audited |
| `SM-TRP` | Step 11 State_Machines, **LOCKED** | `in_transit` entry gate **"Departure recorded"**, exit "Delivered/Exception", owner Operations · `delivered` entry **"Destination confirmed"**, exit "POD received" · `closed` **terminal**, entry "Settlement and closure rules pass" |
| `API-009` | Step 11 API_Registry, **LOCKED** | `POST /api/v1/transport/trips/{trip}/close` · permission **`transport.trip.close`** · emits TripClosed |
| `CTR-013` | Step 11 API_Contracts | API-009 `closure_reason` · body · TEXT · **required** · non-empty · "Closure controls run first" |
| `PERM-005` | Step 11 Permissions, **LOCKED** | Trip / close — Owner **Y**, Operations **Y**, Dispatcher **N**, Accounts **Y**, Approver **Y**, Driver N, Customer N, Supplier N, Admin **Y** |
| `EVT-012` | Step 11 Event_Registry, **LOCKED** | `TripClosed` · producer TripEngine · payload `trip_id, closure_timestamp` · idempotency `trip_id+close_version` · consumers ProfitEngine, ControlRoom |
| `STOS-REQ-OPS-009` | RTM · **P0** | "Track trip status" — acceptance **"Trip lifecycle controlled"** |
| `STOS-REQ-OPS-010` | RTM · **P0** | "Record delivery" — acceptance **"Delivery confirmed"** |
| `FRS TRP-P0-011` | Step 3 FRS · Transit · **P0** | "Live trip control" · Dispatcher/Owner · trigger **"Vehicle in transit"** · rules "geofence, idle, route deviation, ETA rules; **manual update fallback**" |
| `FRS TRP-P0-014` | Step 3 FRS · Billing · **P0** | "Trip closure readiness" · trigger "POD received" · rule **"no silent closure with unresolved critical exceptions"** · acceptance **"User sees exactly why a trip is blocked"** |
| `BR-P0-017` | Step 3 BRWM · **Hard** | "Trip close requires all critical controls passed **or explicit waiver**" · override **Owner role** · action Block/waiver |
| `BR-P0-010` | Step 3 BRWM | Transit — critical route deviation/idle creates exception + SLA · trigger **"GPS event"** |
| Owner ruling **Q3** | 2026-09-10, recorded in `ExceptionScope::RULINGS` | "wire `dispatched → in_transit` as a manual **Record departure** action (`departed_at`, `departed_by` columns only). Nothing beyond that — no `in_transit → delivered`, no GPS/telemetry/odometer/temperature, no automatic triggers." |
| `MS-001 §8` | Milestone plan | Dispatch: "Trip can progress through **controlled dispatch states**" · Delivery: "Delivery, feedback and POD can be recorded" |
| `MS-001 §9` | Milestone plan | Scenario 1 "Normal transport order from **creation to invoice**" · scenario 11 "Container search retrieves … delivery and billing information" |
| `MS-001 §14` | Milestone plan | Demo steps **9** "Show dispatch/trip progression", **11** "Record delivery, feedback and POD", **12** "Show Billing Ready or exact blocker" |

---

## 2. What I found reading the source, before proposing anything

### 2.1 Q3 is not a blocker. It is an authorisation, and half of it was never built.

Three code files and `TEAM-CONTRACTS` say STT-006 is *"blocked on the owner's Q1/Q3 ruling"* —
`TripStatus` (twice), `DispatchScope` (three times), `TransportDispatchController` (twice), `TEAM-CONTRACTS`.
**That text is stale.** The ruling landed on 2026-09-10 and it is an approval, verbatim:

> "Option (b) — build the Exception engine, and also wire `dispatched → in_transit` as a
> manual 'Record departure' action (`departed_at`, `departed_by` columns only)."

The columns were built — migration `2026_12_16_000013_add_departure_to_transport_trips`,
plus index `transport_trips_tenant_departed_idx`. **Nothing writes them.** Grep for
`departed_at` across the whole backend returns the migration, the scope constant and two
tests that assert the scope constant. No service, no controller, no route.

So `dispatched → in_transit` is authorised, its columns exist, its precondition
("Dispatch confirmed") has been satisfiable since Record Dispatch shipped a week ago —
and the edge is missing. **This is D-105.** It is the same shape as D-58: the work looks
done from the outside because the scaffolding is there.

### 2.2 Closure is unreachable, and the missing piece is not mine

`collection_pending` is the only state STT-012 leaves from. Walking backwards:

| Edge | Built? | Who | Reachable by a user? |
|---|---|---|---|
| `delivered → pod_verified` (STT-008) | yes | P3 | yes — `POST /trips/{id}/pod/{doc}/verify` |
| `pod_verified → billable` (STT-009) | yes | P3 | yes — `POST /trips/{id}/bill` |
| `billable → billed` (STT-010) | **model method only** | Accounts / P3 | **NO** |
| `billed → collection_pending` (STT-011) | yes | P3 | only if `billed` is reachable |

`TripBill::markInvoiced()` is the single door to `billed`, and **it has no caller** —
no service, no controller, no route. `TripCollectionService::open()` is honest about it:
it guards with `canTransition()`, so opening a collection on a `billable` trip creates the
collection row and correctly declines to move the status. The trip stays at `billable`.

**Therefore no trip in this system can currently reach `collection_pending`, and closure
built exactly to STT-012 would be correct and dead on arrival.** Logged as **D-106**,
raised to Zafar — not fixed by me, per the standing rule about another developer's files.

### 2.3 Step 9 and Step 11 diverge three more times, and the pattern is already settled in the code

Step 9 (the authority tier) gives sixteen states; Step 11 LOCKS transitions for twelve.
The four Step 9 states Step 11 has no edge for:

| Step 9 state | Has an entry gate anywhere? | A requirement that records it? | A data model? | Status today |
|---|---|---|---|---|
| `pretrip_ok` | **yes** — STT-005's precondition "All checks passed" | **yes** — OPS-007 pre-trip checklist | yes | **wired**, by the Q1 ruling of 2026-09-09 |
| `arrived` | no | no — RTM goes OPS-008 dispatch → OPS-009 track → OPS-010 delivery | n/a | declared, unreachable (**D-36**) |
| `pod_pending` | no | no | n/a | declared, unreachable — and P3's shipped STT-008 already skips it |
| `settlement_pending` | no | OPS/FRS TRP-P0-017 exists but is SNG-TRN-017 | **`trip_settlements` does not exist** | declared, unreachable |

The codebase already behaves consistently: **Step 9 wins on vocabulary, Step 11 wins on
edges, and a Step 9 state becomes reachable only when something can gate it.** `pretrip_ok`
is in because it had a gate and a requirement; the other three have neither. I would like
that written down as a standing rule rather than re-argued per ticket — see Q1 in §7. It
also closes D-36, which said SNG-TRN-014 must decide `arrived` and which SNG-TRN-014 did
not decide.

### 2.4 The two side effects I cannot perform, and will not pretend to

- **STT-006 "Start monitoring".** BR-P0-010's trigger is literally a *GPS event*; there is
  no GPS (SNG-TRN-020, P1) and `trip_exceptions` has schema and vocabulary but **no model
  and 0 rows**. Recording departure starts nothing. The screen will say so.
- **STT-012 "Snapshot profit".** `trip_profit_snapshots` **does not exist** (SNG-TRN-018,
  not built). EVT-012's consumer ProfitEngine has nothing to consume. The event will still
  be emitted, because the event is specified and the consumer's absence is not my licence
  to drop it.

### 2.5 EVT-012's idempotency key names a field that does not exist

`trip_id+close_version`. There is no `close_version` and no versions table — the exact
shape of D-65 (`approval_id` for EVT-004), where the ruling was **do not invent a table**.
Same answer here: closure is idempotent by state (`closed` is terminal, a second close is
refused with a sentence), and the gap is logged as **D-107** rather than papered over.

---

## 3. What I propose to build

Three transitions, in this order, each complete before the next starts.

### A — Record departure · `dispatched → in_transit` · STT-006

Authorised by Q3, columns already exist. Manual, because TRP-P0-011's own rule line grants
a **"manual update fallback"** and SM-TRP's entry gate is "Departure recorded", which a
person can do.

- `DispatchService::recordDeparture()` — writes `departed_at`, `departed_by`, walks the
  edge through `canTransition()`, audits it. **No other column**, per Q3's "nothing beyond that".
- Refuses: trip not `dispatched`; departure in the future; a second departure on a trip
  already `in_transit`; another tenant's trip (**404, not 403**); a user without the permission.
- `departed_at` may be **earlier** than now (a dispatcher records at 11:00 that the truck
  left at 09:30) but never earlier than `dispatched_at` — that would assert it left before
  it was released.
- Permission: **`transport.trip.dispatch`**, which already exists and is already derived
  from PERM-004's set. Recording that the vehicle actually rolled is the same dispatcher's
  same act, minutes later. No new key, no new matrix row.
- `PATCH /api/v1/transport/trips/{trip}/depart` — the registry has **no API row** for
  STT-006 (only 001–015, none of which is departure). Logged as **D-108**; the path follows
  the dispatch endpoints already next to it.

### B — Record delivery · `in_transit → delivered` · STT-007 · OPS-010

- Migration: `delivered_at`, `delivered_by` on `transport_trips`, plus
  `(tenant_id, delivered_at)` index — the same two-column shape and the same restraint as
  Q3 allowed for departure. **No `arrived_at`**, because there is no `arrived` state (§2.3).
- `TransportTripService::recordDelivery()` — same guards, same audit, plus: delivery cannot
  precede departure.
- **"Request POD"**, STT-007's side effect, resolves to a sentence and not to a new record.
  Reaching `delivered` **is** what unlocks P3's `POST /trips/{id}/pod/{doc}/verify`, and
  `TripDocumentService::billingReadiness()` already computes what is outstanding. The screen
  will say *"Proof of delivery is now required before this trip can be billed"* and link to
  step 6. Inventing a `pod_requests` table to satisfy a two-word side effect would be D-9 again.
- Permission: **derived**, D-8/D-45 precedent. RTM OPS-010's actor is **Operations**;
  FRS TRP-P0-013's is Driver/Delivery, but that row is POD capture (P3's), not the state
  change. Proposed `transport.trip.deliver` mirroring **PERM-004**'s set (Owner, Operations,
  Dispatcher, Admin) — the dispatcher who released the trip is the person the delivery call
  reaches. Driver is **N** here and **Own** under PERM-010 for the POD itself, which keeps
  P3's boundary intact. Recorded as a derived key, not a quoted one.
- `PATCH /api/v1/transport/trips/{trip}/deliver`. Also no API row — same D-108.

### C — Close trip · `collection_pending → closed` · STT-012 · API-009

Built exactly to the registry, **and honest that it is unreachable today** (§2.2).

- Migration: `closed_at`, `closed_by`, `closure_reason` (TEXT, CTR-013 requires it non-empty).
- `TransportTripService::close()` — runs the closure controls **first** (CTR-013's note),
  then walks the edge, then emits `TripClosed` (EVT-012, payload `trip_id, closure_timestamp`).
- **The closure controls, and what each one can honestly answer.** FRS TRP-P0-014's
  acceptance is *"User sees exactly why a trip is blocked"*, so each control returns a
  sentence, never a boolean:
  | Control | Source | Can we check it? |
  |---|---|---|
  | POD verified | STT-012, TRP-P0-014 | **yes** — P3's `billingReadiness()` |
  | Billing complete | STT-012 | **yes** — a `trip_bills` row with an invoice |
  | Collection settled | STT-012 "Settlement" | **yes** — `CollectionStatus::SETTLED` |
  | Supplier/driver settlement | TRP-P0-017 | **no — `trip_settlements` does not exist.** Reported on screen as *"not checked — supplier settlement is not built yet"*, never as a pass |
  | No unresolved critical exceptions | TRP-P0-014, **"no silent closure"** | **no — `trip_exceptions` has no model and 0 rows.** Same treatment. The rule's own words are "no *silent* closure"; saying the check did not run is the opposite of silent |
- **BR-P0-017's waiver is deferred**, consistent with every other override in this package
  (PLN-007, CMP-007, BRWM-049, and Q2's own `waived`). A trip that fails a control is
  refused with the reason. Nobody may waive until a ticket authorises and audits one.
- `POST /api/v1/transport/trips/{trip}/close`, permission **`transport.trip.close`** —
  both quoted from API-009, matrix row quoted from **PERM-005**, *including Dispatcher = N*,
  which is tested as a refusal exactly as PERM-003's denial is.
- New event class `App\Events\Transport\TripClosed`, matching the eight already there.

### D — The screen, and the timeline

- **`TransportTripDetail`** is an eight-step panel wizard. Block 3 adds **step 4 "On the
  road"** (record departure, then record delivery — one panel, two acts, because to a
  dispatcher it is one phase) and a final **step 9 "Close the trip"** carrying the control
  list and the reason box. Existing steps renumber; no step is removed.
- **`ContainerPassportService`** — `lifecycle()`, `status()`, `explain()`, `nextAction()`
  and `timeline()` all switch on trip status and currently stop at `dispatched`. Each gets
  the three new states, so Container 360 keeps answering "where is it and what happens next"
  instead of going quiet at the most interesting moment. This is what makes MS-001 §14
  step 9 and step 11 actually land on one screen.
- **`TransportDemoSeeder`** — the second demo trip walks one state further so the
  walkthrough shows a trip in transit. Through the real transitions, as now, and
  `assertDemoIsWhatItClaims()` extended to cover it.

---

## 4. Explicitly NOT in this block

| Left out | Why |
|---|---|
| GPS / telemetry / geofence / idle / route deviation | SNG-TRN-020, P1. Q3: "no GPS/telemetry/odometer/temperature, no automatic triggers" |
| The exception engine (TRP-P0-012, BR-P0-010) | Schema and vocabulary shipped; **no model**. Blocked on D-29/D-30 and on GPS. A transit exception has no trigger |
| SLA / escalation on transit | Q5 — wall-clock only, business calendar does not exist (D-34) |
| Trip cancellation (OPS-011) | **P1**, not P0, and no state for it in any machine |
| Breakdown (OPS-012) | P0, but it is an *exception*, and the exception engine is blocked |
| `arrived`, `pod_pending`, `settlement_pending` | §2.3 — no gate, no requirement, no model. Declared, unreachable |
| Profit snapshot (STT-012 side effect) | `trip_profit_snapshots` does not exist. SNG-TRN-018 |
| `billable → billed` | **Not mine.** P3/Accounts. Raised as D-106, not fixed |
| Customer/driver feedback (MS-001 §14 step 11 says "delivery, feedback and POD") | **SILENT** — no feedback entity, field or requirement ID exists anywhere in the package. Asked as Q4 rather than invented |

---

## 5. Coverage check — all three ways

Run at the end, and the answers below are the *targets*, not claims.

1. **Against the documents.** Every ID in §1 maps to a file and a line, or to a row in §4
   with a reason. A requirement with neither is a failure of this block.
2. **Can a user actually reach it?** For each endpoint: a route in the staff group, a
   permission that resolves, a control on the screen, and a click path from the trip list.
   **A and B will pass. C will fail on this question, and the document will say so** — that
   is the point of asking it. This question is what caught D-58.
3. **Against my own proposal.** This file, diffed against what shipped, with every
   difference explained. Including anything I said I would build and did not.

---

## 6. Honest estimate

| Part | Files | Notes |
|---|---|---|
| A — departure | ~6 (service, request, controller, route, permission test, feature test) | Columns and permission already exist. Smallest piece |
| B — delivery | ~9 (+ migration, + derived permission, + frontend) | |
| C — closure | ~11 (+ migration, + event, + closure-control service, + frontend) | Most of the cost is the control list and its refusals |
| D — screen, passport, seeder | ~5 | `ContainerPassportService` touches five private methods |
| Docs | 3 (register D-105…D-108, coverage doc, note to Zafar) | |

**~34 files, and one full working day at the current rhythm** — a morning for A and B
together, an afternoon for C and the screen, with the suite run and compared by name before
the merge. C is the part most likely to grow, because every control that cannot run needs
its own sentence and its own refusal test.

---

## 7. Open questions — I need four answers before writing code

**Q1. The Step 9 / Step 11 rule, as a standing rule.**
*"Step 9 wins on vocabulary; Step 11 wins on edges. A Step 9 state becomes reachable only
when a document defines something that can gate it."* This is what the code already does
(`pretrip_ok` in, `arrived`/`pod_pending`/`settlement_pending` out). Confirming it closes
**D-36** and stops the next person re-arguing it three more times.
**My recommendation: yes.**

**Q2. Closure is unreachable (§2.2). Which way?**
 (a) Build A and B now; hold C until Zafar gives `billable → billed` a door. Nothing dead ships.
 (b) Build all three; C is correct, tested, and unreachable until D-106 is fixed.
 (c) I build the door myself — **I do not recommend this**; `trip_bills` is P3's table and
     the standing rule is not to fix another developer's file.
**My recommendation: (b), with the coverage check stating plainly that C fails question 2
until D-106 lands.** The registry work is right either way, the tests prove it, and it
stops being dead the day Zafar adds one route. But (a) is the safer answer if you would
rather nothing ship that a user cannot reach — say which and I will follow it.

**Q3. BR-P0-017's waiver.** It is the only P0 Hard rule in this block with an override
written into its own definition ("or explicit waiver", Owner role) — the same thing that
made D-30 a close call. Deferred like every other override, so a failed control refuses?
**My recommendation: defer, refuse.**

**Q4. "Feedback", MS-001 §14 step 11.** No entity, no field, no requirement ID anywhere in
the package — this is the first time the word appears. Out of scope and logged as a gap,
or is there a source I have not been given?
**My recommendation: out of scope, logged.**

---

## 8. Defects this block raises

Next free in the P1 band (D-100…):

| | |
|---|---|
| **D-105** | Q3 authorised `dispatched → in_transit` on 2026-09-10 and built its columns; nothing writes them, and four documents still call it "blocked" |
| **D-106** | `billable → billed` has no caller and no route, so `collection_pending` — and therefore `closed` — is unreachable by any user. **P3's surface.** Raised, not fixed |
| **D-107** | EVT-012's idempotency key `trip_id+close_version`; no `close_version` and no versions table. Same shape as D-65 |
| **D-108** | STT-006 and STT-007 have no API_Registry row. Every other trip transition has one |

---

**No code written. Awaiting approval, and answers to Q1–Q4.**
