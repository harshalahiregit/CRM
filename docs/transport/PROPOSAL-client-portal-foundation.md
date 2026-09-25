# Proposal — the client portal foundation

**Person 1 · 22 September 2026 · proposal only. No code, no migration, no route.**
Written to STOS-DEV-MASTER-001 §16 steps 1–8. Stop conditions under §19 are raised in §6.

---

## The headline, before the detail

**The door already exists, works, and carries a row-scoping model we can reuse. Two of the three
foundation pieces are "follow the existing pattern"; only field filtering is genuinely new.**

I went in expecting to design three layers. What I found is a customer portal with **fourteen live
endpoints**, a guard that has been thought about carefully, and a scoping convention that answers
D-46 for this door without touching D-46 at all. That materially changes the estimate, and it
changes it downward.

---

## 1 · Sources (§16 step 1)

| Document | Sections used |
|---|---|
| **STOS-CLP v1.0** | §1 scope · **§3 users, roles, access** · §11 documents · §20 feedback · **§23 security, privacy, isolation** · **§24 integration/API boundary** · §26 entities · §28 slice · §30 non-negotiables |
| **STOS-SEC-002** | least privilege (l.30) · no secrets to client interfaces (l.35) · **no unrestricted storage paths for protected documents (l.156)** |
| **STOS-DEV-MASTER-001** | §16 build template · §18 AI rules · §19 stop conditions |
| **STOS-MS-001 v1.1** | §4 Person 1 owns CLP architecture and portal integration contracts |
| **Code, read not assumed** | `routes/portal.php:449–487` · `EnsureClientPortalAccess` · `ClientPortalController` · `ClientContact` · `ScopeResolver` / `DataScope` · `TransportPermission::MATRIX` |

---

## 2 · The door — and the question was the right one to ask

### There are two client identities, and only one of them is a real door

| | **`User` with `role='client'`** | **`ClientContact`** |
|---|---|---|
| Created by | self-registration, `status: pending` | **invitation by a staff member** |
| Guard | none that works — the redirect loop | `EnsureClientPortalAccess`, three separate checks |
| Live endpoints | 0 | **14** |
| Transport matrix maps to it | **yes** — `ROLE_MAP['role:client'] => ROLE_CUSTOMER` | no |

The portal's own comment states the model plainly:

> *"The old CRM's model, restored: the customer company never signs in, its CONTACTS do. There is
> deliberately no /register — access always begins with a staff member inviting a real contact of
> a real customer."*

**That matches CLP §3** — *"Each client organisation may have multiple users…"* — a client
organisation with several people, not a person who signed themselves up.

### Proposal: hang Transport off the ClientContact door. Do not build a new one.

It already does what CLP §23 asks. `EnsureClientPortalAccess` checks three things separately
*because they fail for different reasons*: the token subject must **be** a ClientContact (so a
staff or vendor token cannot reach a customer endpoint even if a route is mis-registered), the
contact must be active, and portal access must be switched on — which is deliberately separate
from being active, *"most contacts are people we mail invoices to and nothing more."*

It then stashes the contact **and its client** on the request, which is what makes the next section
cheap.

**And the route file already enforces the strongest isolation rule in the design:**

> *"No route here takes a client id: the contact's own client comes off the token, so 'show me
> another customer' is not expressible."*

That is a better guarantee than any filter, because it removes the parameter rather than validating
it. Every Transport portal route must follow it.

**The broken `role=client` login is not ours and we should not fix it** — but it should be raised,
because the Transport permission matrix maps `role:client` to `ROLE_CUSTOMER`, i.e. **our matrix
points at the identity that does not work.** Raised as a defect in §6.

---

## 3 · Which rows — and this is not D-46

**D-46 is a staff problem, and the client portal never touches it.**

`SCOPE_OWN`/`SCOPE_ASSIGNED` live in `TransportPermission::MATRIX`, which answers *"may this staff
role touch this area"*. The portal does not use that matrix at all. The fourteen existing endpoints
scope like this, every time:

```php
->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
```

— with the client taken off the token, never from a parameter.

**Transport can do exactly the same, because the column already exists:**

| Table | Scoping column |
|---|---|
| `transport_orders` | `customer_id` |
| `transport_consignments` | `customer_id` |
| `transport_trips` | `customer_id` |
| `transport_containers` | **none — scoped through its consignment** |

That last row is a real design point rather than a gap: a container is a reusable asset with no
owner, so *"my containers"* means *the containers currently attached to my consignments*. It is
one join, and it must never be a direct query.

### On Zafar's `ScopeResolver` — what I would copy, and whether he should be in the room

**I would copy the shape and none of the code, and I do not think this needs a joint design.**

