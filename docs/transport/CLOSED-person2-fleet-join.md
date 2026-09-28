# CLOSED — To Person 1, answering "how should allocation read Fleet?"

**Answered by:** Person 2 (Shivam) · **Date:** 2026-09-17
**Status:** **Answered and built.** Person 1 has pulled and verified it; his steps 3–5 are in flight.
**Reads with:** `D-62-RESOLUTION-PLAN.md` (the sequence) and `TEAM-CONTRACTS.md` §1a (the ownership ruling)

> This file was `REQUEST-person2-fleet-join.md`. It asked which of three shapes the join should
> take. The answer is below, and it is not one of the three — it is the first two together, and
> both halves are built. Kept rather than deleted because the question was a good one and the
> reasoning is worth having on record.

---

## The answer: both shapes, because they were never alternatives

The request offered "migrate our rows into your tables" **or** "expose a contract we read". Those
solve different halves of the same problem and doing only one leaves the other broken:

- **Migrating the rows** without a contract gives Fleet the data and leaves allocation still
  querying `transport_vehicles` — now an empty table.
- **Exposing a contract** without migrating gives allocation a door into Fleet and leaves the
  demo trucks stranded on the other side of it.

So: **the schema is unioned, the rows move, and the contract is published.** All three are on
master.

### 1. The union — your columns were not discarded

`2027_01_02_000001_unify_vehicle_and_driver_masters.php`

`vehicles` gained every identity column Operations had that Fleet lacked: `capacity_tonnes`,
`fleet_number`, `manufacturer`, `model`, `variant`, `manufacturing_year`, `purchase_date`,
`fuel_type`, `branch`. Your table was not the poorer one — Fleet had no payload column at all,
which is the field `VehicleEligibilityService` matches an order against.

### 2. The data move — and what it deliberately refuses

`2027_01_02_000002_move_transport_masters_into_fleet.php`

Moves each `transport_vehicles` row, initialises its `vehicle_live_status` row in the same
transaction (Fleet's invariant is one live row per vehicle), and remaps `transport_trips.vehicle_id`,
`transport_trips.driver_id` and `trip_assignments` — the columns you left nullable and FK-free for
exactly this.

**It refuses ambiguous plates rather than guessing.** Where a normalised registration matches more
than one Fleet vehicle, the row is left alone and reported. A wrong match silently attaches one
truck's fuel and maintenance history to another, and nothing downstream would ever reveal it.

Run `php artisan stos:reconcile-fleet` after migrating: it lists what moved, what did not, and which
Fleet vehicles each unresolved plate ambiguously matches. It writes nothing — resolving an ambiguity
is a decision for a person.

> **This command did not exist when the migration first landed**, although the migration told you to
> run it. Person 1 caught that. It is `app/Console/Commands/ReconcileFleetMasters.php` now.

### 3. The contracts — both already answered

| | |
|---|---|
| **Vehicles** | `FleetService::getEligibleVehicles($companyId, $vehicleType, ['pickup_lat' =>, 'pickup_lng' =>])` |
| **Drivers** | `DriverService::eligible($companyId)` · `GET /v1/fleet/drivers/eligible` |
| **Dispatch** | `FleetResourceGateway` — **already bound to Fleet**, see below |

Both return the same shape, so one board component renders trucks and crew:

```
eligible[] — scored, with reasons[] and flags[]
excluded[] — with blockers[{ code, why, owner }]
```

`owner` names the desk that can clear each blocker, so a dispatcher is routed rather than merely
told "blocked".

**C-05 needs nothing from you.** `TransportFleetResourceGateway` is bound in `StosServiceProvider`,
which registers after `TransportNumberingServiceProvider`, so it already replaces
`PendingFleetResourceGateway` — the stub whose TODO was addressed to Person 2. There is no line to
change on your side.

**C-06** publishes fuel, urea, toll and workshop costs into Person 3's `trip_costs`.

---

## Answering the three things "only you can do"

**Do your tables take our rows, or do we start clean?** They take them. Demo data included — the
alternative was Fleet Status reading "No vehicles yet" while the trucks sat in a table it does not
read, and you were right not to seed copies to paper over that.

**What identifies a vehicle and a driver?** `vehicles.id` and `driver_profiles.id`. The migration
remaps your columns to them, and `legacy_transport_vehicle_id` / `legacy_transport_driver_id` record
where each row came from, so the move is auditable and a second run cannot duplicate it.

A driver is **not** a row of demographics. `driver_profiles` holds no name, phone or address — it is
a reference into the CRM directory plus a licence and availability. That is why a driver with no
directory match needs a person created in the CRM first: it is the design, not a gap.

**What can Fleet answer about allocatability?** More than the old enums could. Compliance is derived
from five statutory expiry dates rather than a typed status; a safety-critical job card blocks
allocation; `on_another_trip` prevents double allocation; and proximity, fuel efficiency and
utilisation are scored when telemetry can support them. Everything unmeasurable stays `null` rather
than defaulting to zero, so a screen can say "not measured" instead of implying a bad score.

---

## One thing the request got right that changed my design

The follow-up question — *does an expired driver licence block allocation, or score it down?* — was
answered by Person 1 with "neither, the question is the wrong shape", and he was right.

A licence belongs to the driver, not the truck. Fleet had been flagging `DRIVER_LICENSE_EXPIRED` on
the **vehicle** and scoring the truck down, which quietly offered a dispatcher a worse vehicle to
solve a problem that handing the keys to somebody else fixes in seconds.

It is now a hard blocker on the **person**, from `DriverService::eligible()`. The vehicle stays
fully eligible and unflagged. Rebuilt the same day the ruling arrived.

---

## Still open, and whose

| | |
|---|---|
| Repoint allocation / pre-trip / dispatch onto the contracts | **Person 1** — in flight |
| Read-only week, then retire the placeholder files | **Person 1** — his files, his deletion |
| Whether driver *availability* should also come off the vehicle score | **Person 1** — see `D-62-RESOLUTION-PLAN.md` §5 |
| UPPERCASE vs lowercase enum vocabulary across both modules | **Owner** — T-51, affects all three of us |
| `app/Domains/` vs `app/Models/Transport` — two structures live | **Owner** — D-47, nothing moves before 30 Sept |

Defect numbering noted: **Person 2 takes D-200 onward.**
