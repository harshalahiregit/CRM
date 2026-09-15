# Analysis — the Three-Developer Split document vs the repository

**Author:** Person 1 · **Date:** 2026-09-15 · **Status:** analysis only — *nothing moved, created or renamed*
**Document:** `~/Desktop/Sangoe_Transport_OS_Three_Developer_Split.pdf` (2026-09-12, 952 lines extracted)
**Defect:** D-47

> **Read §B first if you read nothing else.** The folder names are the least important thing in this
> document. There are **seven behavioural requirements** in it that we do not have, and three of them
> contradict decisions already shipped.

---

## A. What the document actually says (corrected against the brief)

The brief describing it said `app/Domains/Operations|Fleet|Finance`. The document says something
different on every count:

| Brief said | Document says |
|---|---|
| `app/Domains/` (plural) | **`app/Domain/`** (singular) |
| Operations · Fleet · Finance | **Flow · Resources · Outcome** |
| Developer 1/2/3 | **DEV A · DEV B · DEV C** |

**DEV A = FLOW = us.** Its five sub-modules are Commercial & Orders, Consignment 360, Operations &
Dispatch, Driver Mobile APIs, AI Gateway — 27 points, 12 tables.

Two of those are **not in the Person 1 brief at all**:

- **AI Gateway** — the Person 1 brief assigns AI to Person 3 ("AI Gateway — that is Person 3"). This
  document assigns it to DEV A, worth 3 points, tables `ai_configurations`, `ai_requests`, `ai_logs`.
- **Driver Mobile APIs** — 3 points, "no new tables", read/write over trips. Absent from the brief.

Also note the copy that reached us is addressed to **"Developer 2 (You)"**, so it is a briefing
written for that role and handed to us as reference. Whether it binds Person 1 is a question.

---

## B. The behavioural requirements — the part that is NOT cosmetic

Folder names are a rename. These are not.

### B1. Six named interfaces, with fixed signatures

| Interface | Owner | Used by | Methods |
|---|---|---|---|
| `FleetGateway` | B | **A** | `availableVehicles(criteria): VehicleSummary[]`, `findVehicle(id): ?VehicleSummary` |
| `DriverGateway` | B | **A** | `availableDrivers(criteria): DriverSummary[]`, `findDriver(id): ?DriverSummary` |
| `ComplianceGateway` | B | **A** | `checkDispatch(vehicleId, driverId): DispatchVerdict` |
| `FleetCostGateway` | B | C | `costsForTrip(tripId): CostLine[]` |
| `TripGateway` | **A** | B, C | `findTrip(id): ?TripSummary` |
| `DocumentGateway` | C | **A** | `billingReadiness(tripId): BillingReadiness` |

**Four of the six touch us**, and one — `TripGateway` — **we own and have not built**. Person 2 and
Person 3 are supposed to reach our trips through it.

**We have `FleetResourceGateway`** (`markDispatched(...)  : bool`), which I invented under the Fleet
boundary ruling. It appears in none of the six. Its name, its method and its return type all differ.

### B2. `DispatchVerdict` must be an object, not a boolean — and we return a boolean

The document is explicit, with the class written out:

```php
final readonly class DispatchVerdict {
    public function __construct(
        public bool    $allowed,
        public array   $blockingReasons,   // ['Vehicle fitness expired 2026-08-14']
        public ?string $overriddenBy = null,
        public ?string $overrideReason = null,
    ) {}
}
```

and its reasoning:

> "A boolean would force Developer A to invent the user-facing message for a failure they cannot see
> the cause of. Returning the reasons means the dispatch screen can say 'Vehicle fitness expired
> 14 Aug' without A knowing anything about how compliance works."

**This is the same argument we made independently** for BRW-048 and UX §35, and it is the reasoning
behind `PretripService::revalidate()`'s `blockers` / `lapsed` arrays. Our conclusion agrees; our
*contract shape* does not — `FleetResourceGateway::markDispatched()` returns `bool`.

### B3. Seven named events with fixed payloads

| Event | Emitted by | Consumed by | Listener does |
|---|---|---|---|
| `TripDispatched` | **A** | B | start telemetry for that vehicle and trip |
| `TripDelivered` | **A** | C | activate the POD requirement, start its SLA clock |
| `TripClosed` | **A** | C | freeze the profit snapshot |
| `TelemetryBreachDetected` | B | C | open a temperature incident, raise a CAPA |
| `BillingReady` | C | C | internal |
| `MaintenanceNeeded` | C | B | create a maintenance job from a CAPA action |
| `ManpowerGapDetected` | B | B | internal |

**We are the emitter of three.** We emit none of them today — `DispatchService` writes an audit row
and logs, but fires no event. `app/Events/Transport/` does not exist.

This is cheap to add and it is the thing that *decouples*: "the event fires into an empty room and
the system is perfectly healthy."

### B4. `App\Domain\Shared\` — a jointly-owned frozen kernel

`Contracts/` (6), `Data/` (readonly DTOs: `VehicleSummary`, `DriverSummary`, `DispatchVerdict`,
`BillingReadiness`, `CostLine`, `TripSummary`), `Events/`, `Enums/`
(`TripStatus`, `DispatchDecision`, `IncidentSeverity`, `DocumentType`), `Stubs/`.

**`TripStatus` is named as a SHARED enum.** Ours is `App\Support\Transport\TripStatus`, carries 16
states and all the D-17 rulings in its comments, and is referenced throughout. Moving it into a
kernel that needs **three approvals to change** would put every future state ruling behind a
three-person gate.

### B5. Two CI checks, written out as shell