His primitive is right and I said so: answer *which rows* once, return `null` / `[]` / `[ids]`, and
give modules a one-line `applyToQuery()`. But all six public methods are employee-shaped
(`visibleEmployeeIds`, `canActOnEmployee`, `employeeFor`), `HrEmployee` appears nine times, and
`DataScope` is `global/own/department/branch/team` — an employee hierarchy where ours is a single
customer axis.

**More decisively: the portal already has its own answer and it is simpler than his.** His problem
is "which subset of staff may this staff member see", which needs a hierarchy walk. Ours is "this
contact belongs to exactly one client" — a single id off a token. Importing a hierarchy resolver to
express that would be more machinery, not less.

**So: he should be told, not consulted.** The overlap is real but it is one sentence —
*"we scope the portal by the client on the token; your resolver stays the staff answer"* — and if
client branches ever arrive (§6), that is the moment to revisit.

---

## 4 · Which fields — the only genuinely new work

Nothing in Transport filters a field by role today. Measured, these are what a client must never
receive:

| Table | Fields |
|---|---|
| `transport_trips` | `approved_freight`, `closure_reason`, `rejection_reason` |
| `trip_assignments` | `reason`, `override_reason` |
| `transport_drivers` | `driver_code`, `licence_number`, `licence_class`, `licence_valid_*`, `availability` |
| `trip_advances` | `decision_reason` |
| `trip_costs` | everything — costs are internal in their entirety |
| `trip_bills` | the billable amount is arguably the client's; margin never is |

CLP §3 is the rule: *"Internal salary, profitability, penalties, management notes and internal CIA
analysis are excluded."*

### Proposal: a whitelist presenter, not a blacklist filter

**The portal must never serve a Transport model directly.** Each portal endpoint returns a small,
hand-written array naming the fields a client may see. A blacklist is the wrong shape — the day
somebody adds a `margin_pct` column, a blacklist leaks it and a whitelist does not.

This is the same instinct as the route rule above: **make the wrong thing inexpressible rather than
filtered.**

I would add one test that fails if a portal response contains any field from the table above, and
break it deliberately before trusting it.

---

## 5 · CLP §3's six roles — what we can express, and what we cannot

§3 requires permissions to be *"organisation-, branch-, role-, transaction- and document-type
aware."* Measured against `client_contacts`:

| Axis | Can we express it? |
|---|---|
| **Organisation** | **Yes** — `client_id`, already enforced by the guard |
| **Role** | **Yes** — the table has `role`, and `permissions` is already a JSON list (`["invoice","estimate","contract","proposal","support","project"]`) |
| **Transaction** | **Partly** — `permissions` names modules, not transaction types. Extending it is additive. |
| **Branch** | **No.** `client_contacts` has no branch or location, and **no customer-location table exists** anywhere in the schema. |
| **Document type** | **No.** Nothing associates a contact with document types. |

**So four of CLP's six roles are expressible today** — Admin, Operations, Finance, Management map
onto `permissions` entries. **Warehouse/Gate cannot be**, because its whole purpose is branch-
scoped (*"arrival/departure confirmation, loading/sealing evidence"* at a named site), and
**Quality/Compliance only partly**, because document-type awareness is missing.

**I am not proposing to build branches.** CLP §26 lists `Customer Location` as a canonical entity
and §2 says *"no duplicate customer master"* — so customer locations belong to the Customer module,
not to Transport. That is a dependency to raise, not work to start.

---

## 6 · Stop conditions I am invoking (§19)

Four, and I would rather raise them than pick the convenient reading.

**S-1 — Two client identities, and our matrix points at the broken one.**
*§19: "Authorization or tenant boundary is unclear."* `TransportPermission::ROLE_MAP` maps
`role:client` to `ROLE_CUSTOMER`, but the working portal authenticates a `ClientContact`, which the
matrix does not know about. I propose ClientContact and propose deleting or re-pointing that
mapping — **but changing a permission mapping is a ruling, not a refactor.**

**S-2 — CLP requires branch awareness; no customer-location entity exists.**
*§19: "Business rule is missing or ambiguous."* CLP §3 requires branch-aware permissions and §26
names `Customer Location`. Neither exists. **Client Warehouse/Gate is not buildable** until
somebody owns that entity. I am not inventing one.

**S-3 — "Internal Consignment ID" and document-type visibility are undefined for a client.**
CLP §11 gives clients a document vault; nothing says *which document types* a client may open. A
POD yes, presumably — an internal cost note obviously not, an e-way bill unclear. SEC-002 l.156
forbids unrestricted storage paths, so this must be decided before any document endpoint.

