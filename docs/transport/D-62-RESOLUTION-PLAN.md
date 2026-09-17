# D-62 — Resolution plan: two vehicle/driver systems

**Status:** P2's half EXECUTED 2026-09-17 · **Needs:** P1 to retire the placeholder files (§3 step 5)
**Reads with:** `docs/transport/registry-defects.md` (D-62) and `docs/transport/TEAM-CONTRACTS.md` (§1a)

> **If you are an AI assistant picking this up:** read this whole file before touching any
> vehicle or driver code. It explains a live data split, who owns which half, and the exact
> order the two halves must be joined in. Doing the steps out of order loses rows.

---

## 0. What has been done, 2026-09-17

Executed by P2 on `shivam/raise-ticket-modules`. Not yet merged to master.

| Step | State |
|---|---|
| Schema union — `vehicles` gains every identity column Operations had, including `capacity_tonnes` | **done** — `2027_01_02_000001` |
| Data move + FK remap, refusing ambiguous plates | **done** — `2027_01_02_000002`, 10 tests |
| C-05 `FleetResourceGateway::markDispatched()` — the stub addressed to Person 2 | **done** — 12 tests |
| C-06 fuel / urea / maintenance costs → `trip_costs` | **done** — 7 tests |
| One module in the UI: fleet screens moved into `modules/transport/fleet/`, one nav rail, one sidebar section, duplicate marketplace card removed | **done** |
| Retire `transport_vehicles` / `transport_drivers` and their files | **P1's** — see §3 step 5 |

**Both suites green together: 1,075 passing, 0 failures** (`tests/Feature/Stos` + `tests/Feature/Transport`).

One of P1's tests was updated rather than deleted: `DispatchTest::test_the_shipped_gateway_declines_and_says_so` asserted that the *placeholder* was what shipped. That assertion is obsolete now the handover is complete, so it asserts Fleet's gateway instead — and keeps the case that still matters, that an unmigrated vehicle is reported honestly rather than silently passing.

---

## 1. The short version

There are **two vehicle/driver systems running at once**:

| | P1 — Operations | P2 — Fleet |
|---|---|---|
| Tables | `transport_vehicles`, `transport_drivers` | `vehicles`, `driver_profiles` + CRM directory |
| API | `/api/transport/vehicles`, `/api/transport/drivers` | `/api/v1/fleet/...` |
| Read by | allocation, pre-trip, dispatch | telemetry, fuel, urea, tolls, tyres, workshop, compliance |

**This is not a competition between two modules.** P1's Transport module (orders, trips,
consignments, containers, dispatch) and P2's Fleet module (telemetry, cost, maintenance,
compliance) are two halves of one system and both must survive. **Only the vehicle and driver
slice overlaps**, and that overlap is already settled in writing.

**The ruling is not a matter of opinion — it is already recorded.** `TEAM-CONTRACTS.md` §1a:

> `transport_vehicles` and `transport_drivers` are **DB-004 and DB-005 — Fleet, owned by P2**
> under TM-001 §8. What is live on master today is a **temporary P1 implementation, held
> deliberately and with an agreed end.**

So: **Fleet absorbs Operations' vehicle/driver placeholder** (D-62's Option 1). P1 built the
placeholder to keep the demo walkable before Fleet existed. Fleet now exists. The handover that
was always planned simply has to be finished.

---

## 2. Why Fleet is the survivor — the concrete reasons

Not "it is newer" or "it is nicer". These are the things that break if the placeholder wins:

1. **Seven tables key off `vehicles`.** `vehicle_live_status`, `telemetry_records`,
   `fuel_transactions`, `urea_transactions`, `fastag_transactions`, `maintenance_jobs`,
   `tyre_fitments`, plus `gensets` and `driver_profiles`. Choosing `transport_vehicles` as the
   master means re-pointing or discarding every one of them, along with the GPS ingestion
   pipeline, the excursion rule, the fuel-variance engine and the workshop release gate.
