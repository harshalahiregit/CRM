# Coverage — Block 3: transit, delivery, closure

**Person 1.** Built 2026-09-17 against `PLAN-block3-transit-delivery-closure.md`, approved the
same day. Checked three ways, as ruled.

**Result up front:** transit, delivery and closure are all **BUILT**.

> **Updated 2026-09-19.** Closure shipped as **PLUMBED** — correct, tested, routed, and reachable
> by nobody. Person 3 closed **D-106** on 19 September and it became **BUILT** with no change on
> this side, exactly as §2 predicted. A trip has since been walked `delivered → closed` by
> clicking, so this is observed, not inferred. §2 is kept below as written, because the useful
> part of it is the argument, and the argument was tested by events.
>
> **Updated 2026-09-21.** Closure is now BUILT *by clicking*, which is a stronger claim than the
> one made on the 19th. That walk reached `billed` by posting to the route by hand, because no UI
> control called it. Person 3 shipped the *Record invoice* button on the 21st and
> `TRP-2026-000035` was taken **delivered → POD verified → ready to invoice → invoiced →
> collection → closed entirely from the screen.** Nothing in the closure code changed for either
> step.
>
> The walk also produced the best evidence this block has: **the close was refused.** A trip that
> was delivered, POD-verified, invoiced and paid in full was told *"1 critical exception is still
> open on this trip — TRP-P0-014"*, and stayed at `collection_pending` until the exception was
> resolved through the panel. The control that spent a day claiming it could not run has now
> stopped a real close.

---

## 1. Against the documents — does every requirement ID have code behind it?

| ID | Source | Where it landed |
|---|---|---|
| `STT-006` | Step 11, LOCKED | `TripStatus::TRANSITIONS[dispatched]`, `DispatchService::recordDeparture()` |
| `STT-007` | Step 11, LOCKED | `TripStatus::TRANSITIONS[in_transit]`, `TransportTripService::recordDelivery()` |
| `STT-012` | Step 11, LOCKED | `TripStatus::TRANSITIONS[collection_pending]`, `TripClosureService::close()` |
| `SM-TRP` entry gates | Step 11, LOCKED | "Departure recorded" and "Destination confirmed" are the two manual acts; `closed` is terminal and has no reverse |
| `API-009` | Step 11, LOCKED | `POST /api/transport/trips/{trip}/close` — path, method and permission key all quoted |
| `CTR-013` | Step 11 | `CloseTripRequest` (required, min 12) **and** `TripClosureService::close()` — enforced twice |
| `PERM-005` | Step 11, LOCKED | `TransportPermission::TRIP_CLOSE`, all nine columns tested, **Dispatcher denied** |
| `EVT-012` | Step 11, LOCKED | `App\Events\Transport\TripClosed` — payload `trip_id, closure_timestamp`, nothing beyond |
| `STOS-REQ-OPS-009` | RTM, P0 | departure + the lifecycle now walkable end to end |
| `STOS-REQ-OPS-010` | RTM, P0 | delivery |
| `FRS TRP-P0-011` | Step 3, P0 | **"manual update fallback"** only — the GPS half is SNG-TRN-020 |
| `FRS TRP-P0-014` | Step 3, P0 | the five closure controls, each returning a sentence |
| `BR-P0-017` | Step 3, Hard | controls block; the waiver is **deferred with a reference and an owner** |
| `MS-001 §14` step 9 | Milestone | "dispatch/trip progression" — the tracker now runs seven steps, not five |
| `MS-001 §14` step 11 | Milestone | delivery ✓ · POD (P3) ✓ · **feedback — NOT MINE**, see §4 |
| `MS-001 §14` step 12 | Milestone | "Billing Ready or exact blocker" — the closure panel is exactly this |

**Requirements with no code, and why:** none silently. Every one is either above or in §4.

---

## 2. Can a user actually reach it? — the question that caught D-58

For each endpoint: a route in the staff-only group, a permission that resolves, a control on the
screen, and a click path from the trip list.

