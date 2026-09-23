# STOS — Developer 2 Build Task List
**Fleet, Asset & Telemetry Lead · `STOS-FLEET` · `STOS-INT` · `STOS-COST` · `STOS-MAINT` · `STOS-CMP`**

Owner: Shivam (Developer 2) · Last verified against the running code: **2026-09-17**

---

## How to use this file

This is the single source of truth for what Developer 2 builds. Every requirement from the
master specs is written out here as a task with its **exact input fields, column types,
validation rules and business logic** — so nothing has to be reconstructed from a chat log.

**Status marks, and what they are allowed to mean:**

| Mark | Meaning |
|---|---|
| ✅ | Built, wired end to end, and covered by a passing test. Named test given. |
| 🟡 | Partly built. What is missing is stated explicitly — never "mostly done". |
| ⬜ | Not built. No column, no endpoint, no screen. |
| ⚠️ | Decision needed from the team before it can be built correctly. |

**The no-mock rule.** A task is only ✅ when it is real all the way down:

1. A migration exists and has run.
2. The service enforces the rule (not the controller, not the screen).
3. An HTTP endpoint exists behind the correct auth and tenancy guard.
4. A screen uses the **real** API response field names — no placeholder data, no fake
   success toast, no "coming soon" button that does nothing.
5. A feature test asserts the behaviour, including the failure paths.

Anything that cannot yet be real is marked ⬜ or ⚠️ and says why. A disabled control with an
honest label is acceptable; a control that pretends to work is not.

> 🛑 **Hardware is parked.** Anything needing a GPS box, temperature probe or genset sensor is
> NOT being built. Every hardware touchpoint is recorded in
> `docs/transport/HARDWARE-DEPENDENCIES.md` instead — read it before picking up a telemetry task.

**Current position:** Stos + Transport together: **1,100 passing, 0 failures**. Full backend
suite verified green on 2026-09-17: **4,641 passing, 0 failures, 3 skipped**.

---

## 1. Domain boundary

**Owned by Developer 2** — build freely:

| Sub-module | Scope |
|---|---|
| `STOS-FLEET` | Vehicle, trailer, genset and tyre registries; status transitions; driver overlay; asset readiness |
| `STOS-INT` | Provider-neutral GPS, reefer probes, genset state, live tracking |
| `STOS-COST` | Diesel, urea/AdBlue, emergency purchases, consumption variance, FASTag tolls |
| `STOS-MAINT` | Workshop job cards, diagnosis, itemised parts/labour, downtime, tyre fitments |
| `STOS-CMP` | Document expiry tracking (RC, insurance, fitness, permit, PUC, driver licence) gating dispatch |

**Not ours — never create tables for these:** trips and dispatch (Dev 1), orders, invoicing,
billing, QC/CAPA (Dev 3), and the people directory (the CRM already owns personhood).

**The three rules that constrain every task below:**

1. Every table carries an indexed `company_id`, resolved from the **session** — never from a
   query string, request body or URL.
2. Money is `DECIMAL(18,2)`, latitude `DECIMAL(10,8)`, longitude `DECIMAL(11,8)`. Never floats.
3. Two-tier telemetry: `vehicle_live_status` (one row per vehicle, overwritten) stays separate
   from `telemetry_records` (append-only). Hardware writes never touch a master table.

---

## 2. Sub-module 1 — Vehicle & asset master (`STOS-FLEET`)

### 2.1 Input fields

