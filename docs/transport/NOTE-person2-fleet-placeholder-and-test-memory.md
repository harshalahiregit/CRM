# Note to Shivam (Person 2) — two things: the Fleet placeholder, and a one-line test fix

**From:** Person 1 · **Date:** 2026-09-16 · Neither of these needs a reply before you start.

---

## 1. Vehicles and Drivers on master are a PLACEHOLDER. Build your own; do not extend ours.

There is a working Vehicle and Driver implementation on master right now — tables, models,
services, API and four screens. **It is not ours to keep.** `transport_vehicles` and
`transport_drivers` are DB-004 and DB-005, Fleet, yours under TM-001 §8.

It exists for one reason: Trip and Transport Order cannot be demonstrated without vehicles and
drivers. A trip with nothing to allocate proves nothing — no allocation panel, no eligibility
refusal, no dispatch gate. It is scaffolding for the demo, and it was always meant to come out.

**So:**

- **Build your Fleet module the way you want it.** Do not extend ours, do not preserve its schema,
  do not write a migration plan for its data. It is demo data.
- **You do not need our permission or our sign-off** to replace it. This was agreed in advance
  precisely so it is not a negotiation when your branch is ready.
- **We remove ours**, in the same PR that brings your Fleet in, or immediately after. Not you.
- Nothing more will be built on it in the meantime — no features, no refactors, no polish.

### What we delete, so the handover is mechanical

| | Files |
|---|---|
| Migrations | `2026_12_16_000004_create_transport_vehicles_table.php`, `2026_12_16_000006_create_transport_drivers_table.php` |
| Models | `TransportVehicle.php`, `TransportDriver.php` |
| Services | `TransportVehicleService.php`, `TransportDriverService.php` |
| Controllers | `TransportVehicleController.php`, `TransportDriverController.php` |
| FormRequests | `Store` / `Update` / `Transition` × `TransportVehicleRequest`, `TransportDriverRequest` — six files |
| Enums | `VehicleStatus`, `VehicleOwnership`, `DriverStatus`, `DriverAvailability`, `DriverComplianceStatus` |
| Routes | the `/vehicles` and `/drivers` groups in `routes/transport.php`, plus their `TransportPermission` keys |
| Pages | `TransportVehicles.jsx`, `TransportVehicleDetail.jsx`, `TransportDrivers.jsx`, `TransportDriverDetail.jsx` |
| Components | `VehicleForm.jsx`, `DriverForm.jsx` |
| Nav | Vehicles and Drivers entries in `TransportLayout.jsx` and `Sidebar.jsx`, and their routes in `app/routes.jsx` |

### What we do NOT delete — please read this row before you touch anything

| File | Why |
|---|---|
| `components/MasterFormFields.jsx` | **shared.** Also imported by `PretripPanel`, `AllocationPanel`, `DispatchPanel` and `DocumentsPanel`. Deleting it with the forms breaks four panels that have nothing to do with Fleet |
| `TripAssignment.php`, `transport_trips.vehicle_id` / `.driver_id` | ours. Nullable, no FK — the team convention for a shared entity that does not exist yet, so wiring them to your tables is a one-line migration |
| `AllocationService`, `VehicleEligibilityService`, `DriverEligibilityService` | allocation scoring is TM-001 §9 — **yours**, currently built by us. A separate handover, not part of this one |
| `ResourceCommitmentService` | ours. Reads `trip_assignments` and `transport_trips` only, no Fleet table, so it survives the swap untouched |

**What we will need from your Fleet**, whenever you get to it — not a request, just so it is not a
surprise: a way to list allocatable vehicles and drivers, and enough on each to show a
registration or a name. That is all our allocation panel reads today.

---

## 2. `TaskCommentTest` caps memory for every test that runs after it

Not Fleet, and not urgent — but it is your file, so it comes to you rather than being edited.

`backend/tests/Feature/Task/TaskCommentTest.php`, line 139:

```php
ini_set('memory_limit', '512M');
```

You raised it because the 5 MB comment test was exhausting the default 128 MB and taking the run
down — that was a real problem and the fix was right for your test. The snag is that PHPUnit runs
the whole suite in **one process** and `ini_set` is never restored, so every test that runs after
`TaskCommentTest` inherits the 512 MB cap. On this machine the CLI default is `-1` (unlimited), so
in practice the line *lowers* the ceiling for the rest of the suite.

**Two ways to fix it. Both restore the limit; pick either.**

The important property is that the restore must survive a FAILING assertion — your test is
specifically about something failing, so a plain restore written after the assertion never runs.

*Durable version — no deprecation, works on any PHPUnit:*

```php
$old = ini_get('memory_limit');
ini_set('memory_limit', '512M');

try {
    $t = $this->task();
    $this->comment($t, str_repeat('x', 5_000_001))
        ->assertStatus(422)
        ->assertJsonPath('errors.content.0', 'This comment is too large to post. Try fewer or smaller images.');
} finally {
    ini_set('memory_limit', $old);   // runs even when the assertion fails
}
```

*One-liner — shorter, but deprecated:*

```php
$this->iniSet('memory_limit', '512M');   // instead of ini_set(...)
```

`TestCase::iniSet()` does restore the previous value when the test ends (I read the PHPUnit source
to check — it records the old value and restores it in `tearDown`). **But it is deprecated:**
PHPUnit emits *"iniSet() is deprecated and will be removed in PHPUnit 12 without replacement"*, and
this project is on `phpunit/phpunit: ^11.5.50`, so it breaks at the next major upgrade and adds one
more to the 39 deprecation warnings the suite already reports.

I would use the `try/finally` version, but either one fixes the problem today and it is your call.

**What it costs today:** the full suite dies partway with
`Allowed memory size of 536870912 bytes exhausted`. Master alone stays just under the cap; adding
Transport's ~780 tests crosses it. Until the fix lands, the full suite has to be run as:

```bash
./vendor/bin/phpunit --exclude-filter='/TaskCommentTest::test_an_absurdly_large_comment_is_refused_with_a_readable_reason/'
```

With that one test excluded: **4263 tests, 8 failures** — all eight pre-existing on master
(1 `BrandingTest`, 5 `ContractEmailArrivesIntactTest`, 2 `ContractModuleTest`), none of them yours
and none of them ours.
