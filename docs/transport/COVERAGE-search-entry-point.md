# Coverage — search as the entry point (CTD §4, §5)

**Person 1.** Built 2026-09-19 against `PROPOSAL-search-as-the-entry-point.md`, approved the same
day with three answers. Checked three ways, as ruled.

**Result up front:** the entry point exists, and **every search key except a trip number now ends
at the same Digital Passport**. The trip exception is the one authorised divergence and is pinned
by a test. Two of §4's eleven keys still do not resolve, for reasons outside this work.

---

## 1 · Against the documents — does every requirement have code behind it?

| Requirement | Source | Where it lives | Status |
|---|---|---|---|
| *"The preferred entry point is: Container Number"* | §4 | `TransportFinder.jsx` on the `/app/transport` index slot, autofocused | **BUILT** |
| *"All relevant search paths must ultimately lead to the same Digital Passport"* | §4 | `TransportSearchService::throughToPassport()` | **BUILT** — one authorised exception |
| *"Sangoe should immediately show"* | §5 | Enter navigates straight to the passport; no intermediate row to click | **BUILT** |
| *"One connected, searchable and auditable digital journey"* | §2 | The module now opens on a search box rather than a table | **BUILT** |
| Container number normalised for search | §7 | Pre-existing; unchanged | **BUILT** |
| LR and DO searchable | §150 NON-NEGOTIABLE | Pre-existing; now also carried through to the passport | **BUILT** |
| Invoice Number | §4, §97 | — | **BLOCKED** — Accounts owns the number; we hold only `trip_bills.invoice_id` |
| POD Number | §4 only *(§97 and §150 omit it)* | — | **NO FIELD EXISTS** — ruled: do not invent one |

### The eleven keys, measured

| # | §4 key | Resolves | Destination | Change |
|---|---|---|---|---|
| 1 | Container Number | ✅ | Passport | — |
| 2 | LR Number | ✅ | **Passport** | was the consignment |
| 3 | DO Number | ✅ | **Passport** | was the consignment |
| 4 | Transport Order Number | ✅ | **Passport** | was the order page |
| 5 | Trip Number | ✅ | Trip page | **deliberate — see §2** |
| 6 | Customer Reference | ✅ | **Passport** | was the consignment |
| 7 | Internal Consignment ID | ✅ | **Passport** | was the drawer |
| 8 | Vehicle Number | ✅ | **Passport** | was Fleet's list |
| 9 | Driver | ✅ | **Passport** | was the drivers list |
| 10 | Invoice Number | ❌ | — | named on screen as unsupported |
| 11 | POD Number | ❌ | — | named on screen as unsupported |

---

## 2 · Against the ruling — the one divergence, and why it is safe

A trip number keeps going to the trip page. That is a departure from §4's letter, approved on
2026-09-19 on the reasoning that §4's intent is traceability rather than uniformity of screen, and
that a dispatcher typing `TRP-2026-000034` wants the working screen.

Two conditions came with it and both are met:

1. **The passport is one obvious click from the trip page.** It was not. "What is being moved"
   linked to the consignment drawer and read as cargo detail, and the trip payload did not even
   know which container it was carrying. `TransportTripController::show()` now returns
   `passport`, and the trip page opens with *"Open the full journey of sgoe-402215-9 —
   everything that has happened to this container"*. Clicked in a browser; lands on the passport.
2. **The divergence is written down**, in D-117 with the reasoning, and pinned by
   `test_a_trip_number_deliberately_goes_to_the_trip_and_not_the_passport` so that a later reader
   comparing §4 to the code cannot quietly "fix" it.

---

## 3 · Against the tests — what is proved, and proved to fail

Six new tests in `TransportSearchTest` (18 total, all passing):