2. **The placeholder duplicates people; Fleet does not.** `transport_drivers` is its own table
   of names and phones. Fleet holds **no demographic columns at all** — it reads the CRM's
   customer/vendor directory live through `DriverDirectory` and stores only a reference plus
   licence and availability. Golden rule 3 (no duplicate master data) is satisfied by one of
   these designs and violated by the other.
3. **Fleet runs standalone; the placeholder cannot.** `DriverDirectory` has two adapters
   (`CrmDriverDirectory`, `StandaloneDriverDirectory`) chosen by one binding that auto-detects
   whether the CRM is present. This is what lets STOS ship both as a CRM module and as its own
   tenant application. The placeholder has no such seam.
4. **Compliance lives on the Fleet side.** The five statutory expiry dates, the nightly
   `stos:refresh-compliance` sweep, the derived `compliance_status`, and the driver licence
   gate are all in Fleet. The pre-dispatch gate that Operations needs is already built — on
   the table we are proposing to keep.
5. **The contract P1 needs already exists.** `FleetService::getEligibleVehicles()` was written
   for exactly this call. P1 queried his own table because Fleet was not ready yet.

**What P1 loses: nothing that is his.** Orders, trips, consignments, containers, dispatch,
pre-trip, documents, policies and the audit log all stay exactly where they are. Only the two
placeholder tables retire.

---

## 3. The safe sequence — do not skip a step

Rows already exist in both systems on production, so **this is no longer a code-only change**.
Each step below is reversible on its own, and nothing is deleted until the end.

### Step 0 — Freeze the split from widening (do this first, today)
- Hide or disable **creating** a vehicle/driver in the Operations screens. Leave reading intact.
- Every new vehicle from now on is created in Fleet (`POST /api/v1/fleet/vehicles`).
- *Why first:* every row written to the losing table is another row to migrate.

### Step 1 — Reconcile what exists
Run a comparison before migrating anything:

```bash
php artisan tinker --execute="
\$ops = DB::table('transport_vehicles')->pluck('registration_number','id');
\$fleet = DB::table('vehicles')->pluck('registration_number','id');
\$norm = fn(\$p) => preg_replace('/[^A-Z0-9]/','',strtoupper(\$p));
\$o = \$ops->map(\$norm); \$f = \$fleet->map(\$norm);
echo 'ops='.\$o->count().' fleet='.\$f->count().PHP_EOL;
echo 'in both (match on plate): '.\$o->intersect(\$f)->count().PHP_EOL;
echo 'ops only (must migrate): '.\$o->diff(\$f)->implode(', ').PHP_EOL;
"
```
Registration number is the join key — normalised to letters and digits, because
`MH 12 AB 1234` and `MH12AB1234` are the same truck. **Resolve every "ops only" plate by hand
before Step 2.** Do not auto-merge; a wrong match silently attaches one truck's fuel history to
another.

### Step 2 — Migrate the rows
Write a one-off migration that, per `transport_vehicles` row with no Fleet match, inserts into
`vehicles` **and** initialises its `vehicle_live_status` row in the same transaction (Fleet's
invariant is one live row per vehicle, created with the vehicle). Field mapping in §4.
For `transport_drivers`: do **not** copy names into Fleet. Match each driver to a person in the
CRM directory and create a `driver_profiles` row holding the reference plus their licence. A
driver with no directory match needs a person created in the CRM first — that is the point of
the design.

### Step 3 — Repoint the readers  *(P1, still open)*
Change Operations' allocation, pre-trip and dispatch code to call the published contract instead
of querying `transport_vehicles`:

```php
// Before
$vehicles = TransportVehicle::where('company_id', $companyId)->where('status','AVAILABLE')->get();

// After — eligibility, compliance and driver licence already evaluated
$result = app(FleetService::class)->getEligibleVehicles($companyId, $vehicleType, [
    'pickup_lat' => $lat, 'pickup_lng' => $lng,   // optional, enables proximity ranking
]);
// $result['eligible']  — scored, each with `reasons` and `flags`
// $result['excluded']  — each with `blockers`: why it cannot be used, and whose desk fixes it
```