| Field | Type / rule | Status |
|---|---|---|
| Registration number | `VARCHAR(40)`, required, uppercased and stripped to A–Z0–9, unique per company | ✅ |
| Vehicle type | Dropdown: `REEFER`, `CONTAINER_BODY`, `FLATBED`, `TRAILER` | 🟡 built as `truck\|trailer\|tipper\|tanker\|reefer\|lcv\|other` — see T-02 |
| Ownership type | Dropdown: `OWNED`, `FINANCED`, `LEASED`, `CONTRACTED`, `ATTACHED` | 🟡 `FINANCED` and `CONTRACTED` missing; extra `market` — see T-03 |
| Chassis number | `VARCHAR(100)`, optional, unique per company when present | 🟡 column is `VARCHAR(50)` |
| Engine number | `VARCHAR(100)`, optional | 🟡 column is `VARCHAR(50)` |
| **Capacity / payload** | `DECIMAL(8,2)`, tons | ✅ column, form field, passport, **and matched against the order** (PLN-001) — T-01 |
| GPS device id | `VARCHAR(100)`, unique per company — one device reports for one vehicle | 🟡 column is `VARCHAR(64)` |
| **Genset serial number** | `VARCHAR(50)`, shown only when type = REEFER | ⬜ table exists, no form field and no endpoint — T-05 |
| `registration_expiry` | Date | ✅ |
| `insurance_expiry` | Date | ✅ |
| `fitness_expiry` | Date | ✅ |
| `permit_expiry` | Date | ✅ |
| `puc_expiry` | Date | ✅ |

**Not an input field:** `status` and `compliance_status` are **derived**. The API rejects an
attempt to set either. A vehicle leaves the road via a job card and returns via its release;
the compliance verdict comes from the five dates above.

### 2.2 Status vocabulary

Spec: `AVAILABLE`, `ALLOCATED`, `IN_TRANSIT`, `IDLE`, `MAINTENANCE_DUE`, `UNDER_MAINTENANCE`,
`BREAKDOWN`, `COMPLIANCE_BLOCKED`.
Built: `active`, `in_maintenance`, `idle`, `retired` — plus a computed operational state
(`moving`, `idle`, `offline`, `maintenance`, `compliance_blocked`, `unmonitored`, `retired`).

`ALLOCATED` and `IN_TRANSIT` are **trip facts** and cannot be set by this module — they arrive
when Dispatch does. `MAINTENANCE_DUE` and `BREAKDOWN` are ours and are missing. See T-04.

### 2.3 Tasks

- [x] **T-00** `vehicles` table, soft deletes, unique plate per company · *VehicleOnboardingTest*
- [x] **T-00b** Onboarding creates the vehicle **and** initialises its `vehicle_live_status` row in one transaction, so ingestion is a pure primary-key update for the asset's life
- [x] **T-00c** Retire = soft delete, blocked while job cards are open, frees the genset, keeps fuel/job history
- [x] **T-01** Payload and identity are reachable. The D-62 union put `capacity_tonnes`, make/model/variant, year, purchase date, fuel type, branch and fleet number on `vehicles`; none were fillable, so every vehicle onboarded through Fleet came out blank — and a blank `capacity_tonnes` is invisible to Operations' capacity matching. Now in the model, the request rules, the onboarding form and the passport, with the passport saying so when payload is missing · *VehicleIdentityTest*
- [x] **T-02** — **RULED by the owner, 2026-09-19.** The vehicle asset state machine is `AVAILABLE / ALLOCATED / IN_TRANSIT / UNDER_MAINTENANCE / COMPLIANCE_BLOCKED / IDLE / BREAKDOWN / RETIRED`, and Fleet is its sole authority. Not just a rename: `in_operation` **split** into ALLOCATED and IN_TRANSIT (an allocated truck can be swapped, a departed one is a recovery problem), and COMPLIANCE_BLOCKED became a state that only the compliance sweep applies. See `docs/transport/STOS-PROCESS-FLOW-AND-OWNERSHIP.md` §4
- [ ] **T-03** Add `FINANCED` and `CONTRACTED` ownership values; decide whether `market` folds into `CONTRACTED`
- [x] **T-04** — built as **two different kinds of thing**, which is a deliberate divergence from how this task was worded. **`breakdown` IS a state**: a job card naming a `trip_id` is a breakdown on the road, not a booked workshop slot, and a planner reading "in the workshop" assumes a return time. It excludes from allocation with its own blocker and owner (Operations, not the workshop). **`maintenance_due` is NOT a state** — a truck past its interval is still roadworthy, and writing that into `status` drops it out of allocation, so a missed oil change silently takes a truck off the road. That is the same category error Person 1 caught in the driver licence. It is derived by `ServiceScheduleEvaluator` from a km and/or day schedule, warns via `SERVICE_OVERDUE` / `SERVICE_DUE_SOON`, and never blocks · *ServiceScheduleAndBreakdownTest*
- [x] **T-05** Genset master — register / edit / fit / unfit endpoints, the conditional serial field on the reefer onboarding form (which previously just *told* people to fit it from the passport later), and a fit/swap/remove panel on the passport. Serials normalised like plates, so `GS-0051` and `GS0051` cannot become two entries for one unit. A unit already fitted elsewhere is **moved**, not refused — that is what happens when one fails on the road · *GensetRegisterTest*

