# To Shivam (Person 2) — your Fleet module is now the visible one. One question back.

**From:** Person 1 · **Date:** 2026-09-17 · Re: **D-62** — two vehicle and driver systems live at once

---

## 1. The decision, so you know where you stand

The owner has ruled: **your Fleet module is the fleet everyone sees.**

Our two sidebar entries — *Vehicles* and *Drivers* — are **hidden as of today**. *Fleet Status* and
*Driver Directory* are now the only fleet screens in the navigation. The reasoning is that
everything has to connect to your module eventually, so yours should be the one in front of people
now rather than later.

**Hidden, not deleted, and deliberately so.** The pages, routes, API, models and services are all
still there and still work if you type the URL. They have to be: **allocation, pre-trip checks and
dispatch read `transport_vehicles` and `transport_drivers` today.** Deleting them would break the
dispatch chain in the same hour.

The commented-out entries in `TransportLayout.jsx` and `Sidebar.jsx` say exactly that, so nobody
un-hides them by accident or deletes them too early.

## 2. One consequence you will see immediately, and it is not a bug

**Fleet Status will read "No vehicles yet."**

The demo trucks live in `transport_vehicles`, which your module does not read. That is honest and
the owner has chosen to leave it that way.

**We have deliberately NOT seeded copies of our demo vehicles into your tables to make your screen
look populated.** Two sets of the same trucks in two tables is precisely the duplicate-master-data
failure this whole arrangement exists to prevent — and they would be your tables, not ours.

## 3. The actual question — yours to answer

Hiding a menu does not join the data. Here is the position, plainly:

| | Reads | Owns |
|---|---|---|
| **Ours** | `transport_vehicles`, `transport_drivers` | nothing — it was always a placeholder (TEAM-CONTRACTS §1a) |
| **Yours** | fleet tables, driver directory | vehicles and drivers, under TM-001 §8 |

**Allocation, pre-trip and dispatch currently read our tables. They need to read yours. How do you
want that to happen?**

Some shapes it could take — **none of these is proposed, and none is started:**

- our demo rows migrate into your tables, and allocation repoints at your module;
- you expose a contract we read — an endpoint or a service returning allocatable vehicles and
  drivers — and our tables are retired behind it;
- something else you prefer that we have not thought of.

**It is your module and your call.** If we disagree, the owner rules.

### What we can do on our side

- Repoint `AllocationService`, `VehicleEligibilityService`, `DriverEligibilityService`,
  `PretripService` and `DispatchService` at whatever you expose.
- Delete our vehicle/driver pages, routes, controllers, FormRequests, models, services and enums —
  the full list is in TEAM-CONTRACTS §1a, including the **one file that must NOT go**:
  `MasterFormFields.jsx`, which four unrelated panels import.
- Keep `trip_assignments` and the trip's `vehicle_id` / `driver_id` columns pointing at whatever ids
  you give us. They are nullable with no FK precisely so this is a one-line change.
- Carry the demo data across, or drop it, whichever you prefer.

### What only you can do

- Decide whether your tables take our rows, or whether we start clean.
- Tell us what identifies a vehicle and a driver in your module, so `trip_assignments` points at the
  right thing.
- Say what your module can answer about **allocatability** — allocation needs to know which vehicles
  and drivers are free and compliant. Today it computes that from our tables and our
  `VehicleStatus` / `DriverAvailability` enums. If yours answers it differently, that changes the
  eligibility services, which are yours under TM-001 §9 anyway.

## 4. What we need from you

**Which shape do you want?** One line is enough to unblock it. Nothing on our side moves until you
answer — we are not going to migrate into your tables on an assumption.

Related and still open from earlier, in case they were missed:

- `TaskCommentTest:139`'s `ini_set('memory_limit', '512M')` leaks into every test after it — the
  full suite needs `--exclude-filter` on that one test until it is fixed
  (`docs/transport/NOTE-person2-fleet-placeholder-and-test-memory.md`).
- Defect numbers now have per-person bands, after P1 and P3 collided on D-58…D-61.
  **Yours is D-200 onward.** See TEAM-CONTRACTS → Defect numbering.