| Test | What it pins |
|---|---|
| `every_key_lands_on_the_same_container_passport` | §4's closing sentence — four keys, one destination, asserted on `path` |
| `a_trip_number_deliberately_goes_to_the_trip_and_not_the_passport` | The authorised exception |
| `a_vehicle_registration_reaches_the_passport_of_what_it_carries` | The chain works inside our own id space, and shows the route it walked |
| `a_vehicle_with_several_trips_returns_the_list_rather_than_guessing` | No silent choice between journeys; every row still offers a passport |
| `a_vehicle_with_no_trips_says_so_instead_of_failing_silently` | The chain-ran-out sentence |
| `a_consignment_with_no_container_explains_itself` | §8's loose cargo — no passport, and it says which hop ran out |

**Asserted on `path`, not on a flag.** A `passport` key present while `path` still pointed at a
list would pass a weaker test and ship the old behaviour — which is exactly the bug that occurred
(see §5).

**Broken two ways before being trusted:**

| Break | Caught |
|---|---|
| Revert the destination to the array-union form — the real bug | 2 failures, both named correctly |
| Let a multi-trip vehicle pick one journey | 1 failure, named correctly |

**One pre-existing test was checked rather than assumed.**
`test_every_supported_identifier_resolves_to_its_own_record` asserts paths and still passed, which
should have been suspicious. It passes for a good reason — its fixture never attaches a container,
so every key correctly stops at its own record — and the test now carries a docblock saying so.
It pins the *without-a-container* half; the new test pins the *with-one* half. Neither alone
describes the resolver.

---

## 4 · Against the screen — walked in a browser

| Walked | Result |
|---|---|
| Open `/app/transport` | The finder, box autofocused. Nine keys named; *"Invoice and POD numbers cannot be searched yet."* |
| Type `sgoe-402215-9` | *Container · matched as SGOE4022159* |
| Type `MH12DEMO01` | *Vehicle* → **Open the passport**, with the trail `MH 12 DEMO 01 → TRP-2026-000034 → sgoe-402215-9` |
| Click it | Lands on `/app/transport/containers/23` — **CONTAINER 360** |
| Type `TO-2026-000037`, press Enter | Straight to `/app/transport/containers/23` — §5's *"immediately"* |
| Type `TRP-2026-000034` | Stops at the trip, as ruled |
| Type `QQQ-NOPE-1234` | Quotes the term and names the vocabulary, including the two unsupported keys |
| Trip page → *Open the full journey* | Lands on the passport |

---

## 5 · What shipped differently from the proposal, and one bug worth recording

**The array-union bug.** `throughToPassport()` was written as `$hit + ['path' => …]`. PHP's `+`
keeps the **left** operand's keys, and `$hit` already carried a `path` — so the passport attached
correctly, the trail rendered correctly, and **every search still landed exactly where it used to.**
The feature looked finished and did nothing.

It was caught by printing the resolved `path` for seven keys rather than checking that `passport`
was present. That is the general lesson and it is the same one as D-115: **assert on the thing the
user ends up with, not on the thing you just added.** The test now asserts `path`, and reverting to
`+` is break 1 above.

**Two small things found by walking rather than by testing:**

- `ModuleShell` renders its rail with `NavLink` and no `end`, so a link to `/app/transport` stayed
  highlighted on every child route. An optional `end` was added — off by default, so every other
  module's rail is unchanged.
- The empty state was built as `KEYS.join(', ').toLowerCase()` and printed *"a container, lr,
  delivery order… number"*. An empty state is the one screen a confused person actually reads, so
  it is written out as a sentence now.

**Not built, as proposed:** no dashboard on the landing page. §4 asks for an entry point.

---

## 6 · What is still not reachable, and whose it is

| | Whose | Why |
|---|---|---|
| **Invoice Number** | Accounts / P3 | We hold a numeric `trip_bills.invoice_id`, not the number a human types. No read contract. |
| **POD Number** | Nobody yet | `trip_documents` has no `document_number`. Ruled: do not invent one. |
| **A plate Fleet holds that our placeholder table does not** | P2 / the repoint | Resolves to nothing today. Both tables currently carry the same trucks, which is why this limit is easy to miss — it will appear the day Fleet becomes the master. |
