# The real flow, and the only part of it that is Dev 2's

**Owner:** Shivam (Person 2 — Fleet, Asset & Telemetry) · **Written:** 2026-09-23
**Companion to:** `STOS-PROCESS-FLOW-AND-OWNERSHIP.md` (ownership) and
`docs/audit/STOS-DEV2-BUILD-TASKLIST.md` (the tickable list)

This file exists so the flow does not have to be re-derived from three specifications every time,
and so that **what is ours is separated from what merely appears near us.** Read §5 first if you
are short of time — that is the worklist.

---

## 1 · The rule that makes the whole map simple

Both specifications say the same thing in their own words:

> **CLP §1** — "The portal is not a second transport-management database. It is a controlled
> presentation and transaction layer connected to the same STOS records."
>
> **DVR §2** — "The Driver App is an interface into STOS; it is not a separate source of truth."

So **no screen ever sends data to another screen.** Every hop is:

```
screen writes a record → the record's state moves → an event fires → other screens re-read it
```

One state machine — **SM-TRP**, the trip, 11 states. Three interfaces are windows onto it:
internal STOS, the client portal, the driver app. That is why "which screen sends to which screen"
never had a clean answer: **the trip's state is the messenger.**

For Fleet specifically: **I never hand Operations a vehicle.** The dispatcher asks
`FleetService::getEligibleVehicles()`, the trip records what came back, and every other screen
reads the trip.

---

## 2 · The spine — 11 states and who moves each one

| Trip state | Moved by | From which screen | What appears elsewhere |
|---|---|---|---|
| `draft` | OPS | Internal → Orders | Client Requests flips to accepted |
| `viability_pending` | system | — (auto) | Control Tower + Alerts only — **never the client** |
| `approved` | approver | Internal → Orders | Notifications |
| **`allocated`** | dispatcher | Internal → Fleet / Drivers | Driver App gets the job · Client sees permitted vehicle/driver fields |
| **`dispatched`** | dispatcher | Driver pre-trip passes | Client Today's Ops |
| **`in_transit`** | departure recorded | Driver App | Client Trips in Transit, Control Tower |
| `delivered` | destination confirmed | Driver App → Unloading | Client Trip View |
| `pod_verified` | Documents | Driver uploads → Internal verifies | Client Documents vault unlocks |
| `billable` | Person 3 | Internal → BillingPanel | Client Billing shows "ready" |
| `billed` | Accounts, P3 records it | Accounts raises → `markInvoiced()` | Collections, Control Tower |
| `collection_pending` → `closed` | Person 3 | Internal → Collections, Close | Client Payment M14, ProfitEngine |

**Bold rows are the three Fleet moves.** Everything below `in_transit` is somebody else's.

## 3 · The twelve points where data actually crosses apps

| Writer screen | Record | Event | Lands on |
|---|---|---|---|
| Client → New Request | booking | *(none canonical)* | Internal Orders inbox |
| Internal Orders → accept | `transport_orders` | EVT-001 OrderCreated | TripEngine, Notifications |
| Internal → create trip | `transport_trips` | EVT-002 TripCreated | Viability, Notifications |
| *(auto)* viability | revenue/cost/margin | EVT-003 | ControlRoom, Alerts — client blocked (§23) |
| Internal → approve | — | EVT-004 | TripEngine |
| **Internal Fleet → assign** | `trip_assignments` | **EVT-005 TripAssigned** | Driver App + Client Portal (first 3-way) |
| **Driver → Fuel/Expense** | `trip_advances` / `trip_expenses` | **EVT-006 / 007** | Approval → Accounts. Client never sees it |
| **Driver / Client / Internal → raise** | `trip_exceptions` | **EVT-008** | All three at once |
| Driver → POD | `trip_documents` | EVT-009 PODReceived | → BillingEngine |
| Accounts → invoice | invoice | EVT-010 InvoicePosted | Collections, ControlRoom |
| P3 → collection | `trip_collections` | EVT-011 | ControlRoom |
| P3 → close | — | EVT-012 TripClosed | ProfitEngine |

