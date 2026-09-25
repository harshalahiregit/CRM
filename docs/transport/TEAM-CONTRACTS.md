# Transport OS — Team Contracts

Three of us, two weeks. This file exists so nobody builds the same thing twice.
It was written after that had already happened once: SNG-TRN-028 got built on two
branches in parallel because I looked for transport code in my own working tree,
found none, and did not run `git log --all`. Both registered the middleware alias
`transport.permission` with different arities, which would have thrown
`ArgumentCountError` on every gated route the moment they met in master.

**Before starting anything: `git log --all --oneline -- 'backend/**/Transport/*'`.**

**Update this by PR, in the same change as the work.** A WhatsApp message is not a
record.

| | Owner | Code lives in |
|---|---|---|
| P1 | Raza — Commercial, Orders, Consignment, Container, Operations, Dispatch, Trip | `backend/app/**/Transport/` |
| P2 | Shivam — Fleet, Vehicles, Drivers, Assets, Telemetry | — |
| P3 | Zafar — Documents, Billing Readiness, Finance, Compliance, Quality/CAPA, Intelligence | `backend/app/**/Transport/` |

Structure follows what P1 already built: `app/Models/Transport`,
`app/Services/Transport`, `app/Http/Controllers/Api/Transport`,
`app/Support/Transport`. Not a package. One structure, not two.

## Never run `migrate:fresh` on the dev database

**Not for cleanup, not for testing, not "just this once".** `migrate:fresh` and `migrate:refresh`
drop every table in the application — HR, Purchase, Sales, Helpdesk, TPV, Inventory, Customers,
Projects, Tasks, users — not only the module you are working in. The dev database is the owner's
working copy and all three of our modules plus six others live in it.

This happened on **2026-09-17**. A cleanup instruction meaning "remove the Transport demo data" was
carried out as `migrate:fresh --seed`. Every table was dropped and the owner could not log in. It
was recovered in full only because a `mysqldump` had been taken minutes earlier — that was luck,
not a process.

**The rule:** clearing data is scoped to your own module's tables and to one tenant, and you assert
that it touches nothing outside them. Tests run on their own in-memory sqlite, which is the only
place `migrate:fresh` belongs.

**And the judgement behind it:** if a cleanup instruction — including one from a lead — would
destroy anything outside your own section, stop and say so before running it.

---

## A block is not done until it has been walked in a real browser

**Ruled by the owner, 2026-09-18. Standing rule, all three sections.**

Not tested — **walked**, by a person clicking, in a real browser, using the values a user would
actually use.

This is not a suggestion born of caution. On 2026-09-18 three blocks that were marked done,
fully tested and merged were walked for the first time. **Six user-visible defects**, including
two shipped features that **nobody outside UTC could use at all**. The suite was green at 1311
tests throughout.

A green suite is evidence that the code does what the tests say. It is not evidence that anybody
can use the product.

---

## First click, default values

**The lazy path is the one everybody takes. Test it.**

Every one of the six defects above failed on the **first click with nothing typed**:

- `Record departure` — pressed with the time field untouched: *"A departure cannot be recorded in
  the future."*
- `Record delivery` — same.
- A free container's passport — opened with no arguments: six cards reading "—".
- A search that matches nothing — "No containers yet", with three containers on file.

Nobody types a custom value on their first try. They press the button and see what happens. If
that path is broken the feature is broken, whatever the form does when carefully filled in.

---

## Anything crossing the browser/server boundary needs a contract test

**Dates, times, money, numbers — anything with a format.**

Server-side tests cannot see that boundary **by construction**: they build their values on the
server, where there is nothing to convert and nothing to get wrong. A suite made entirely of them
will be green over a feature that cannot be used, and was.

What that blind spot cost, measured:

| | typed | stored | shown back |
|---|---|---|---|
| Dispatch ETD | 14:00 | 08:30 UTC | **19:30** |
| Order "Required by" | 09:00 | 09:00 UTC | **14:30** |
| Record departure / delivery | *(default: now)* | — | **refused as "in the future"** |

So: when a value is transformed on its way out of the browser, there is a test asserting the
shape it leaves in. `TransportDateTimeContractTest` is the worked example — it reads the frontend
source from the PHP suite, because **the PHP suite is what runs**.

**Two things that guard has already taught us:**

1. **Fixing the converter does not fix the callers who never called it.** `TransportOrderForm`
   had a `datetime-local` and called nothing, and survived the first fix untouched. The guard now
   asserts that **every** file rendering a `datetime-local` also converts one.
2. **A guard must read code, not prose.** It first fired on a file whose only offence was a
   comment explaining the bug — the same trap the D-63 seeder guard fell into. Strip comments,
   and strip them with `[^\n]*`, not `.*$` with the `/s` flag: with `/s` a line-comment pattern
   runs greedily to the end of the file and the guard then scans almost nothing and passes on
   everything. That was true of two guards here until it was measured.

**And prove the guard fires.** Break the thing on purpose, watch it go red, put it back. A guard
that has never failed is a guard nobody has tested.

---

## A key-name guard cannot catch a renamed field — check the value

**P1, 2026-09-22.** Standing rule for any guard that asserts something did **not** escape.

The client portal's leak test began as a deny-list of field names checked against response keys.
It caught a denied column added to a select, and it caught a join whose alias was still mapped to
the forbidden name. Both breaks went red. It looked finished.

