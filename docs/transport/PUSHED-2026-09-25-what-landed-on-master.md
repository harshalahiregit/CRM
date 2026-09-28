# What landed on master on 25 September, and what is deliberately red

**For Person 2 and Person 3.** Three days of Transport work became visible to you in one push.
Read section 3 before debugging anything red.

**Suite on the exact tree pushed: 104 failed · 6853 passed · 3 skipped.**

---

## 1 · The repoint — trip references now point at Fleet

`transport_trips`, `trip_assignments`, `trip_exceptions` and `trip_advances` held **legacy** vehicle
and driver ids. They now hold **Fleet** ids. 16 references moved, 7 recorded as unmatchable, all of
it reversible from `fleet_reference_repoints`.

Everything that reads those columns moved with them: seven model relations, both eligibility
services, `AllocationService`, `freeResources`, the plate search, and BR-P0-003's row lock.

Three things worth knowing because they will bite anyone who assumes the old shape:

- **Fleet's statuses are UPPERCASE.** `VehicleStatus::AVAILABLE` is `'available'` and never matches
  a Fleet row. It fails *silently* — the allocation still writes, the vehicle simply never stops
  being advertised.
- **`driver_profiles` has no `availability` column** and **no `name` column.** A driver is a
  reference into a directory plus a licence. `$profile->name` resolves through the directory via an
  accessor that is deliberately not appended, so `toArray()` stays clean.
- **`auto` now binds `CompositeDriverDirectory`**, which asks the CRM directory *and* the STOS
  register. After the D-62 move both hold real people, and choosing one hid the other.

## 2 · The read-only ruling — vehicles and drivers are created in Fleet

The owner ruled on 24 September that the legacy master CRUD becomes read-only.

`POST/PUT/PATCH/DELETE` on `/api/transport/vehicles` and `/api/transport/drivers` now answer **409**
with a sentence naming where vehicles are created now. The routes are still registered on purpose:
an absent route returns a bare 404 and can name nothing, and "no role bypasses this" is only
testable against a refusal that exists.

**The reads are unchanged** and the screens still show history. Nothing was deleted — the
controllers, services, requests and models are all still there.

**One exception:** `POST /transport/drivers` is **held**, not refused. See D-145 below.

## 3 · What is red on purpose — do not debug these

| Count | What | Why |
|---|---|---|
| 1 | `DriverDirectoryTest::forty_workers_added_under…` | **D-144(i)** — asserts `auto` returns `CrmDriverDirectory`, the rule D-134 deliberately replaced. P2's test, not edited by us. |
| 1 | `TransportMasterApiTest::duplicate_licence…` | **D-145** — the only thing in the codebase enforcing licence uniqueness. Fleet has no check, no request class, and its one unique index is `(company_id, source, source_id)`. **Two drivers can share a licence today.** Left red on purpose. |
| 3 | the delete-guard tests | **D-146** — `grep trip_assignments app/Domains/Fleet/` returns **0**. Fleet's delete cannot know a vehicle is mid-journey. Moving these would move them somewhere the rule is not enforced. |
| 11 | `TransportMasterAllocationAuditTest` | Waiting on the delete decision above. |
| 9 | long-standing | Branding, ContractEmail ×5, ContractModule ×2, TaskComment — red before any of this. |

**Total deliberate: 25.**

## 4 · The remaining ~79 — untriaged fixture debt, ours

Not deliberate, and not a product defect as far as measured. The dominant causes:

```
18  Expected status [201/200] but received 409   tests of the write surface the ruling closed
 6  must be of type TransportDriver / Vehicle    test helpers' own type hints
 7  tenant-isolation assertions                  follow-on from the above
 2  forceFill() on null
```

They are tests whose **fixtures** still build legacy vehicles and drivers, so allocation cannot
accept them. Transport went 299 → 104 this week by moving fixtures onto Fleet; these are what is
left. **If you see one of these, it is ours and it is on the list** — please do not spend time on
it.

## 5 · Three things for Person 2, none urgent

- **D-132** — two of your migrations cannot run on MySQL (`count(*) as rows` is a reserved word;
  `dropUnique` is refused while `000004`'s foreign key exists, and `000004` runs after). Repaired
  minimally in this push because the chain was blocked and the dev database was half-converted.
  **Please review; if you prefer the reorder, it is your file.**
- **D-145 / D-146** — above. Both are guards that would have disappeared in a cleanup, found by
  asking *"who enforces this after the move, and have I read their code saying so"* before deleting
  a test. That rule is now in `TEAM-CONTRACTS.md`.
- **D-121** — the number was used twice; ours renumbered to D-126. The **band** was breached, and
  the bands exist so this cannot happen: P1 is D-100+, P2 is D-200+, P3 is D-300+.

Your D-202 landed before this push and its suite is green here — 426 passing in `Stos`.

## 6 · Two things for Person 3

- **D-131** — `trip_advances` has no foreign keys at all, and one row holds `driver_id = 1212010`,
  which is an id in no table anywhere. Recorded in the repoint ledger under a verdict added for it,
  `never_valid`. Your table, untouched.
- **D-127** — the shared portal records table has no pagination and one customer already has 35
  shipments. Note in `NOTE-person3-the-portal-table-will-not-scale.md`.