An **ownership guard** (a PR touching files outside the author's lane fails) and an **architecture
lint** (`! grep -rE 'use App\\Domain\\(Resources|Outcome)\\' app/Domain/Flow/`).

We could adopt the *spirit* of the second today without any move — see §D.

### B6. Migration numbers partitioned per developer

`_1NNN_` for A, `_2NNN_` for B, `_3NNN_` for C. Ours are `2026_12_16_0000NN` — outside the scheme.
Renaming a migration that has already run is a separate hazard (the `migrations` table records the
old name).

### B7. Week-zero contract freeze

Three days, all three developers, no features: write the six interfaces, the DTOs, the enums, a stub
per interface in **both allow and block modes**, bound in one `SharedStubServiceProvider`.

**This has not happened.** Our `PendingFleetResourceGateway` is the same idea arrived at
independently, for one interface out of six.

---

## C. Three options, costed against 30 September (15 days)

### Option 1 — Keep TEAM-CONVENTIONS, defer the structure to after the milestone

| | |
|---|---|
| Files moved | 0 |
| Test risk | none |
| Blocks | nobody |
| Others must move | no |
| Cost | 0 days |

Block 1 continues. The **behavioural** items in §B can still be adopted selectively (see §D) — they
do not require the folder layout.

### Option 2 — Adopt the structure for Transport only, now

| | |
|---|---|
| Files moved | **88** under `app/` + **27** test files |
| Namespace edits | **543 occurrences across 106 files** |
| Frontend | 14 files reference `modules/transport` / `transportApi` |
| Test risk | **High.** 671 tests, every one resolving a moved class |
| Blocks | Block 1 stops for the duration |
| Others must move | **Yes, unavoidably** |
| Cost | **3–5 days**, and that is the optimistic read |

**This option is not actually available to Person 1.** `app/Models/Transport/` holds
`TransportVehicle.php` and `TransportDriver.php` — Person 2's under TM-001 §8. Splitting Transport
into Flow and Resources *is* the move, and it cannot be done by one person without crossing the
boundary we have held all week. The document's own ownership guard would fail the PR.

It also leaves the repository in a **third** state: 29 modules on one convention, one on another,
and the two conventions' CI guards contradicting each other.

### Option 3 — Adopt across all 18+ modules

| | |
|---|---|
| Files moved | Transport is 88 of roughly **1,000+** PHP files under `app/` across 30 model folders |
| Test risk | **Severe.** The full suite is ~2,700 assertions; 32 already fail for unrelated reasons |
| Blocks | **All three developers, simultaneously** |
| Others must move | **Yes — at the same time, or the repo does not compile** |
| Cost | **2–4 weeks**, conservatively |

Against 15 days to a milestone whose exit condition is "the chain runs without manual database
intervention", this ends the milestone.

---

## D. Recommendation

**Option 1 for the structure. Adopt §B's behavioural items now, selectively, where they cost days
rather than weeks.**

Grounds:

1. **The folder layout is the cheapest part of the document to adopt later and the most expensive to
   adopt now.** A namespace is a rename; it can be done in a quiet week with a green suite on both
   sides. The contracts and events are the part that actually decouples three developers, and they
   are adoptable *without* moving a file.

2. **Person 1 cannot execute the move alone.** Person 2's models sit in the folder. This is not
   reluctance — it is the same boundary rule that has governed every decision since D-39.

3. **Deferring costs almost nothing later.** Our layout is already one-module-per-folder with a
   service layer, thin controllers and no cross-module concrete references. That is the shape the
   document wants; only the path differs.

4. **Three of the seven behavioural items contradict shipped decisions** and need a ruling regardless
   of the folder question — `TripGateway` (unbuilt, and ours to build), `DispatchVerdict` (we return
   a bool), and `TripStatus` as a shared enum (ours carries the D-17 rulings).

### What I would adopt in the next few days, at low cost

| Item | Cost | Why now |
|---|---|---|
| Emit `TripDispatched`, `TripDelivered`, `TripClosed` | ~½ day | We own all three. They block nobody and decouple Person 2 and Person 3 immediately — "fires into an empty room" |
| Build `TripGateway` + `TripSummary` | ~½ day | **We own it and it does not exist.** Person 2 and Person 3 need it to read our trips without touching our tables |
| Reshape the Fleet contract toward `DispatchVerdict` | ~½ day | Our reasoning already matches; only the shape differs. Doing it before Person 2 writes the real gateway avoids a second migration of the seam |
| Architecture lint, adapted to current paths | ~1 hour | Enforces "no cross-module concrete references" against `app/Models/Transport` etc. today — the spirit of B5 with none of the move |

### What needs a ruling before anyone builds it

1. **Does this document bind Person 1 at all?** The copy is addressed to "Developer 2 (You)".
2. **AI Gateway** — Person 3 per the brief, DEV A per this document. Both cannot be true.
3. **Driver Mobile APIs** — in neither the brief nor the ticket register. New scope.
4. **`lr_records` / `delivery_orders`** appear as DEV A tables — **D-41 ruled the opposite**, that LR
   and DO stay documents and those tables are not built. A ruling that reverses D-41 should say so
   explicitly.
5. **Table naming.** The document lists `consignments`, `containers`, `trips`, `vehicles` — no
   `transport_` prefix. We shipped `transport_consignments` three commits ago and 13 migrations
   before it.

---

## E. What I have NOT done

No `app/Domain/` or `app/Domains/` directory created. No file moved. No namespace renamed. No
migration renumbered. Nothing in `app/Models/Transport/` touched.

Block 1 continues on the current structure until the owner rules.