Then the same join was aliased **all the way through** —
`->get([..., 'd.licence_number as driver_contact'])` with `'driver_contact' => $row->driver_contact`
— and **the guard passed while the response carried a real licence number.** The forbidden word
appeared nowhere: not in the response, not in the diff a reviewer reads.

**A name check can only ever find what somebody agreed to call the thing.** The fix is to seed the
internal fields with **sentinel values** that exist nowhere else, and assert those values never
appear in a response at any depth, under any key. A value travels even when the name does not.

**So: when a guard asserts an absence, ask what a rename does to it.** If renaming defeats it,
the guard is checking spelling rather than substance.

**And the general lesson, which is why this is here rather than only in the test:**

> **Stopping at the break that worked is how a blind guard is born.**

Two breaks went red and the third — the one the reviewer had specifically warned about — went
green. That third case only existed because it was tried after the guard already looked finished.
Six blind guards on this project now, every one written carefully by somebody who believed it
worked.

---

## Assert on what the user ends up with, not on what you just added

**P1, 2026-09-19. Standing rule for every test on this project.** It has been earned five separate
times, each one a case where **the code was right, the suite was green, and the outcome was
wrong.**

| What was added | What was asserted | What the user got |
|---|---|---|
| A timezone converter | That the converter converts | A departure stored 5½ hours out, because two callers never called it |
| Two comment-stripping guards | That the guard runs | Nothing scanned — the regex ate the whole file |
| Refusal messages with reasons | That the API returns the reason | *"Validation failed"* — the screen read a different key |
| `trip_events` rows on every trip | That the rows exist | Four types written by nothing; a backfill had filled them in |
| A search that reaches the passport | That `passport` was attached | Every search landed exactly where it always had |

The last one is the clearest, because it is three characters. `$hit + ['path' => $passport]` —
PHP's union operator keeps the **left** operand's keys, and `$hit` already had a `path`. The
passport attached. The trail rendered. Every test passed. Every search went exactly where it had
gone before. **A finished-looking feature that did nothing.**

**So: write the assertion against the thing the user receives.** Not the flag you set, not the
field you added, not "the method was called". The path they navigate to, the text on the screen,
the row in the table, the value in the column.

Ask, before you commit: *if the wiring between my new code and the user were cut, would this test
still pass?* If it would, it is testing that you wrote some code.

**And when a test that should have broken does not, find out why before moving on.** Changing
where seven search keys landed should have broken a test that asserts paths. It did not. The
reason turned out to be legitimate — its fixture never attached a container, so every key
correctly stopped at its own record — and the test now says so in a docblock naming which half it
pins. **A test that passes for the wrong reason is a blind guard that has not been caught yet**,
and the moment to catch it is when it surprises you.

---

## Ask what filled the screen, not whether the screen is filled

**P1, 2026-09-19, from D-115.** Standing rule wherever data can arrive by more than one route —
a seeder, a backfill, a migration, an import, another team's job.

MS-001 §14 step 14 — *"show the complete timeline"* — was walked in a browser and marked **WORKS**.
The screen was right. Eleven events, correctly ordered, in English. What it did not show is that
seven of them had been **reconstructed by a backfill** and that four types
(`trip.created`, `trip.submitted`, `vehicle.allocated`, `pretrip.passed`) were **emitted by no
code at all**. Every existing trip looked complete. A trip created the next day would have had
four holes, silently.

**So: when a screen can be populated by something other than the code under test, walking it is
not sufficient.** Read a row and ask where it came from. Better, make one from scratch — the fix
above was confirmed by creating a new trip and checking its first three events said `LIVE` rather
than `BACKFILLED`.

**Two corollaries, both earned the hard way on the same day:**

1. **Keep the audited token a literal.** `TripEventEmissionTest` audits the event registry by
   finding type strings in recorder calls. The first version of `AllocationService` looped a
   `[$type => $id]` table, and the audit could not see either call — a value assembled from a
   variable is invisible to any scanner. If a test greps for it, write it out longhand.

   **And scan the other sections' code before you trust a green run.** That same test matched the
   type only as a first positional argument, which is P1's dialect. P2 and P3 both write
   `record(type: 'x.y', …)`, so on the day they shipped nine emitters between them the guard went
   green and reported none of them existed — and mis-reported one of P1's own calls too. **A guard
   that only recognises the dialect its author writes is a mirror, not a guard.** Three guards on
   this project have now failed in that exact shape.
2. **Trust the test over your own grep.** The allow-list for that test was drafted from a hand-run
   `grep` which reported `genset.on` as emitted. The only occurrence outside the registry was **an
   example inside a docblock.** The test contradicted the grep on its first run and the test was
   right.

---

## Check a blocker against the code, not against the register

**Ruled by the owner, 2026-09-19, after two of ours had quietly cleared.**

**Before every block, and before any message saying you are blocked:**

```bash
git fetch origin
git log --oneline HEAD..origin/master     # what landed since you last looked
```

Then take each of your open blockers and **look at the thing itself** — the class, the method,
the route — rather than at what the register says about it.

On 2026-09-19 we found that two of ours had been cleared days earlier and nobody had said so:
P3 made a consignment a document entity on the 16th, closing every item of
`REQUEST-person3-document-entity.md`; P2 landed `ReconcileFleetMasters` on the 17th, closing one
of D-100's three blockers. **A walk of the demonstration written on the 18th still recorded the
first as "blocked on P3".**

A register that says you are blocked on something that landed three days ago is **worse than no
register**: it sends you to ask a colleague for work they have already done, and it stops you
building something that is sitting there unblocked.