---

## 3. Sub-module 2 — Telemetry ingestion (`STOS-INT`)

### 3.1 Hardware payload — `POST /api/v1/telemetry/ingest`

```json
{
  "device_id": "GPS-TRACKER-9921",
  "latitude": 19.076090,
  "longitude": 72.877426,
  "speed": 45.5,
  "ignition": true,
  "generator_status": "off",
  "temperature": -12.0,
  "recorded_at": "2026-09-16 22:00:00"
}
```

| Field | Rule | Status |
|---|---|---|
| `device_id` | Required. Resolves the vehicle — a device the fleet does not know is refused with 404, never silently dropped | ✅ |
| `latitude` / `longitude` | `between:-90,90` / `-180,180`. A dead fix reporting 999 is rejected, not stored | ✅ |
| `speed` | `0..400` km/h | ✅ |
| `ignition` | Boolean, nullable — "the device did not say" is its own state | ✅ |
| `generator_status` | `off\|on\|standby\|fault` | 🟡 spec says `ON/OFF/UNKNOWN`; ours is richer. Map at the boundary or align — T-06 |
| `temperature` | `-60..80` °C, signed | ✅ |
| `recorded_at` | Required, rejected more than 10 min in the future (a wrong device clock would otherwise top every query forever) | ✅ |

**Auth:** hardware has no session. A shared secret in `X-Device-Token`, checked with
`hash_equals`, **failing closed** when unconfigured (503). Per-device credentials are the real
answer — T-07.

### 3.2 Tasks

- [x] **T-08** Two-tier write in one transaction: always append to `telemetry_records`; update `vehicle_live_status` only when the ping is the newest held · *TelemetryIngestionTest*
- [x] **T-09** Replayed buffers (a unit leaving a tunnel) are kept in history but never drag the live row backwards
- [x] **T-10** Excursion rule — genset OFF ∧ speed > 0 ∧ temp > −18 °C → `telemetry.temperature_excursion.detected`
- [x] **T-11** `GET /v1/fleet/vehicles/{id}/live-status` with three-state GPS health (`active` / `degraded` / `offline`)
- [ ] **T-06** Align `generator_status` values with the spec, or document the mapping in `STOS-API`
- [x] **T-07** Per-device tokens — issue / rotate / revoke, SHA-256 hashed, plaintext shown once, `stos_dev_` prefix so a leaked one is identifiable on sight. **It also fixes the 409:** a token carries its company, so a device id held by two companies now resolves instead of being refused. The fleet-wide secret still works and is deprecated; the listing names every unit still relying on it, which is the migration checklist. Rotation issues before revoking, so re-flashing a unit is not an outage · *DeviceTokenTest*
- [x] **T-12** Ingest is idempotent. **Decision taken: dedupe on write**, enforced by a unique index on `(company_id, device_id, recorded_at)` — one device has one clock, so the same instant is the same reading. Keep-all-and-dedupe-on-read was rejected because it makes every future consumer of the trail responsible for de-duplicating forever, and the first one that forgets double-counts a journey. A retry is answered 201 with `duplicate: true`, never 409: a device told 409 by a retry it could not avoid either retries forever or drops its buffer · *TelemetryIdempotencyAndBatchTest*
- [x] **T-13** Batch ingest — `POST /v1/telemetry/ingest/batch`, up to 500 readings. Sorted by the device's own clock before writing, because the live row only moves forward; and one bad reading is rejected on its own line rather than failing the batch, because a device cannot resend just the good ones · *TelemetryIdempotencyAndBatchTest*