**GPS is not a screen.** M09 transit arrives from the device via `POST /integrations/gps/events`.
DVR §11: *"Driver cannot fabricate or edit GPS coordinates."* The driver's Transit Mode screen
**displays** telemetry, it never authors it. Fleet's `TripTimelinePublisher` is what puts it on the
timeline all three windows read.

**The client portal is almost write-only at the start.** It opens the chain with a request and then
watches. Its only other writes are gate confirmations, CAPA and feedback.

---

## 4 · What Step 11 does and does not authorise

Step 11 is the canonical registry for tables, APIs, states and events, and it **outranks CLP and
DVR** under the authority hierarchy. Term counts across the whole of it:

```
trip 131 · POD 26 · milestone 0 · container 0 · portal 0 · feedback 0 · geofence 0
```

All 20 tables (DB-001–DB-020) are internal transport tables. All 15 endpoints are
`/api/v1/transport/*` internal, plus GPS and e-way-bill ingest. **Not one is portal-scoped or
driver-scoped.**

So the M01–M14 spine, Container 360, the Client Portal and the Driver App have **no canonical
table, no event, no endpoint and no state machine.** CLP §25 lists `milestone.completed` and
`billing.ready`; they exist nowhere in the registry.

**The operating rule: when CLP or DVR names an event, check Step 11 before building to it.**

### What this forbids us from starting

- **M12 Feedback** — no table, no event, no endpoint, and two competing producers (driver at final
  handover per DVR §20, client per CLP M12). This is what B-09 means by *don't build from CLP
  narrative.*
- **M06 / M07 / M08 gate-arrival, loading, sealing** — two writers, no referee. CLP §3 gives them
  to the client's Warehouse/Gate role, DVR §9 gives the same three to the driver. **On the
  decisions list; my name is on it.** My position: both record, neither overwrites — a milestone is
  an *observation*, not a state, so two timestamps is not a conflict and the trip moves on the
  first. If it is ruled single-writer, the rule must say what happens when that writer is absent,
  or we ship blank milestones and call it a bug.
- **Geofencing / port and gate events** — `geofence 0`, and parked with hardware anyway.

---

## 5 · The filter — what is Dev 2's, and what only looks like it

### ⛔ The trap in the flow map, stated plainly

The section headed **"Where your four sit"** — EVT-009 PODReceived, `billingReadiness()`,
`BillingPanel.jsx`, EVT-010 InvoicePosted, EVT-012 → ProfitEngine, SNG-TRN-018 — **is Person 3's
work, not ours.** That map was written for Documents & Billing. Reading "your four" as ours would
put days into the tail of the belt that Zafar already owns.

**Fleet's inbound trigger is not EVT-009. It is EVT-005 TripAssigned**, and our tail ends at
`in_transit`.

### ✅ Fleet's actual surface in this flow — four touchpoints, all built

| Flow point | Fleet's part | State |
|---|---|---|
| `allocated` / EVT-005 | `getEligibleVehicles()` + `DriverService::eligible()` feed the dispatcher; `markDispatched()` → vehicle `ALLOCATED` | built |
| `dispatched` | pre-trip passes; gateway call | built |
| `in_transit` | `markDeparted()` → vehicle `IN_TRANSIT`, same event as "Record departure" | built |
| M09 transit | ingest → `telemetry_records` + `TripTimelinePublisher` → 5 events on the timeline | built |
| release | `markReleased()` → `AVAILABLE` | built |

**Nothing in the flow map requires new Fleet integration.** It changes how we *describe* the work,
not what it is. That is the main finding of this whole exercise and it is good news.

---

## 6 · The worklist — what is actually left for Dev 2

Cross-checked against the code on 2026-09-23, not against the tasklist's own claims.

### Stale entries corrected