**Nobody is at fault for not announcing.** We did not announce the trip lifecycle to them
either. Three people shipping to one repository several times a day is simply a situation where
announcements are not a reliable channel. Looking is.

**And when you check, record what is STILL open too** — with how you verified it. D-114 lists
`markInvoiced()` as still having no caller and `FleetResourceGateway` as still carrying one
method, each checked in the code, because "I looked and it is still blocked" is worth exactly as
much as "I looked and it is not".

---

## No guard is trusted until it has been seen to fail on the thing it guards

**Ruled by the owner, 2026-09-18. One line, and it has already paid for itself twice.**

Write the guard, then **break the thing on purpose and watch it go red**, then put it back. A
guard that has never failed is not a guard — it is a comment that costs CPU.

**Break it a second way, too.** On 2026-09-18 two guards in this module were stripping comments
with `'#//.*$|/\*.*?\*/#ms'`. The `/s` flag makes `.` match newlines, so `//.*$` ran greedily
from the file's first comment to its last line and **stripped the entire file**. Both guards were
scanning an almost-empty string and passing on anything at all.

The newest of them — a timezone contract test — **passed while a second timezone bug was still
live in the file it was reading.**

The D-106 caller scan had the identical line and *did* fire when it was tested, for one reason:
the probe happened to be inserted **above that file's first comment**. One probe in one position
is not proof. Put the probe somewhere else as well.

**This is not hypothetical — the second break found a second hole the same day.** The timezone
guard checked "does this file render a `datetime-local`". Probe A removed the conversion from a
file that renders one directly: it went red, correctly. Probe B removed the conversion from
`DispatchPanel`, which renders `type={field.type}` out of a shared field config — and deleting
the conversion deleted the file's last mention of the literal, so the file **fell out of the
guard's scope entirely and the guard stayed green over a real regression.**

Two probes, two positions, two different mechanisms. The first proved the guard worked. The
second proved it did not.

**A test that cannot fail is worse than no test: it converts a gap into confidence.**

---

## When you sweep a boundary, look for the silent bugs first

**They are the ones that have been running longest.**

The timezone sweep found two faults of the same cause and they behaved completely differently:

| | behaviour | how long it survived |
|---|---|---|
| `Record departure` / `Record delivery` | **threw** — "cannot be recorded in the future" | found the first time anyone clicked |
| Order "Required by" | **stored the wrong time, silently** | every order ever created through the UI |

A bug that throws gets found, by a user if not by us. A bug that quietly writes the wrong value
does not — there is nothing to notice, and the wrong data accumulates the whole time.

So when sweeping: start with the paths that **succeed**, not the ones that fail. Take a value
through the full round trip — type it, store it, read it back — and compare it to what was typed.
"No error" is not the same as "correct".

**And report what was clean, not only what was found.** A sweep that lists only its hits tells
the reader nothing about what was actually checked.

---

## Always run `migrate:status` before `migrate`

**Read what is pending before you apply it. Every time.**

`php artisan migrate` applies **everything** that is pending, not the migration you just wrote.
Another developer's migration, sitting unapplied on your branch since a merge you did not look
at closely, runs on your database the moment you add a column of your own.

This happened on **2026-09-18**. Block 3 added two columns to `transport_trips`;
`php artisan migrate` also ran `2027_01_02_000002_move_transport_masters_into_fleet`, which the
owner had said explicitly not to run yet. Nobody opted into it. Nobody passed a flag or named a
file. Laravel did what Laravel does. See D-109.

**The rule:**

```
php artisan migrate:status     # read the pending list
php artisan migrate --pretend  # if anything on it is not yours
php artisan migrate
```

**And the judgement behind it:** `migrate` is not a command that applies *your* change. It is a
command that applies *the branch's* changes, and a pending migration you did not write is
somebody else's decision executing on your database. A status check is the only thing standing
between you and the next one.

Same family as the rule above: a routine command with an irreversible effect nobody expects.

---

## `php artisan migrate` currently moves the Fleet masters — D-109

**Added 2026-09-17, after I triggered it by accident.**

`2027_01_02_000002_move_transport_masters_into_fleet` is a pending migration with no guard on
it. **The ordinary `php artisan migrate` runs it**, and it repoints
`transport_trips.vehicle_id`, `.driver_id` and the same two columns on `trip_assignments` from
the Transport masters to the Fleet ones.

Transport still reads `transport_vehicles` and `transport_drivers`, so every repointed row
becomes an orphan and trips lose their vehicle and driver on screen. `down()` is a deliberate
no-op, so `migrate:rollback` will not undo it.

**Until it is guarded or withdrawn:**