> ⚠️ **Known trade in the excursion rule.** `speed > 0` silences a reefer parked with its
> genset deliberately off — and *also* silences a **loaded trailer standing in a yard with a
> dead genset**, which is real spoilage. It is behind `stos.telemetry.excursion_requires_motion`
> so it can be flipped the day Dispatch can tell us a vehicle is loaded.

---

## 4. Sub-module 3 — Fuel & emergency diesel (`STOS-COST`)

### 4.1 Input fields — `POST /v1/fleet/vehicles/{id}/fuel`

| Field | Type / rule | Status |
|---|---|---|
| Vehicle | From the URL | ✅ |
| Odometer | Numeric, **must exceed the previous fill** — equal is refused too | ✅ |
| Litres | `DECIMAL(12,3)` — a pump prints 3 decimals; rounding to 2 breaks reconciliation | ✅ |
| Rate per litre | `DECIMAL(18,2)` | ✅ |
| Total amount | `DECIMAL(18,2)`, auto-computed but editable — the printed bill wins | ✅ |
| Station / vendor | `VARCHAR(150)` | ✅ |
| `is_emergency` | Checkbox | ✅ |
| Emergency reason | Required when `is_emergency` — somebody must answer for it | ✅ |
| Customer recoverable | Yes / No / **Not decided** — three states, shown only for an emergency | ✅ |
| Receipt image | File or camera, stored on the **private** disk and served through the API | ✅ |

### 4.2 Business rules

- Consumption is computed **at entry** from the odometer gap and stored. It is not an accessor:
  correcting an old fill must not silently rewrite every figure after it.
- The **first** fill on a vehicle has no previous odometer, so no distance, no efficiency and no
  exception — flagging it would put an exception on every new truck on day one.
- Outside tolerance → `fuel_exception = true` plus a `variance_note` that states what was
  expected and what happened, e.g. *"1.2 km/l against a benchmark of 2.8 — 57% below, over 240 km."*
- Emergency + recoverable → `recovery_status` reaches Dev 3's billing engine; **undecided never
  defaults to billable**, because that quietly writes off money the customer owes.

### 4.3 Tasks

- [x] **T-14** Fuel entry, odometer guard, variance flag, receipt upload · *FleetOperationsTest*
- [x] **T-15** `fuel.emergency_issued` published on an emergency fill
- [x] **T-16** `GET /v1/fleet/fuel/exceptions` — the variance and emergency register
- [ ] **T-17** Report **L/KM** as the spec defines it (`litres ÷ km`) beside the stored km/l. Both are the same fact inverted, but the spec's figure is the one people quote — expose it explicitly rather than making a reader invert it
- [ ] **T-18** ⚠️ `recovery_status` vocabulary: spec `NOT_REVIEWED / BILLABLE / NON_BILLABLE / BILLED` vs built `not_applicable / pending / billable / recovered / waived`. **`BILLED` is Dev 3's to set** — agree who owns the transition before renaming
- [ ] **T-19** Per-vehicle fuel benchmark overriding the per-type default in `config/stos.php`

---

## 5. Sub-module 4 — Urea / AdBlue (`STOS-COST`)

| Field | Type / rule | Status |
|---|---|---|
| Vehicle | From the URL | ✅ |
| Odometer | Numeric, must exceed the previous top-up | ✅ |
| Litres | `DECIMAL(12,3)` | ✅ |
| Total cost | `DECIMAL(18,2)` | ✅ |
| Supplier / source | `VARCHAR(150)` | ✅ |
| `trip_id` | Nullable, indexed, **no FK** — Dispatch owns trips | ✅ |

- [x] **T-20** `urea_transactions` table, endpoint, and L/100 km consumption · *FleetContractsAndAssetsTest*
- [x] **T-21** Kept out of `fuel_transactions` — mixing urea into diesel corrupts every km/l figure
- [x] **T-22** Consumption outside the 0.8–4.0 L/100 km band is flagged on the passport, per row and as a count. The band travels with the readings rather than being hardcoded in the screen · *UreaConsumptionBandTest*
- [x] **T-23** Urea entry screen — `UreaTopUpModal`, reached from the passport's Urea card. Rate fills the amount, the amount stays editable, and an entry with no odometer says plainly that it will not be measured