**S-4 — the rate card, for the one step it blocks.**
Confirmed as you framed it: it blocks **booking and contract validation only**. It does not touch
the door, the row scoping or the field filtering. I am not proposing to build booking.

---

## 7 · What I would build, in order — each piece useful on its own

**Step 1 — one scoped, filtered read endpoint behind the existing door.**
`GET /portal/client/transport/trips` — the client's trips, whitelisted fields, scoped off the
token, no id parameter. Plus the field-leak test.
*Useful alone:* a client can see their trips. That is one of the sixteen steps, genuinely done.

**Step 2 — the trip's own view, and the container passport behind it.**
`GET /portal/client/transport/trips/{id}` with ownership asserted server-side, and the container
reached through the consignment join. This is CLP's Container 360 with the internals removed.
*Useful alone:* steps 8 and 9 of the slice.

**Step 3 — a `transport` entry in `ClientContact.permissions`, and the roles that map to it.**
Additive to an existing mechanism. Admin / Operations / Finance / Management only — the other two
are blocked on S-2 and S-3.

**Step 4 — a plain-language journey view built from what we already emit.**
Not M01–M14 (§7a: four of its fourteen have no vocabulary anywhere, and it needs a milestone entity
Step 11 does not have). What we *can* show honestly today is the events we actually emit, in the
client's language: *allocated · checks passed · dispatched · departed · delivered · POD verified ·
invoiced · paid · closed*. Nine real moments, all live, none invented.

**Named as an interim in the UI**, because §8's milestone model is the target and a client should
not be told this is it.