| Tasklist says | Reality |
|---|---|
| T-01 "capacity column does not exist" | **`vehicles.capacity_tonnes` EXISTS** (added by the D-62 union). Capacity work is a filter, not a migration. |
| T-05 genset serial "no form field" | `vehicles.genset_serial` still **missing**; T-50 records the panel as done. The column is the gap. |
| T-41 medical expiry | confirmed **missing** |
| T-36 `tyre_masters`, T-54 `trailers` | confirmed **missing** |

### Do now — small, unblocks other people, owner-visible

1. **Capacity matching in eligibility.** Both columns exist (`vehicles.capacity_tonnes`,
   `transport_orders.required_capacity_tonnes`) and nothing matches them, though PLN-001 says
   eligibility must. This is the `allocated` hop — the one the owner asked about. **Filter +
   blocker only. Promised to P1 this week.**
2. **T-43 driver documents.** The single thing keeping half P1's driver controller alive; blocks
   his deletion list. Mirrors T-57: write through `TransportDocumentService`, project a verified
   `driving_license` onto the driver's licence expiry.
3. **T-58 enums to UPPERCASE** (genset, tyre, job card). Database enums, so spec 12.S11 applies.
   The internal half P1 left to us; his API half is already lowercase.

### Do next — the Driver App reads these, so they are real work with a consumer

4. **T-41 `medical_expiry`** — judged exactly like the licence, on the driver card and compliance
   tab. Driver App "Vehicle & Pre-Trip Readiness" and "Document Checklist" both read it.
5. **T-42 `inactive` → `ON_LEAVE`** — "on leave" and "no longer with us" are different facts and
   the roster cannot tell them apart today.
6. **T-05 `genset_serial` column** — the panel exists, the column does not.
7. **T-06 `generator_status`** — align with the spec or document the mapping in `STOS-API`.

### Fuel / expense hop (EVT-006) — ours, and half-done

8. **T-17 L/KM** beside the stored km/l — same fact inverted, but L/KM is the figure people quote.
9. **T-19 per-vehicle fuel benchmark** overriding the per-type default.
10. **T-18 `recovery_status` vocabulary** — ⚠️ **needs Dev 3 first**: `BILLED` is his transition to
    set. Agree ownership before renaming.

### Bigger, real, and wanted by CLP §5

11. **T-54 trailers as their own master** with `vehicle_trailer_assignments`. A trailer is **not** a
    `vehicles` row — own compliance profile, tyre set, maintenance record, coupling kept
    historically. **CLP §5 asks the client for trailer type and container type/size; three of those
    four have nowhere to land in Fleet today.** Until this exists, the order records what the
    client asked and it does not reach eligibility.
12. **T-36/37/38 tyres** — `tyre_masters`, rotation, tread-wear forecast.
13. **T-49 idle-vehicle and utilisation reporting** for the executive tower.

### Blocked on someone else — do not start

- **T-53 expiry-as-projection** — needs Dev 3's verify workflow live. Sequencing matters: removing
  the manual input before the projection runs leaves no way to record a compliance date at all.
- **T-18** — see above.

### 🛑 Parked with hardware — record, do not build

`T-25 / T-26` FASTag import and reconciliation (needs the toll provider feed, not a device),
`T-55` route/movement anomaly (needs real pings), geofencing. All in
`docs/transport/HARDWARE-DEPENDENCIES.md`. **T-27** (the FASTag screen) is buildable without the
feed but pointless before it.

---

## 7 · Standing rules this flow does not change

- Fleet owns the vehicle and driver **masters**; Operations owns the trip. One entry point per
  master — **no second "add vehicle" anywhere.**
- Database enums and state-machine states are **UPPERCASE**; API blocker codes and machine reasons
  are **lowercase snake_case** (spec 12.S11).
- A **condition warns, a state excludes.** Service-due, licence expiry and telemetry staleness are
  conditions. Getting this wrong has blocked the wrong object three times in this module.
- Verified document → Fleet's expiry columns is a **projection, not a master**.
- Telemetry publishes on **change**, never per ping. `telemetry_records` is the trail; the timeline
  is the story.