- check `php artisan migrate --pretend` before running `migrate` on any database you care about;
- if it has already run on yours, re-running `TransportDemoSeeder` repairs the Transport side
  (the Fleet rows it inserted are P2's and are left alone);
- do not delete the rows it created in `vehicles`, `driver_profiles` or `stos_drivers` — they
  carry `legacy_transport_*_id` and are P2's to reverse.

Same family as the rule above: a routine command with an irreversible effect nobody expects.
The decision on guarding it belongs to the owner and P2, and is open in D-109.

---

## Which document wins when the state machines disagree

**Ruled by the owner, 2026-09-17. Standing rule — applies to every state machine in
Transport, not just the Trip.**

> **Vocabulary from Step 9. Edges from Step 11. A Step 9 state becomes reachable only
> when some document defines something that can gate it.**

Step 9 (Master Product Constitution) is the authority tier and gives the Trip sixteen
states. Step 11 (Canonical Registries) LOCKS transitions for twelve. Four times, Step 9
puts a state where Step 11 draws a single edge straight past it. Each of those four was
being re-argued from scratch by whoever reached it next, which is what this rule ends.

Applying it:

| Step 9 state | Entry gate in any document | Requirement that records it | Data model | Result |
|---|---|---|---|---|
| `pretrip_ok` | STT-005's "All checks passed" | OPS-007 pre-trip checklist | yes | **WIRED** (ruled 2026-09-09) |
| `arrived` | none | none — the RTM runs OPS-008 dispatch → OPS-009 track → OPS-010 delivery with nothing between | n/a | **declared, unreachable** |
| `pod_pending` | none | none | n/a | **declared, unreachable** |
| `settlement_pending` | none | TRP-P0-017 exists, but it is SNG-TRN-017 | **`trip_settlements` does not exist** | **declared, unreachable** |

**`arrived`, `pod_pending` and `settlement_pending` stay in the vocabulary and stay
unreachable.** They remain in `TripStatus::ALL`, `::OPEN` and `::LABELS`, so the token is
fixed before anything writes to the column and a later ticket that gains a gate can wire
one without renaming anything. None of them gets an edge until that happens.

**Why the vocabulary and not just the edges.** Dropping a Step 9 state would mean a later
ticket inventing its own name for the same thing — which is D-9's mistake (a field with no
defined values) one level up. Keeping it declared costs one line and fixes the word.

**Why not wire them anyway.** A state with no gate is a hidden state change dressed as
configuration: any caller could move a trip into it, and the state would assert something
no document defines and nothing checks.

This closes **D-36**, which had asked SNG-TRN-014 to decide `arrived` and which SNG-TRN-014
shipped without deciding.

---

## Defect numbering

**P1's `docs/transport/registry-defects.md` is the list.** New findings get a
D-number there, not a new scheme in this file. Where the two overlapped, the
D-number wins.

### Take your number from YOUR OWN RANGE — added 2026-09-17

On 16–17 September P1 and P3 both allocated **D-58, D-59, D-60 and D-61**, on
different branches, on the same day, to entirely different defects. Neither of
us was careless: the register has no allocator, we both read the same last-used
number, and we both counted on from it. It only became visible where the
branches met — the same shape as the `transport.permission` alias collision.

P1's four were renumbered to **D-63…D-66** because master is the shared
baseline and P1's branch had not landed. The register carries a conversion
table at the seam.

**From now on, take the next free number in your own band:**

| | Band | Next free |
|---|---|---|
| **P1** — core, orders, consignments, containers, trips, dispatch | **D-100 …** | D-100 |
| **P2** — fleet, drivers, allocation scoring, maintenance, telemetry | **D-200 …** | D-200 |
| **P3** — documents, POD, billing, collections, compliance, QC | **D-300 …** | D-300 |

Existing numbers **D-1 … D-66 are frozen** — never renumber one, because they
are cited from code comments, docblocks and commit messages across all three
sections. The bands apply to new findings only.

A three-digit band collides only if someone logs a hundred defects in one
section, and at that point we have a larger problem than numbering.

---

## 1. Who owns what

Owner = the only person who writes migrations, models or endpoints for it.
Everyone else reads through a service or an event. Table ownership is the Owner
column of Step 11's DB_Registry, read from the XLSX.

| ID | Table | Step 11 owner | Dev |
|---|---|---|---|
| DB-001 | `transport_orders` | Transport Product | P1 |
| DB-002 | `transport_trips` | Transport Product | P1 |
| DB-003 | `trip_assignments` | Transport Product | P1 |
| DB-017 | `transport_customers` | CRM/Transport | P1 |
| — | `transport_consignments`, `transport_containers`, `consignment_containers` | ruled in, see D-39/D-40 | P1 |
| DB-004 | `vehicles` | Fleet | P2 |
| DB-005 | `drivers` | Fleet | P2 |
| DB-006 | `trip_costs` | Finance Control | P3 |
| DB-007 | `trip_advances` | Finance Control | P3 |
| DB-008 | `trip_expenses` | Finance Control | P3 |
| DB-009 | `trip_documents` | Document | P3 |
| DB-019 | `transport_documents` | Document | P3 |
| DB-010 | `trip_exceptions` | **Control** | P1 — see §2 |
| DB-011 | `trip_risks` | Risk | P3 |
| DB-013 | `trip_collections` | Collections | P3 |
| DB-014 | `trip_settlements` | Finance Control | P3 |
| DB-015 | `trip_profit_snapshots` | Intelligence | P3 |
| DB-020 | `transport_policies` | Governance | P3 |
| DB-012 | `trip_bills` | **Accounts** | read only, all of us |
| DB-016 | `transport_rates` | Commercial | unassigned |
| DB-018 | `transport_suppliers` | Supplier | unassigned |

Endpoints and events follow the same rule — Step 11's API_Registry and
Event_Registry, resolved **by name**, never by the number a ticket prints.

---

### 1a. Vehicles and Drivers are P1's PLACEHOLDER, not P1's property

`transport_vehicles` and `transport_drivers` are **DB-004 and DB-005 — Fleet, owned by P2** under
TM-001 §8. The table above is unchanged and remains correct.

What is live on master today is a **temporary P1 implementation**, held deliberately and with an
agreed end. It is recorded here so it is not mistaken for ownership by anyone reading the code.

**Why it exists.** Trip and Transport Order cannot be demonstrated without vehicles and drivers.
A trip with nothing to allocate proves nothing — no allocation panel, no eligibility refusal, no
dispatch gate, no "back in 2 days" sentence. Until P2 ships Fleet, this is what keeps the demo
chain walkable end to end.