**Not in the foundation, and why:** booking and contract validation (rate card, S-4);
M01–M14 milestones (no registry — the package's own audit calls this a blocker); telemetry
(D-116/D-118, and it reaches no trip today); documents (S-3).

---

## 7a · M01–M14 — answered by CLP §8, and it is not a filter

I read §8 rather than taking the summary. **It is not a view over our timeline — it is a different
vocabulary**, and our internal states (`dispatched`, `pretrip_ok`, `in_transit`) do not appear in
it at all.

### Mapped against what we actually emit

| | Milestone | Our position |
|---|---|---|
| M01 | Vehicle & Driver Allocation | ✅ `vehicle.allocated` + `driver.allocated`, both live |
| M02 | Vehicle Documents / Inspection / Yard Departure | ◐ `pretrip.passed` is the inspection; vehicle documents are Fleet's; yard departure is not ours |
| M03 | Container Yard Arrival | ❌ **no event type registered anywhere** |
| M04 | Container Inspection & Loading | ❌ **nothing registered** |
| M05 | Container Yard Departure | ❌ **nothing registered** |
| M06 | Client Gate Arrival | ○ `gate.in` registered, **never emitted** (P2) |
| M07 | Loading & Sealing Complete | ❌ **nothing registered** |
| M08 | Client Premises Departure | ◐ `trip.departed` is *left the pickup point*, which is close but not the same event |
| M09 | Transit / Port or Destination Gate | ○ `port.entry` / `port.exit` registered, **never emitted** (P2) |
| M10 | Unloading / Parking / Detention | ◐ `trip.delivered` partly; **detention has nothing registered** |
| M11 | Physical Documents Collected | ○ `documents.handed_over` registered, **never emitted** (P3) |
| M12 | Client Feedback | ○ `feedback.requested` / `feedback.received` registered, **never emitted** (P3) |
| M13 | Sales Bill / Billing | ✅ `billing.ready` + `invoice.posted`, both live |
| M14 | Payment Received / Trip Closure | ◐ `trip.closed` live; **`collection.recorded` registered, never emitted** (P3) |

**Two of fourteen are fully covered. Four have no vocabulary anywhere. Five are registered types
nobody emits — and every one of those five is somebody else's to wire.**

### And the shape is wrong, not just the coverage

§8 requires each milestone to carry *"planned/actual time, location, source, actor, evidence,
remarks, exception and audit trail."* `trip_events` has `occurred_at`, `recorded_at`, `source`,
`actor_*`, `summary`, `detail` — and **no planned time, no location, no evidence link and no
exception link.** A milestone is a richer object than an event: it has an expectation as well as a
fact, which is what makes *"running late"* expressible at all.

### What the mapping costs — the honest size

This is **not** a mapping layer. It is four pieces, and only the first is small:

1. **A translation for the milestones we already emit** — M01, M13, M14 and the partials. Small;
   a table and a resolver.
2. **A milestone entity** carrying planned vs actual, location, evidence and an exception link.
   New table, new registry entries. **Under our own Hard Rule 4 this needs a Step 11 entry or a
   ruling — and AUTH-REC-001's B-08 already says Step 11 has no milestone registry.**
3. **Four new event types** (yard arrival, container inspection/loading, yard departure, loading
   and sealing). Same rule, and these are not renamings — they are *operational acts nobody
   performs in the system today*. Someone at a yard has to record them.
4. **Five emitters that are not ours** — P2's gate and port, P3's documents, feedback and
   collection.

**My estimate: the milestone timeline is larger than the rest of the foundation put together**,
and most of it is not code. It is a registry decision (B-08), four new capture points that change
what somebody does at a yard, and five emitters owned by two other people.

**So I would not put M01–M14 in the foundation at all.** It is its own piece of work with its own
blockers, and the foundation does not depend on it.

## 7b · Whether the portal resembles our screens — CLP §27 answers it

§27, read directly:

> *Status must be understandable without technical language. Clear progress indicators and
> milestone timelines. Exceptions and required actions prominent. Search by container/order/trip/
> client reference. **Single Trip/Container 360 view.** Responsive web design for gate/warehouse
> users. Confirmation for irreversible actions. Do not force clients to manually update
> information STOS can derive from system/GPS/geofence/telemetry/workflow.*

So it is neither of the two things I was unsure between. **It is the same 360 concept, in plain
language, on a phone.** Not a different screen, and not ours with columns removed.

Three consequences worth stating, because they change the work:

- **"Single Trip/Container 360 view"** is the same idea we just rebuilt. The redesign moved the
  timeline to the top, cut the repeated category words and replaced nine machine phrases with
  plain ones — **that work transfers**, and it is the reason the portal view is not a fresh design.
- **"Responsive for gate/warehouse users"** is the real constraint and our screens do not meet it.
  Container 360 is a 1440px two-column layout. A gate user is on a phone in a yard.
- **"Do not force clients to manually update what STOS can derive"** rules out asking a client to
  confirm milestones — which is the other reason M01–M14 is expensive: §8 wants those timestamps
  from GPS, geofence and workflow, and ours would have to come from someone typing.

## 7c · The whitelist through nested payloads — my answer

This was the third thing I was unsure about and it is mine to answer.

**The leak is real and predictable.** A trip carries an assignment, an assignment carries a driver,
and a driver carries `licence_number`, `licence_class`, `driver_code` and `availability`. A
whitelist applied at the top level and a `->load()` two levels down is exactly how a field escapes.

**My answer: the portal must not serve models at all, at any depth.** Not "a whitelist on the
response" — a **presenter per resource** that takes a model and returns a literal array, calling
other presenters for nested things. A model that reaches a portal response is a bug by
construction, because there is no code path that can put one there.

Concretely: `ClientTripPresenter` returns `['trip_number' => …, 'status' => …, 'vehicle' =>
ClientVehiclePresenter::from($a->vehicle)]`, and `ClientVehiclePresenter` returns the registration
and nothing else. There is no `->toArray()`, no `->only()`, no `makeHidden()` — all three are
blacklists wearing a whitelist's clothes, because they start from the full row.

**And one test that reads every portal response and fails on any key from the sensitive list**,
broken deliberately both ways: a field added to a top-level presenter, and a field added two levels
down. The second is the one that matters.

## 8 · Honest estimate, and where I am not confident

**Confident:**
- The door is the right one and needs no new authentication work. *High confidence — I read the
  guard, the routes and fourteen working endpoints.*
- Row scoping is a `where` on an existing indexed column, following a pattern used fourteen times.
  *High confidence.*
- Steps 1 and 2 are each a day or so of careful work, mostly test.

**Not confident:**
- **How much the field whitelist grows.** Six tables is what I measured; nested payloads (an
  assignment inside a trip inside a passport) are where whitelists usually leak, and I have not
  traced every nesting.
- ~~Which event categories a client may see.~~ **Answered** — §8 gives a milestone vocabulary,
  not a filter, and §7a sizes it. I am confident it does not belong in the foundation.
- ~~Whether the portal resembles the internal screens.~~ **Answered by §27** — same 360 concept,
  plain language, responsive. The redesign work transfers; the phone layout does not exist.
- **Still not confident: the responsive requirement.** §27 wants gate and warehouse users on a
  phone. Container 360 is a 1440px two-column page and the trip page is worse. I have not costed
  a phone layout and I would not guess at it.

**What I am confident is *not* true:** the earlier framing that this is "a building". That
measurement returned 0 READY because the door and the scoping were assumed missing. The door
exists, the scoping pattern exists, and one of the three pieces — field filtering — is the real
work. **The foundation is smaller than I reported on 21 September, and I would rather correct that
now than let the estimate stand.**

---

**No code. No migration. No route. Four stop conditions raised. Waiting.**