| | Route | Permission | On screen | Click path | **Verdict** |
|---|---|---|---|---|---|
| **Record departure** | `PATCH /trips/{trip}/depart` | `transport.trip.dispatch` (existing, PERM-004's set) | Trip detail, **step 4 "On the road"** | Trips → open → step 4 → Record departure | **BUILT** |
| **Record delivery** | `PATCH /trips/{id}/deliver` | `transport.trip.deliver` (derived, PERM-004's set) | same panel, second act | Trips → open → step 4 → Record delivery | **BUILT** |
| **Closure readiness** | `GET /trips/{trip}/closure` | `transport.trip.view` | **step 10 "Close the trip"** | Trips → open → step 10 | **BUILT** |
| **Close the trip** | `POST /trips/{trip}/close` | `transport.trip.close` (API-009 verbatim) | same panel | Trips → open → step 8 → Close this trip | **BUILT** ✅ |

### Why closure was PLUMBED and not BUILT — and what happened to it

`collection_pending` is the only state STT-012 leaves from, and nothing in the application can
reach it. `TripBill::markInvoiced()` is the single door into `billed` and **it has no caller and
no route**. That is P3's table, raised as **D-106** in
`REQUEST-person3-invoice-door.md`, and deliberately not fixed here.

Three things make this honest rather than a fudge:

1. `ClosureScope::REACHABLE = false`, asserted by a test.
2. `TripClosureTest::test_nothing_in_the_application_can_reach_collection_pending` scans `app/`
   and `routes/` for a caller and **fails the day one appears**, telling whoever sees it to flip
   the constant and close D-106. Proven to fire — a probe caller was added, the test failed
   naming the file, and the probe was removed.
3. The panel prints the reason on screen instead of showing a dead button.

**The day P3 adds one route, closure becomes BUILT with no change on this side.**

#### That day was 2026-09-19, and the prediction held exactly

Person 3 shipped `POST /trips/{id}/bill/invoiced`. Closure became reachable with **no change to
any closure code**: the constant flipped, the panel followed the flag it was already reading, and
`TRP-2026-000034` was walked to `closed` in the browser.

Two notes on how the three honesty devices actually behaved, since both are worth more than the
prediction:

- **The scan in device 2 did not fire, and that needed investigating rather than celebrating.**
  Person 3 flipped `REACHABLE` and reversed the scan's assertion in the same commit, so the suite
  stayed green and the tripwire never rang. That is the correct outcome and it is also
  indistinguishable from a blind guard. It was checked the only way that settles it: the original
  assertion was restored against the current tree, and it failed, naming
  `TransportBillingController.php` and `TripBillingService.php`. Not blind — it had simply been
  answered before it could ring.
- **Device 1 had gone stale in a way none of the three covered.** `NOT_BUILT_REASONS['exceptions']`
  still said the exception register was not built, a day after it was. So the closure screen
  reported that **TRP-P0-014 could not be checked** while the check was, in fact, buildable and
  unbuilt into the flow — the rule was unenforced behind a sentence claiming it was unenforceable.
  Fixed, with three tests. The lesson is that these devices guard *code that does not exist yet*
  and say nothing about *text that describes code that now does*.

---

## 3. Against my own proposal — what shipped differently, and why

| Plan said | What shipped | Why |
|---|---|---|
| "~34 files" | **41** | Two extra scope classes (`TransitScope`, `ClosureScope`) rather than folding both into one, plus a third test file for permissions. The permission row deserved its own file: PERM-005 has nine columns and three of them map to no Sangoe identity. |
| "one full working day" | one working day | held |
| Step 4 "On the road", step 9 "Close the trip" | step 4 **and step 10** | The plan mis-counted: there were eight steps, so inserting one makes nine and appending one makes ten. |
| `ContainerPassportService` — "five private methods" | **two** (`explain`, `nextAction`) | `lifecycle()`, `chain()` and `timeline()` are status-agnostic and needed nothing. I had assumed they switched on status without checking. |
| A `TripClosureService` for the controls | as planned | |
| Departure in `DispatchService`, delivery in `TransportTripService` | as planned | |
| No `arrived`, `pod_pending`, `settlement_pending` | as planned, and now a **standing rule** in TEAM-CONTRACTS | The owner generalised my recommendation from one state to the rule, which closes D-36 for good. |
| Seeder: "second demo trip walks one state further" | the **first** trip walks **two** further, to `in_transit` | I named the wrong trip in the plan. And one state was not enough: the report line has always called that trip "crewed and moving", and only `in_transit` makes that true. |
| — not in the plan — | **the demo's planned-departure date had to change** | The new guard refused the seeder: it backdated a departure to `now()->subDay()`, which precedes the release. The guard was right and the demo's dates were the lie. Now released today, left today, due in two days. |
| — not in the plan — | **five existing tests updated** | Four snapshot guards fired on the new edges (`TransportAllocationTest`, `PretripGateTest`, `PretripScopeTest`, `PretripEvidenceAuditTest`) and one asserted the stale "blocked" claim (`DispatchTest`). That is those guards doing their job; each was re-pointed at the invariant it actually protects. |
| Q4: "feedback — out of scope, logged" | **NOT MINE, cited to TM-001 §8, raised to P3** | I was wrong, and the correction matters: "out of scope" reads as a decision somebody made; "yours, and nobody has specified it" is a gap with an owner's name on it. |
| Q3: "defer the waiver" | deferred **as a specified behaviour**, not an unspecified one | The owner drew the distinction. Every other override we have refused was undefined; BR-P0-017 names its waiver and names the Owner role. So the refusal message says the waiver is *not built yet* and never that none exists — a user told "this cannot be waived" when the rule says it can is being misled by our screen. |

**Nothing in the plan was dropped.**

---

## 4. What is NOT here, and whose it is

| | Why | Whose |
|---|---|---|
| GPS / telemetry / geofence / idle / route deviation | SNG-TRN-020, P1. Q3 forbids it explicitly | P1, later |
| Exception engine (TRP-P0-012, BR-P0-010) | schema and vocabulary shipped, **no model**; blocked on D-29/D-30, and no trigger without GPS | P1, blocked |
| Transit SLA | Q5 — wall-clock only; no business calendar (D-34) | P1, blocked |
| Trip cancellation (OPS-011) | **P1 not P0**, and no state for it in any machine | later |
| Breakdown (OPS-012) | P0, but it is an *exception* | blocked above |
| `arrived`, `pod_pending`, `settlement_pending` | no gate, no requirement, no model — standing rule | closed, D-36 |
| Profit snapshot (STT-012's side effect) | `trip_profit_snapshots` is not a table; SNG-TRN-018 | P1, later |
| `billable → billed` | **D-106** — not ours to fix | **P3** |
| **Feedback** (MS-001 §14 step 11) | **TM-001 §8: "Feedback / Complaints / CAPA → Person 3, Quality"**, and no entity, field or requirement ID for it exists anywhere in the package | **P3**, and unspecified |

---

## 5. Defects raised

| | |
|---|---|
| **D-105** | STT-006 was authorised 2026-09-10, its columns and index shipped, and nothing wrote them for a week while four documents called it blocked. **Fixed**, and all four documents corrected in the same change. |
| **D-106** | `billable → billed` has no caller and no route, so nothing can reach `collection_pending` or `closed`. **P3's. Raised, not fixed.** |
| **D-107** | EVT-012 keys on `trip_id+close_version`; no such field. Same shape as D-65 — nothing invented; terminal state serves instead. |
| **D-108** | STT-006 and STT-007 have no API_Registry row at all. Paths derived from convention and recorded as derived. |
| **D-36** | **Closed** by the standing Step 9 / Step 11 rule. |
| **C-09** | **Closed** — P3's POD edge now has trips arriving in `delivered`, with no change on their side. |

---

## 6. Evidence

- **Transport + unit suites: 1311 passed, 3 skipped, 0 failed** (4,811 assertions).
- Full backend suite: 4,765 passed, **9 failed — all nine pre-existing**, in Branding, Contract
  and Task. Verified by name against a clean checkout of master: eight fail identically there;
  the ninth (`TaskCommentTest`, an `ini_set('memory_limit')` test) passes in isolation on both
  and fails only under full-suite memory pressure. The Block 3 change set touches nothing
  outside `*/Transport/*`, `routes/transport.php`, the two migrations and the frontend
  transport module.
- Frontend: `vite build` clean, 705 precache entries.
- Both new guards proven to FIRE, not merely to pass:
  - the D-106 caller scan — a probe caller was added and it failed naming the file;
  - the departure time guard — it refused the demo seeder's own backdated departure, which is
    how the demo's contradictory dates were found.