**When it ends.** When P2's Fleet module merges. P1's version is then **removed, not merged with
and not reconciled.** P2 builds their own; they do not extend ours, and they do not need our
permission or a migration plan to replace it. Agreed in advance, so it is not a negotiation at
merge time.

**Who removes it.** P1 — in the same PR that brings P2's Fleet in, or immediately after.

**The rule that goes with it:** no further investment. No new features, no refactors, no design
polish on those four screens. They are fixed only if they break the demo. Hours spent on code
scheduled for deletion are hours taken from Container 360.

#### What comes out, exactly

| | Files |
|---|---|
| Migrations | `2026_12_16_000004_create_transport_vehicles_table.php`, `2026_12_16_000006_create_transport_drivers_table.php` |
| Models | `TransportVehicle.php`, `TransportDriver.php` |
| Services | `TransportVehicleService.php`, `TransportDriverService.php` |
| Controllers | `TransportVehicleController.php`, `TransportDriverController.php` |
| FormRequests | `Store`/`Update`/`Transition` × `TransportVehicleRequest`, `TransportDriverRequest` (6) |
| Enums | `VehicleStatus`, `VehicleOwnership`, `DriverStatus`, `DriverAvailability`, `DriverComplianceStatus` |
| Routes | the `/vehicles` and `/drivers` groups in `routes/transport.php`, and their `TransportPermission` keys |
| Pages | `TransportVehicles.jsx`, `TransportVehicleDetail.jsx`, `TransportDrivers.jsx`, `TransportDriverDetail.jsx` |
| Components | `VehicleForm.jsx`, `DriverForm.jsx` |
| Nav | the Vehicles and Drivers entries in `TransportLayout.jsx` and `Sidebar.jsx`, and their routes in `app/routes.jsx` |

#### What does NOT come out — the seam

These reference a vehicle or a driver and are **P1's or P3's**, so they are rewired to P2's Fleet
rather than deleted. Anyone doing the removal must read this row before starting:

| File | Why it stays |
|---|---|
| `frontend/.../components/MasterFormFields.jsx` | **shared** — also imported by `PretripPanel`, `AllocationPanel`, `DispatchPanel`, `DocumentsPanel`. Deleting it with the forms breaks four panels that have nothing to do with Fleet |
| `TripAssignment.php` | P1. `vehicle_id` / `driver_id` are P1 columns and stay |
| `transport_trips.vehicle_id` / `.driver_id` | P1 columns. Nullable, no FK, by the team convention for a shared entity that does not exist yet |
| `AllocationService`, `VehicleEligibilityService`, `DriverEligibilityService` | allocation scoring is TM-001 §9 — **P2's domain**, currently built by P1. Handled as its own handover, not as part of this one |
| `ResourceCommitmentService` | P1. Reads `trip_assignments` + `transport_trips` only — no Fleet table — so it survives the swap untouched |
| `TransportDocumentService` | P3 |

---

## 2. Settled

| Ticket | Owner | State |
|---|---|---|
| 001, 003, 004, 006, 007, 009, 010 | P1 | built — `3fb9337d` |
| 013 Transit & Exceptions | **P1** | `2a9883c3`, vocabulary + schema. Step 11 gives DB-010 to *Control*, and "Execution" is OPS. It is his. |
| 028 Permission Matrix | **P1** | built — `App\Http\Middleware\EnsureTransportPermission`, `transport.permission:<key>` |
| 027 Immutable Audit | **P1** | `TransportAuditLog` + `RecordsTransportAudit` exist |
| Consignment / Container | P1 | **RULED 12 Sep** — D-39 canonical entities, D-40 container master + association, D-41 LR/DO stay documents |

CAPA has no SNG-TRN ticket at all. Quality is P3, so the corrective-action half
of the exception lifecycle is mine — but it needs a ticket before it can be
built. Goes in P1's `REQUEST-step12-missing-tickets.md`.

---

## 3. What we owe each other

| # | From | To | What | Status |
|---|---|---|---|---|
| C-01 | P3 | P1 | an object with blocking reasons, not a boolean | **already exists — see below** |
| C-02 | P3 | P1 | `TransportDocumentEntity::CONSIGNMENT` in `ALL` and `ACTIVE` | **done** — `cdb3d0ad`, branch `zafar/transport-p3` |
| C-03 | P3 | P1 | `TransportDocumentService::entityTypeFor()` — a `TransportConsignment` arm | **done** — same commit |
| C-04 | P3 | — | `delivery_order` into ENUM-006 (approval in `REQUEST-person3-document-entity.md`) | **done** — same commit |
| C-05 | **P2** | P1 | `FleetResourceGateway::markDispatched()` — see below, this was mis-routed to P3 | not started |
| C-06 | P2 | P3 | fuel, urea, tyre, maintenance, FASTag costs → `trip_costs` | **P3 side ready** — see below. Waiting on P2. |
| C-07 | P1 | P3 | EVT-012 `TripClosed` | needed for 018, **not** for 011/012 |
| C-08 | P1 | P3 | a way to ask "does this trip have a waived exception?" | **P3 is reading `trip_exceptions` directly meanwhile** — see below |
| C-09 | P1 | P3 | STT-006 `dispatched → in_transit`, then STT-007 → `delivered` | **blocks nothing today, but POD verification cannot fire without it** |
| C-10 | **Accounts** | P3 | fill `trip_bills.invoice_id` and emit EVT-010 `InvoicePosted` | **P3 side complete** — the queue and the door are built, see below |
| C-11 | **Accounts** | P3 | post the receipt behind `CollectionRecorded`, and own `receipt_id` / `posting_id` | **P3 side complete** — tracking works, posting is yours (D-61) |
| C-12 | P1 | P3 | STT-012 `collection_pending → closed`, whose effect is the profit snapshot | not started — and **blocked on D-58** anyway |