---

## 6. Sub-module 5 — FASTag toll reconciliation (`STOS-COST`)

| Field | Type / rule | Status |
|---|---|---|
| Tag id / registration | `VARCHAR(64)` | ✅ column |
| Plaza name | `VARCHAR(150)` | ✅ column |
| Transaction timestamp | `DATETIME` (not `TIMESTAMP` — avoids MySQL's legacy auto-update rewriting a crossing time on reconcile) | ✅ column |
| Amount | `DECIMAL(18,2)` | ✅ column |
| `reconciliation_status` | Spec `PENDING / MATCHED / ANOMALY` | 🟡 built `unreconciled / matched / disputed / settled` |

- [x] **T-24** Table, idempotency key `(company, tag, timestamp)` so a re-imported statement cannot double-count tolls
- [ ] 🛑 **T-25** FASTag import — **PARKED** with hardware/provider feed. Needs the toll provider, not a device. See `docs/transport/HARDWARE-DEPENDENCIES.md` §5 · *was:* ⬜ **Import endpoint** — provider feed / CSV upload with a parser and a per-row result. *No…
- [ ] 🛑 **T-26** FASTag reconciliation — **PARKED**. Needs the provider feed AND Dispatch route data · *was:* ⬜ **Reconciliation engine** — match plaza hits against the trip's route and timestamps, fl…
- [ ] **T-27** FASTag screen: the register, the anomalies, and a manual match action

---

## 7. Sub-module 6 — Workshop job cards (`STOS-MAINT`)

### 7.1 Input fields

| Field | Type / rule | Status |
|---|---|---|
| Job card number | Auto-generated `JC-YYYY-NNNN`, unique per company | ✅ |
| Vehicle | Dropdown → `vehicle_id` | ✅ |
| **Yard / workshop name** | `VARCHAR(100)` | ⬜ **no column** — T-29 |
| Complaint summary | Long text, required — what the driver reported | ✅ |
| Diagnostic notes | Long text — what the workshop found | ✅ |
| **Itemised parts** | Part name, qty, unit cost, supplier, warranty | 🟡 **the form itemises, only the totals are stored** — T-30 |
| **Itemised labour** | Labour type, hours, hourly rate, technician | 🟡 same — T-30 |
| **QC test result** | `PASS` / `FAIL` / `CRITICAL_FAIL` | 🟡 stored as a boolean `qc_passed` — T-31 |
| **Road test confirmation** | Checkbox | ⬜ **no column** — T-31 |
| Safety-critical | Checkbox — this is what **blocks allocation** | ✅ |

### 7.2 Rules

- **On creation:** `vehicles.status → in_maintenance`, in the same transaction as the card.
- **On closure:** costs recorded, status → completed, and the vehicle is released **only if
  nothing else holds it** — no other open card, QC passed, and papers valid. Releasing
  unconditionally is how a truck leaves the workshop fixed and still uninsured. When it is not
  released, the response names every hold and whose desk each sits on.

### 7.3 Tasks

- [x] **T-28** Open / update / close, auto-numbering, cost totals, guarded release · *FleetOperationsTest*
- [x] **T-28b** A signed total overrides parts + labour (a warranty credit or rounded settlement is legitimate)
- [x] **T-29** `workshop_name` on the card, the form and the board · *WorkshopJobCardTest*
- [x] **T-30** **Line items persisted.** `maintenance_job_parts` (part, number, qty, unit cost, supplier, warranty months) and `maintenance_job_labour` (type, hours, rate, technician). Totals are summed FROM the lines so the card and its itemisation cannot disagree; a scalar total is still accepted for a card settled at the counter, and an explicit `total_cost` still overrides · *WorkshopJobCardTest*
- [x] **T-31** QC as `PASS/FAIL/CRITICAL_FAIL` plus a road-test flag. `CRITICAL_FAIL` holds the vehicle beyond its own card and is cleared only by a later card that passes QC **and names it** (`clears_job_id`) — not by any later pass, or a routine oil change would un-condemn a vehicle failed on its brakes · *WorkshopJobCardTest*
- [x] **T-32** `testing` and `qc` added to the status flow, both OPEN states so they still hold the vehicle · *WorkshopJobCardTest*
- [x] **T-33** Downtime hours per card, fixed at closure, with a per-vehicle total on the passport · *WorkshopJobCardTest*

---

## 8. Sub-module 7 — Tyre lifecycle (`STOS-MAINT`)

| Field | Type / rule | Status |
|---|---|---|
| Tyre serial id | `VARCHAR(60)`, the casing's identity across vehicles and retreads | ✅ |
| Position | `FRONT_LEFT`, `FRONT_RIGHT`, `REAR_INNER/OUTER_LEFT/RIGHT`, trailer, spare | ✅ |
| Fitment date | Date | ✅ |
| Odometer at fitment | Numeric | ✅ |
| Tread depth | `DECIMAL(4,2)` mm, **may only decrease** | ✅ |
| **Brand / size** | `VARCHAR` on a tyre master | ⬜ **`tyre_masters` does not exist** — T-36 |

- [x] **T-34** `tyre_fitments` as a **chain** — fitting opens a row, removing closes it, so a casing's distance survives being moved between trucks · *FleetContractsAndAssetsTest*
- [x] **T-35** Fitting a casing already on another vehicle moves it; fitting over an occupied position removes the incumbent; a casing at or below 1.6 mm is flagged
- [ ] **T-36** `tyre_masters` (serial, brand, size, purchase cost, status `IN_STOCK/FITTED/RETREADED/SCRAPPED`) with fitments pointing at it — gives cost-per-km per casing and a stock list
- [ ] **T-37** Rotation action (swap two fitted positions in one operation) and a retread cycle count
- [ ] **T-38** Tread-wear forecast: mm lost per 10,000 km, projecting the replacement date

---

## 9. Sub-module 8 — Driver overlay (`STOS-FLEET` / `STOS-CMP`)

**Golden rule 3 compliance: `driver_profiles` stores ZERO demographic columns** — no name, no
phone, no address. Those live in the CRM's customer/vendor directory, and a copy is what goes
stale. The overlay holds a reference (`crm_tpv_worker:17`) plus what only Transport knows.

| Field | Type / rule | Status |
|---|---|---|
| Worker reference | `source` + `source_id` (equivalent to the spec's single `worker_ref`) | ✅ |
| Licence number | `VARCHAR(40)` | ✅ |
| Licence class | `LMV / HMV / HTV / HAZ / OTHER` | ✅ |
| Licence expiry | Date, valid **through** the date | ✅ |
| **Medical fitness expiry** | Date | ⬜ **no column** — T-41 |
| Duty status | Spec `AVAILABLE / ON_TRIP / ON_LEAVE / SUSPENDED` | 🟡 built `available / on_trip / suspended / inactive` — T-42 |
| Assigned vehicle | The regular driver of a vehicle; one per vehicle | ✅ |

- [x] **T-39** Directory adapter: 40 workers added under a vendor in the CRM appear in Transport with nobody re-entering them · *DriverDirectoryTest*
- [x] **T-40** Licence verdict drives allocation scoring and flags · *DriverAllocationLinkTest*
- [x] **T-41** `medical_expiry` added and judged by the same date arithmetic as the licence, on the driver card and the drivers board. A VERIFIED `medical_certificate` now projects onto it (completes T-43's second gate). **Expired blocks; MISSING only warns** — the column arrives with every driver blank, so blocking on unknown would ground the fleet the day it ships. Making unknown a blocker once certificates are loaded is one line, and the owner's call.
- [x] **T-42** Both added, not renamed — they are different facts. Existing `inactive` rows stay INACTIVE because that is what was recorded. `driver_profiles.status` went UPPERCASE with them (the last lowercase enum in the module), and ON_TRIP is now refused from the profile form because dispatch owns it.
- [x] **T-43** Driver documents — filed through STOS-DOC's service at `/v1/fleet/drivers/{source}/{person}/documents`, with a VERIFIED `driving_license` projected onto `licence_expiry`. An upload does not clear a driver. Medical is filed but gates nothing until T-41 adds the column. **Unblocks P1's driver-controller deletion.**

---

## 10. Interconnections, contracts and events

```
        CRM customer / vendor directory  (owns name, phone, employer)
                          │ worker_ref
                          ▼
                   driver_profiles  ── licence + medical ──┐
                                                           │
   vehicles  ◄── the anchor; every table below points here │
      │                                                    │
      ├── vehicle_live_status (1:1, overwritten)           │
      ├── telemetry_records   (1:M, append-only)           │
      ├── fuel_transactions   (1:M) ── emergency ──► Dev 3 billing
      ├── urea_transactions   (1:M)                        │
      ├── fastag_transactions (1:M)                        │
      ├── maintenance_jobs    (1:M) ── open ──► status = in_maintenance
      ├── tyre_fitments       (1:M)                        │
      └── gensets             (1:M)                        │
                                                           ▼
                                         getEligibleVehicles() ──► Dev 1
```

### Service contracts — the names are the integration surface

| Contract | Consumer | Status |
|---|---|---|
| `FleetService::getEligibleVehicles(int $companyId, ?string $vehicleType, array $context)` | Dev 1 — dispatch planning | ✅ |
| `FleetService::getTripOperatingCosts(int $tripId, ?int $companyId)` | Dev 3 — trip P&L, billing readiness | ✅ |

`getTripOperatingCosts` sums fuel + urea + tolls + maintenance attached to `trip_id`. The second
argument exists **only** so the documented one-argument call works; it resolves from the session
and refuses outright if neither is available — a trip id alone is not a tenancy boundary.

### Events — published under their dotted contract names

| Event | Consumer | Status |
|---|---|---|
| `telemetry.temperature_excursion.detected` | Dev 3 → QC incident / CAPA | ✅ |
| `fuel.emergency_issued` | Dev 3 → billing recovery item | ✅ |
| `fleet.vehicle.status_changed` | Dev 1 and Dev 3 → control-room widgets | ✅ |

Classes stay typed internally; the provider re-broadcasts each dispatch under its string name
with a plain-scalar payload, so a consumer never imports our namespace. `VehicleStatusChanged`
fires from a **model observer**, so no future code path can change availability silently.

### Eligibility evaluation order

1. Vehicle type filter.
2. Hard exclusions, each returned **with a reason**: retired, compliance expired/blocked, open
   safety-critical job card, in the workshop, position unknown.
3. Score the survivors: proximity 0.35, efficiency 0.22, utilisation 0.13, **driver 0.30**.
4. Flags: `DRIVER_LICENSE_EXPIRED`, `_EXPIRING`, `_UNRECORDED`, `NO_DRIVER_ASSIGNED`, `DRIVER_UNAVAILABLE`.

> ⚠️ **T-44 — decision.** The spec says eligibility requires `driver_profiles.license_expiry >
> today`. We **score it down (to zero) and flag it, rather than excluding the vehicle**: the
> truck is roadworthy and swapping drivers is a smaller decision than standing it down —
> excluding would hide the only vehicle in the yard over a paperwork problem. Say the word and
> it becomes a hard exclusion; it is one branch in `VehicleAllocationService`.

---

## 11. Cross-cutting tasks

- [x] **T-45** Compliance sweep `stos:refresh-compliance`, scheduled 03:30 — a certificate that lapses overnight blocks dispatch the same morning with nobody touching the record
- [x] **T-46** Tenancy: `company_id` from the session; a `?company_id=` that disagrees is **403**, never silently ignored
- [x] **T-47** Every screen behind `role:admin,staff`; portal logins (client/vendor/TPV) get 403 from every endpoint
- [x] **T-48** Standalone mode: `DriverDirectory` binding auto-detects the CRM, so one codebase runs integrated and standalone
- [ ] **T-49** Idle-vehicle and utilisation reporting for the executive tower (`STOS-REP` feeds from our data)
- [ ] **T-50** 🟡 Frontend, the four screens the tasks above imply. **Urea modal — done** (T-23). **Genset management — done** (T-05: passport panel + onboarding field). Still missing: FASTag register (T-27), tyre master screen (T-36).
- [x] **T-51** — **RULED 2026-09-19 (spec 12.S11): two casings, on purpose.** Database enums and state-machine states are UPPERCASE; API blocker codes and machine reasons are lowercase snake_case. Fleet's advisory flags and blocker codes were uppercase and are now lowercase (`service_overdue`, `driver_license_expired`). A state is what a thing *is*; a machine reason is what a response *says about* it
- [ ] **T-53** Expiry dates become a **projection**, not a master. `STOS-DOC` verification → `STOS-CMP` → Fleet's five date columns, so verifying a renewal clears the dispatch block with no manual re-entry; then hand-editing dates is prohibited. **Sequencing matters:** removing the manual input before the projection runs leaves no way to record a compliance date at all · *needs Dev 3's verify workflow live*
- [ ] **T-54** `trailers` as their own master with `vehicle_trailer_assignments` dynamic coupling — a trailer is NOT a `vehicles` row. Own compliance profile, tyre set and maintenance record; coupling preserved historically
- [ ] 🛑 **T-55** Route / Movement Anomaly exception — **PARKED**: needs real pings to detect movement · *was:* Route / Movement Anomaly exception — a vehicle moving under power while not ALLOCATED or I…
- [x] **T-56** Vehicle asset status transitions — `PATCH /v1/fleet/vehicles/{id}/status`, absorbed from Dev 1's retiring endpoint. Accepts only `AVAILABLE`, `IDLE`, `RETIRED`; every refusal names the desk that can clear it rather than just saying no. Retiring routes through the existing guarded `retire()` so it is not a second, weaker implementation. Input is canonicalised before validation, so the door and the service cannot disagree about a spelling · *VehicleStatusTransitionTest*
- [x] **T-57** Vehicle documents — file / renew / list, written **through** STOS-DOC's service so versioning and audit stay Person 3's. The owner approved `rc`, `puc`, `tax` + six driver types, which fixes the ambiguity that made RC and PUC unfilable as themselves. **An upload never moves the dispatch gate** — only a VERIFIED document projects onto the vehicle's date, and an older certificate verified late cannot pull a date backwards · *VehicleDocumentTest*
- [x] **T-58** Genset, tyre and job-card statuses to UPPERCASE — database enums, so spec 12.S11 applies. Migration `2027_01_10_000001` converts the rows and the defaults; the three vocabularies are named constants now. **Found a live bug doing it:** `GensetService::fit()` compared a vehicle against `'retired'` while `vehicles.status` has held `RETIRED` since January, so that guard had never once fired — a genset could be fitted to a scrapped truck. Also deleted `FleetService::OPEN_JOB_STATES`, a second definition of “open job” missing TESTING and QC.

- [x] **T-52** ~~`BannedPatternsTest` fails on `TpvVendorDetail.jsx`~~ — **no longer true.** Full backend suite verified green on 2026-09-17: **4,641 passing, 0 failures, 3 skipped.** The uncommitted refactor that caused it was committed in the meantime.

---

## 12. Build order

Dependencies first — each step unblocks the next.

| # | Step | Tasks |
|---|---|---|
| 1 | Close the schema gaps on the anchor table | T-01, T-03, T-29, T-41 |
| 2 | Make the itemisation real (the biggest untruth left) | T-30, T-31, T-32 |
| 3 | Tyre and genset masters | T-05, T-36, T-37 |
| 4 | The toll pipeline that does not exist | T-25, T-26, T-27 |
| 5 | Screens for everything built in 1–4 | T-23, T-50 |
| 6 | Hardening and reporting | T-07, T-12, T-13, T-19, T-33, T-38, T-49 |
| 7 | Vocabulary alignment, once, with the team | T-02, T-18, T-42, T-44, T-51 |

**Done means done.** Before ticking anything above: migration run, service enforcing, endpoint
guarded, screen reading real field names, feature test asserting the failure paths too.