The vehicle id in the response is the **Fleet** `vehicles.id`. Anything storing a vehicle id
(e.g. `transport_trips.vehicle_id`) must be remapped in Step 2's migration at the same time.

### Step 4 — Make the old tables read-only, then watch
- Leave `transport_vehicles`/`transport_drivers` in place but writable by nothing.
- Log any code path that still reads them for one full week.
- *Why:* if something was missed, it shows up as a log line rather than a broken screen.

### Step 5 — Retire
Only after a clean week: drop the models, controllers, routes and screens, then the tables.
Keep the migration that created them (never edit another developer's migration — write a new
one to drop).

### Rollback
Steps 0–1 are reversible instantly. Step 2 is reversible while the old tables still exist —
which is why Steps 4 and 5 are deliberately last and a week apart.

---

## 4. Field mapping (Operations → Fleet)

| `transport_vehicles` | `vehicles` | Note |
|---|---|---|
| `registration_number` | `registration_number` | **Normalise** to A–Z0–9 uppercase; this is the join key |
| `vehicle_type` | `vehicle_type` | ⚠️ vocabularies differ — see §5 |
| `ownership_type` | `ownership_type` | ⚠️ same |
| `chassis_number` / `engine_number` | same | Unique per company in Fleet |
| `gps_device_id` | `gps_device_id` | Unique per company — two vehicles cannot share a device |
| `status` | *(not copied)* | Derived in Fleet: job cards move a vehicle in and out of the workshop |
| `compliance_status` | *(not copied)* | Derived from the five expiry dates by `ComplianceService` |
| — | `registration_expiry`, `insurance_expiry`, `fitness_expiry`, `permit_expiry`, `puc_expiry` | Fill if Operations holds them; blank reads as "not recorded" and does not block |

| `transport_drivers` | Fleet | Note |
|---|---|---|
| name, phone, address | **not copied** | Must exist in the CRM directory; Fleet resolves them live |
| licence number / class / expiry | `driver_profiles` | Plus `assigned_vehicle_id` for the regular driver |
| duty status | `driver_profiles.status` | `available` / `on_trip` / `suspended` / `inactive` |

---

## 5. Two decisions to take while doing this

1. **Enum vocabulary (open as T-51 in `docs/audit/STOS-DEV2-BUILD-TASKLIST.md`).** The spec
   wants UPPERCASE (`REEFER`, `AVAILABLE`, `BILLABLE`); Fleet stores lowercase. Settle it in the
   same migration rather than twice — this touches both sides.
2. **Does an expired driver licence block allocation, or score it down?** Fleet currently
   **scores it to zero and flags `DRIVER_LICENSE_EXPIRED`**, so the vehicle is still offered —
   the truck is roadworthy, and swapping drivers is a smaller decision than standing it down.
   The spec reads as a hard block. It is one branch in `VehicleAllocationService`; P1 should
   choose, because P1's board consumes the result.

---

## 6. Who does what

| Step | Owner |
|---|---|
| 0 — freeze creation in Operations | P1 |
| 1 — reconcile plates | P1 + P2 together (neither can verify the other's rows alone) |
| 2 — migration | P2 (Fleet's schema and invariants) |
| 3 — repoint allocation/pre-trip/dispatch | P1 (his call sites) |
| 4–5 — watch and retire | P1 |
| §5 decisions | P1 + P2, recorded back in `registry-defects.md` |

P3 (Zafar) raised D-62 and merged the duplicate sidebar entries. **It is not P3's to decide** —
correctly noted in the defect record.

---

## 7. What NOT to do

- **Do not delete `transport_vehicles` before Step 4's clean week.** Trips point at those ids.
- **Do not copy driver names into `driver_profiles`.** That re-creates the duplicate-master-data
  problem Fleet was built to remove; the copy goes stale the first time the CRM is corrected.
- **Do not auto-match plates.** Normalise, then have a person confirm the list.
- **Do not merge the menus and call it done.** That was fixed on 2026-09-17 and it makes the
  split *less visible* without joining any data — the tidy menu is the one risk of having fixed
  it, as the defect record says.