### C-01 — do not build a second one

The object-with-reasons shape P1 asked for is already in the codebase and
already wired:

```php
DriverEligibilityService::evaluate($driver, $trip, $tenantId)   // and Vehicle…
// → { subject, eligible, checks: [{key,label,required,passed,detail}], blockers, warnings }
```

`EligibilityVerdict::make()` builds it, `compliance_status` is one of the checks,
and each check carries its own `required` flag read from policy — so CMP §20's
"blocking must be configurable" is a settings change, not a deploy. Compliance is
also already **re-derived at dispatch**, not read from the stored pre-trip row:
`DispatchService::assertDispatchable()` → `PretripService::revalidate()`, which
reports `lapsed` separately from `blockers` so "it never passed" and "it passed
and has since expired" are distinguishable.

Nothing to add. A new `ComplianceGate` would have been the second duplicate in
two days.

### C-11 — Accounts: collections track here, the money posts with you

SNG-TRN-016 records what a customer owes on a trip, when it is due, why it is stuck and
who chased it. It does **not** post money. When a receipt is recorded, Transport moves a
tracked balance and fires `CollectionRecorded`; CTR-014's own note on API-011 is
*"Posting event generated"*, and the posting is yours.

**One thing needs your decision (D-61).** EVT-011's registry payload is
`receipt_id, invoice_id, amount`, keyed on `receipt_id+posting_id`. Transport creates
neither a receipt nor a posting, so it cannot fill those. The event currently carries:

```php
['receipt_id' => null,              // yours — we never fabricate one
 'invoice_id' => $bill->invoice_id, // known only after you call markInvoiced()
 'amount'     => '2500.00',         // this receipt, not the running total
 'collection_id' => 12, 'trip_id' => 7]   // added so you have a join key
```

**Either** confirm this two-act reading and consume the event as a trigger, **or** take
the event over entirely and we emit nothing. Both are one deletion on our side — tell us
which before you build against it.

**What you can read:** `TripCollection` carries `amount_due`, `amount_received`,
`outstanding`, `status` (`pending` / `part_paid` / `settled`, derived from the
arithmetic, never set by hand), `due_date`, `days_overdue`, `blocker_reason` and the
follow-up stamps. `TripCollectionService::ageing($tenantId)` returns the standard
0/30/60/90 buckets as decimal strings.

Overpayment is refused here on purpose — a negative balance would make the ageing total
meaningless, and a refund or credit note is your decision, not a tracking one.

---

### C-10 — Accounts: the billing handover is built and waiting for you

SNG-TRN-015 is a **trigger**, not an invoice. Step 11 is explicit about the division
and we have implemented exactly our side of it:

```
EVT-010 | InvoicePosted      | Producer: Accounts     <- yours
EVT-011 | CollectionRecorded | Producer: Accounts     <- yours
DB-012  | trip_bills         | Owner: Accounts        <- linkage, we write the trip half
```

Transport writes **no ledger entry, ever** (FORBID-002, LOCK-004). What it now does is
mark a trip billable once its POD is verified, freeze what the trip is worth, and stop.

**The queue to read:**

```php
TripBill::forTenant($tenantId)->awaitingInvoice()->get();
// status = 'prepared' AND invoice_id IS NULL
```

Each row carries `trip_id`, `billable_amount` (a decimal string, frozen at the moment
billing was prepared so a later trip amendment cannot restate an invoice you have
already raised), `currency`, and `basis` — `verified_pod` or `exception_waiver`, so you
can see which arm of the rule let it through.

**The one door back:**

```php
$bill->markInvoiced($invoiceId, $actorId);   // sets invoice_id, status = 'invoiced'
```

`invoice_id` is deliberately **not fillable** — the one column belonging to your module
is the one a Transport caller cannot set by posting a field. `markInvoiced()` is the
only way it is ever written, and it records who did it.

**Also available:** the `BillingPrepared` event fires on every prepare, carrying
`{bill_id, trip_id, amount, currency}`. Nothing subscribes yet. Note it is a
**constructed** event — API-010 promises it but the Event_Registry never defines it
(**D-60**), so if you want a different payload, say so before you build against it.

---

### C-08 — P3 is reading `trip_exceptions` directly, and would rather not

SNG-TRN-014's acceptance criterion is *"POD required before billable state **unless
approved exception**"*. That second arm needs to know whether a trip carries a waived
exception. `trip_exceptions` is DB-010 and **P1 owns it**, and there is no
`TripException` model — SNG-TRN-013 built the schema and the vocabulary, not the model.

Rather than create a model for a table P3 does not own (§1: *"Owner = the only person
who writes migrations, models or endpoints for it"*), `TripDocumentService` asks one
narrow read-only question:

```php
DB::table('trip_exceptions')
    ->where('tenant_id', $tenantId)->where('trip_id', $tripId)
    ->where('status', ExceptionStatus::WAIVED)->exists();
```

It reads no other column, so when P1 exposes an `ExceptionService` this is one line to
replace. **P1: if you would prefer that seam closed now, a single `hasWaivedException()`
on your service is all P3 needs.**

Two things worth knowing: the table has **no `deleted_at`** — its own migration records
why, *"it must never disappear from the system"* — so a soft-delete filter there is a
SQL error, not a safety net. And the arm cannot fire in production yet regardless:
`ExceptionStatus::WAIVED` is declared-but-unreachable because a waiver needs an
authorising role, and **BLK-10** means no CRM account maps to one.

---

### C-09 — POD verification is built but cannot fire until Transit is wired

> **CLOSED 2026-09-17 — Block 3 wired both edges. This entry is kept for the record.**
>
> The paragraph below was wrong for a week. It said STT-006 was deferred; the owner had
> already authorised it on 2026-09-10 (Q3) and its columns had already shipped. See
> **D-105**. P3 needed nothing from this beyond the two edges landing, and they have.

~~`TripStatus::TRANSITIONS` still has no edge out of `dispatched`. STT-006
(`dispatched → in_transit`) is the Transit half of SNG-TRN-013 and is recorded in the
code as deferred; STT-007 (`in_transit → delivered`) follows it. **Nothing writes
`delivered`.**~~

STT-008 (`delivered → pod_verified`) is now implemented on P3's side and is wired into
`TripDocumentService::verify()`. It is deliberately **conditional**: verifying a POD on
a trip that is not `delivered` records the document and leaves the trip's status alone,
rather than throwing over a gap that is not the verifier's fault.

**P1: the moment you wire STT-006 and STT-007, the POD edge starts firing with no change
on P3's side.** Nothing needs coordinating beyond you landing those two edges.

**Both are wired as of 2026-09-17.** `TripDocumentService::verify()` now has trips arriving
in `delivered` to act on, with no change on P3's side — exactly as this entry predicted.

---

### C-06 — `trip_costs` is live; here is how to write to it

**P2: nothing is blocking you.** `trip_costs` (DB-006) shipped with SNG-TRN-012 and takes
telemetry costs today. Call the service, not the model:

```php
app(TripCostService::class)->record($trip, [
    'cost_type'   => 'fuel',          // free text, normalised for you
    'amount'      => '4820.00',       // string, never a float
    'source'      => CostSource::TELEMETRY,
    'source_ref'  => $yourTransactionId,   // REQUIRED for telemetry
    'incurred_on' => '2026-09-16',
], $tenantId, actor: null);           // null actor = the system itself
```

Three things worth knowing before you wire it up:

**`source_ref` is mandatory for you and it is what makes retries safe.** There is a unique
index on `(tenant_id, source, source_ref, cost_type)`. Send the same row twice and the
second call returns the first row rather than erroring — so a re-delivered webhook or a
re-run import cannot double the trip's cost. Without a `source_ref` that protection does
not exist and the call is refused outright.

**Pass `actor: null`.** A request carrying a real user is treated as hand-entered and may
only claim `manual` or `import`. That is deliberate: a person must not be able to post a
row wearing telemetry's identity, because the deduplication trusts it.

**One transaction may carry several cost types.** `cost_type` is in the unique key, so a
single fuel bill can produce a `fuel` row and a `service_charge` row against the same
`source_ref`. It cannot produce two `fuel` rows.

`cost_type` is free text on purpose — its registry vocabulary `CST-001` is a dangling
pointer (**D-58**). `CostType::KNOWN` lists the spellings we suggest, including all five
from this contract, but nothing is rejected. Case and spacing are folded on write, so
`Fuel`, `FUEL` and `fuel` are one group in the margin.

---

### C-05 — the stub is P2's, not P3's

`PendingFleetResourceGateway` is what dispatch currently runs on, and its own
TODO reads `TODO(Person 2 / Fleet)`. It is about **writing** vehicle and driver
status when a trip departs (BRW-050: vehicle → In Operation, driver → On Trip),
and those tables are DB-004 and DB-005 — Fleet. Shivam implements
`markDispatched()`. It is not a compliance interface and not P3's.

Also flagged there: `AllocationService` (SNG-TRN-009, already merged) writes both
tables directly, predating the split. Known, not fixed, and should move behind
the same gateway when P2 supplies it.

### P1's branch already conflicts with master

`origin/feat/p1-consignment-container` vs `origin/master` conflicts in three
files — `frontend/src/components/layout/Sidebar.jsx`,
`frontend/src/components/layout/sidebarSection.js`,
`backend/bootstrap/providers.php` — because SIRE, the vendor screens, the medical
portal and the HR rail regrouping have all landed in master's navigation since
that branch forked. His to resolve, and better resolved before the branch grows.

**Correction on C-06.** DEP-003 and DEP-004 require only that a trip *exists*
(SNG-TRN-007, built). 011 Advances and 012 Costs are therefore **not blocked** —
an earlier version of this file said they were, and that was wrong. Closure
matters at 018 Profitability, which is after 015 and 017.

### C-01, the shape

Returning true/false forces the caller to invent the reason, and the reason is
what the screen has to show. BRWM §70 asks a blocked action to say what is
blocked, why, and who can resolve it.

```php
interface ComplianceGate
{
    public function statusFor(int $tenantId, int $vehicleId, int $driverId): ComplianceStatus;
}

final class ComplianceStatus
{
    public bool $compliant;
    /** @var ComplianceBlock[] each: subject, requirement, state, expired_on, owner */
    public array $blocks;
    public bool $overridable;   // false where policy forbids it outright
}
```

So dispatch can render "Vehicle fitness expired 14 Aug — Fleet" without knowing
anything about how compliance works.

---

## 4. Open, and who is waiting

Cross-referenced to `registry-defects.md` where a D-number already exists.

| Problem | Blocks | Escalation |
|---|---|---|
| **Step 11 still has zero occurrences of container or consignment** across all 14 sheets, though D-39/D-40 ruled the entities in. The ruling was an architecture approval; the canonical registry has not been written back. | traceability (DOD-014) | registry CHG |
| **No mapping from a CRM account to Step 11's nine roles** (CEO/Owner, Operations, Dispatcher, Accounts, Approver, Driver, Customer, Supplier, Admin). `users.role` is an account type, `users.internal_role` is a `staff_roles` slug, neither is that list. **This decides who may approve advances and expenses.** | every gated action | `CLARIFICATION_REQUIRED` |
| **PERM-013 marks Admin `Y*`**, footnote not in the package | registry modification | `CLARIFICATION_REQUIRED` |
| **PERM has 13 rows and no dispatch, pre-trip, override, waiver or document-verify row.** Deny-by-default means refused, not unspecified. | 009, 010, waivers | `SECURITY_REVIEW_REQUIRED` |
| **A grant is not a boolean.** PERM-001 gives Driver `Own` and Supplier `Assigned`. A gate answering true, with the caller then querying every row, hands one driver the whole tenant. | any list endpoint | design fix in P1's gate |
| **My domain's states are unregistered** — STOS-DOC 18 document statuses, STOS-FIN 14 invoice, STOS-QC 14 + 11 CAPA, STOS-CMP 10 + 6. Step 11 registers none. CLA-005 is "blocker if missing: YES". | 013 CAPA, 014, 015, 016 | `CLARIFICATION_REQUIRED` |
| **Step 12 cites IDs that do not exist** — `FRS-P0-*` (real: `TRP-P0-*`), `BR-001…029` (real: `BR-P0-001…020`). Its Traceability sheet is placeholder text. The Authority Register carries this as an unclosed OPEN ITEM: *"replace placeholder/legacy IDs with canonical Step 11/12 IDs before handing the package to developers."* | DOD-014 | `CLARIFICATION_REQUIRED` |

### Confirmed against the XLSX, not the PDF

P1 asked whether two of his findings were PDF-truncation artifacts. Both stand —
checked across all fourteen sheets of Step 11:

- **D-50, `container_type` vocabulary** — zero hits for `container_type`, `20ft`,
  `40ft`, `ISO 6346`, "high cube". Zero hits for "ISO 6346" anywhere in the
  extracted package.
- **D-52, temperature-critical marker** — zero hits for reefer, temperature or
  genset in Step 11.
- **ENUM-006 verbatim**: `lr|ewaybill|invoice|pod|driver_doc|vehicle_doc|insurance|permit|fitness|other`. Ten values, `delivery_order` absent. Confirms C-04.

The PDF warning was about registry *tables* column-wrapping and truncating enum
value lists — it does not manufacture content that is absent from the XLSX. Where
the XLSX says nothing, nothing is there.

---

## 5. Rules

1. **Step 9 > Step 10 > Step 11 > Step 12 > Step 13 > everything else.** The
   STOS-* suite and Folder_06 are reference. STOS-AIC §3 gives a different
   hierarchy — ignore it; `00_READ_ME_FIRST` governs.
2. **Read the XLSX in `Folder_00_READ_FIRST`, not the PDFs.**
3. **Resolve registry references by name, never by the number a ticket prints.**
   SNG-TRN-011 cites `DB-010`, which is `trip_exceptions`; advances are `DB-007`.
4. **No new table, field, API, state, event or permission without a Step 11 entry
   or a recorded ruling.** If you need one, it goes in `registry-defects.md`.
5. **Transport never writes ledger lines.** Emit the accounting event.
6. **Money is `DECIMAL(18,2)` with bcmath.**
7. **Every branch, PR and commit names its SNG-TRN ticket.**
8. **Don't hand Claude the whole package** — STOS-AIC §103. Per ticket: Step 9 +
   Step 10 + the Step 11 XLSX + the one ticket + the one module spec.
9. **Audit against the package, not yourself.** Step 12's Acceptance_Criteria and
   Step 13's DOD-001…015 are the checklist.

---

## Before a merge, and before retiring a test

Two rules earned the hard way in the week of 22–25 September. Both are one command.

### The collision that matters is not always a file both sides edited

The pre-merge check for the 76-commit re-base compared changed paths and came back clean: one
doc conflict, one auto-merge, one additive method. **The real collision only appeared when both
sides RAN together** — two of P2's tests asserted behaviour our D-134 and D-135 changes had
deliberately replaced, in files neither branch had touched.

> Comparing changed paths finds the edits. Running the merged suite finds the **rules**. Do both,
> and do the second one before believing the first.

### Before retiring or relocating a test, name who enforces it afterwards

Do **not** ask *"is this still our surface?"*. Ask:

> **"Who enforces this after the move, and have I read their code saying so?"**

Retiring 18 tests with the legacy master's write path, six were checked this way and each names the
Fleet test taking over. Three were not, and would have been moved to an endpoint where the rule is
not enforced at all — Fleet has never read `trip_assignments`, so its delete cannot know a vehicle
is mid-journey (**D-146**). A fourth turned out to be the only thing in the codebase enforcing
licence uniqueness (**D-145**).

Both were found by one `grep` each, after the list was written and before anything was deleted.
**A guard that disappears in a cleanup never goes red** — which is what makes this cheaper to do
than to skip.
