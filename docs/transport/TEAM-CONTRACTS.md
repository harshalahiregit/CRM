# Transport OS — Team Contracts & Open Blockers

One page, three of us, two weeks. This exists so nobody builds the same table twice,
nobody waits silently on someone else, and no conflict in the spec gets resolved by
whoever happens to hit it first.

**Update it by PR, not by message.** If you change what you own, what you expose, or
what you are blocked on, edit this file in the same PR as the work. A conversation in
WhatsApp is not a record.

| | |
|---|---|
| P1 | Raza — Commercial, Orders, Consignment, Operations, Dispatch, Trip |
| P2 | Shivam — Fleet, Vehicles, Drivers, Assets, Telemetry |
| P3 | Zafar — Documents, Billing Readiness, Finance, Compliance, Quality/CAPA, AI Gateway |

---

## 1. Who owns what

Owner = the only person who creates migrations, models or endpoints for it. Everyone
else reads through a service or an event. Taken from the Step 11 DB and API registries
(read them from the XLSX in `Folder_00_READ_FIRST`, not the PDFs — the PDFs mangle the
tables).

### Tables

| ID | Table | Owner |
|---|---|---|
| DB-001 | `transport_orders` | P1 |
| DB-002 | `transport_trips` | P1 |
| DB-003 | `trip_assignments` | P1 |
| DB-017 | `transport_customers` | P1 |
| DB-004 | `vehicles` | P2 |
| DB-005 | `drivers` | P2 |
| DB-006 | `trip_costs` | P3 |
| DB-007 | `trip_advances` | P3 |
| DB-008 | `trip_expenses` | P3 |
| DB-009 | `trip_documents` | P3 |
| DB-010 | `trip_exceptions` | P3 |
| DB-011 | `trip_risks` | P3 |
| DB-013 | `trip_collections` | P3 |
| DB-014 | `trip_settlements` | P3 |
| DB-015 | `trip_profit_snapshots` | P3 |
| DB-019 | `transport_documents` | P3 |
| DB-020 | `transport_policies` | P3 |
| DB-012 | `trip_bills` | **Accounts** — read only for all three of us |
| DB-016 | `transport_rates` | unassigned — see BLK-06 |
| DB-018 | `transport_suppliers` | unassigned |

### Endpoints

| Owner | APIs |
|---|---|
| P1 | 001 create order · 002 create trip · 003 viability · 004 assign · 009 close trip · 013 trip detail |
| P2 | 014 GPS ingest |
| P3 | 005 advances · 006 expenses · 007 exceptions · 008 POD · 010 prepare billing · 011 collection · 012 control room |
| — | 015 e-way bill, unassigned |

### Events we publish

| Event | Producer | Who listens |
|---|---|---|
| EVT-001 `OrderCreated` | P1 | P1 |
| EVT-002 `TripCreated` | P1 | P1, P3 |
| EVT-003 `TripViabilityCalculated` | P1 | P3 (control room) |
| EVT-004 `TripApproved` | P1 | P1 |
| EVT-005 `TripAssigned` | P1 / P2 boundary | P1, P3 |
| EVT-006 `AdvanceRequested` | P3 | P3 |
| EVT-007 `ExpenseSubmitted` | P3 | P3, Accounts |
| EVT-008 `TripExceptionRaised` | P3 | P1, P3 |
| EVT-009 `PODReceived` | P3 | P3 |
| EVT-010 `InvoicePosted` | **Accounts** | P3 — we consume, we never emit it |
| EVT-011 `CollectionRecorded` | Accounts | P3 |
| EVT-012 `TripClosed` | P1 | P3 |

Anything not in this list does not exist yet. Do not invent one — raise it as a blocker
below and get it into Step 11 first.

---

## 2. What we need from each other

| # | From | To | What | Needed for | Status |
|---|---|---|---|---|---|
| C-01 | P1 | P3 | A trip exists — SNG-TRN-007 | 011 advances, 012 costs | **waiting** |
| C-02 | P1 | P3 | Pre-trip complete — SNG-TRN-010 | 013 exceptions | **waiting** |
| C-03 | P1 | P3 | `TripClosed` (EVT-012) with trip_id, order_id | 014 POD, 015 billing | **waiting** |
| C-04 | P2 | P3 | Fuel, urea, tyre, maintenance, FASTag costs → `trip_costs` | 012 costs, 018 profitability | **waiting** |
| C-05 | P3 | P1 | `ComplianceStatus(vehicle, driver)` — blocks dispatch when invalid | 009 allocation | **not started** |
| C-06 | P3 | P1, P2 | `DocumentStatus` / billing readiness for a trip | control room, closure | **not started** |
| C-07 | P2 | P1 | Eligible + available vehicles for a trip | 009 allocation | **waiting** |
| C-08 | Accounts | P3 | `InvoicePosted`, `CollectionRecorded` | 016 collections | **not started** |

DEP-003 to DEP-009 in Step 12 are all marked **Blocking**, and every one of them ends at
P3. Practically: most of my chain cannot start until P1 has a trip on the table.

---

## 3. Open blockers — do not resolve these alone

Each one is a real contradiction between controlled documents, not a preference. The
Authority Register's own rule is *"Never resolve a conflict by assumption"*, and its
conflict matrix marks most of these **Critical**. Raise, don't guess.

| ID | Problem | Blocks | Escalation | Status |
|---|---|---|---|---|
| BLK-01 | **Container / Consignment has no canonical anything.** Zero mentions in Step 11 and zero in Step 12's thirty tickets. But it is the milestone's vertical slice, the whole of STOS-CTD, and MAM §7 assigns us ownership of it. | Container 360, universal search, the 30 Sep slice | `ARCHITECTURE_REVIEW_REQUIRED` | open |
| BLK-02 | **Dual mode.** Owner wants Transport installable as a CRM module *and* standalone on its own domain. Integrated mode pulls customer/driver from CRM masters; standalone has no CRM to pull from, so it needs native ones — which is the duplicate canonical entity that LOCK-010 / FORBID-005 mark RED. | every master we consume; billing + settlement most of all | `ARCHITECTURE_REVIEW_REQUIRED` | open |
| BLK-03 | **Trip states.** Step 9 locks 16. Step 11 `ENUM-001` has 12 — missing `pretrip_ok`, `arrived`, `pod_pending`, `settlement_pending`. Step 9 outranks Step 11. | 007, 010, 014 | `ARCHITECTURE_REVIEW_REQUIRED` | open |
| BLK-04 | **Exception lifecycle, three answers inside the authority tier alone.** Step 3 `TRP-P0-012` has 4 states (incl. `waived`), Step 9 has 6, `ENUM-004` has 5. | 013 | `CLARIFICATION_REQUIRED` | open |
| BLK-05 | **My whole domain's states are unregistered.** STOS-DOC defines 18 document statuses, STOS-FIN 14 invoice statuses, STOS-QC 14 + 11 CAPA, STOS-CMP 10 + 6 — about 73 values, and Step 11 registers none of them. CLA-005 is "blocker if missing: YES". | 013, 014, 015, 016 | `CLARIFICATION_REQUIRED` | open |
| BLK-06 | **Lane / Route.** Exists as `BO-004` in Step 2 only. Not in Step 9's domain model, not in Step 11. But `transport_rates` is lane-keyed and viability needs distance. | 005 rate card, 008 viability | `ARCHITECTURE_REVIEW_REQUIRED` | open |
| BLK-07 | **Step 12's own references point at IDs that don't exist.** Tickets cite `FRS-P0-001…022`; the real FRS IDs are `TRP-P0-001…022`. Tickets cite `BR-001…029`; the real rules are `BR-P0-001…020`. The Traceability sheet is unfilled placeholder text. The Authority Register lists this as an OPEN ITEM "to replace before handing the package to developers" — it wasn't. | traceability, which is a mandatory DoD gate (DOD-014) | `CLARIFICATION_REQUIRED` | open |
| BLK-08 | **Permission matrix has 13 rows and no dispatch, override, pre-trip or document rows.** Deny-by-default means those actions are *refused*, not merely unspecified. The gate now names each hole when it refuses — see `PermissionRegistry::UNREGISTERED`. | dispatch (009), pre-trip (010), waivers, document verification | `SECURITY_REVIEW_REQUIRED` | open |
| BLK-10 | **Nothing says how a CRM account becomes one of Step 11's nine roles.** The sheet is keyed on CEO/Owner, Operations, Dispatcher, Accounts, Approver, Driver, Customer, Supplier, Admin. The CRM has `users.role` (account type) and `users.internal_role` (a `staff_roles` slug) and neither is that list. The map ships **empty**, so today only `role=admin` can do anything. Run `php artisan transport:roles <tenant>` to see who is locked out. **This decides who may approve advances and expenses** — it needs sign-off, not a guess. | everything, for everyone but an admin | `CLARIFICATION_REQUIRED` | open — **needs a decision this week** |
| BLK-11 | **PERM-013 marks Admin `Y*`** and the footnote is not in the package. Refused until somebody produces it. | registry modification | `CLARIFICATION_REQUIRED` | open |
| BLK-09 | **Two API registries.** Step 4 and Step 11 both number API-001…015 with different paths, offset from 003 onward. Step 11 governs. | any endpoint work | noted — follow Step 11 | resolved by rule |

---

## 4. Rules we all follow

1. **Step 9 > Step 10 > Step 11 > Step 12 > Step 13 > everything else.** The STOS-* suite
   and Folder_06 are reference. Where STOS-AIC §3 gives a different hierarchy, ignore it —
   `00_READ_ME_FIRST` governs.
2. **Resolve registry references by name, never by the number the ticket prints.**
   SNG-TRN-011 cites `DB-010`, which is `trip_exceptions`; advances are `DB-007`. Every
   ticket is wrong this way. Look the table up by name in the Step 11 XLSX.
3. **No new table, field, API, state, event or permission without a Step 11 entry first.**
   If you need one, it goes in section 3 above, not in a migration.
4. **Transport never writes ledger lines.** Emit the accounting event; PostingService owns
   the ledger. Prohibited independently in five documents.
5. **Money is `DECIMAL(18,2)` with bcmath.** No floats, no new money library.
6. **Every branch, PR and commit names its SNG-TRN ticket.**
7. **Don't hand Claude the whole package.** STOS-AIC §103 says give it only the documents
   the task needs. Per ticket: Step 9 + Step 10 + the Step 11 XLSX + the one ticket + the
   one module spec. Nothing else.
8. **Audit against the package, not against yourself.** Step 12's Acceptance_Criteria sheet
   and Step 13's DOD-001…015 are the checklist. If a gate has no evidence, it isn't done.

---

## 5. What is actually startable today

| Ticket | Sprint | Status | Owner | Where it is |
|---|---|---|---|---|
| SNG-TRN-028 Permission Matrix | S1 | Ready | P3 | **Gate built** — `packages/transport/src/Access`, middleware `transport.permission:domain,action`, 10 tests. Waiting on **BLK-10** before anyone but an admin can be granted anything. |
| SNG-TRN-027 Immutable Audit | S1 | Ready | P3 | **Next.** The host already has a polymorphic, tenant-scoped, actor-snapshotting trail (`AuditLogService`, `Auditable`), and nothing in the codebase mutates an audit row. The delta is enforcing that — STOS-SEC §109 wants append-only, and today nothing stops an update or a delete. |

Everything else in my chain waits on C-01 or C-02.

### How to use the gate

```php
Route::post('/trips/{trip}/pod', [PodController::class, 'store'])
    ->middleware('transport.permission:pod,submit');
```

The middleware puts the resolved scope on the request. **Use it** — passing the
gate is not the same as being allowed to see everything:

```php
$scope = $request->attributes->get('transport_scope'); // full | own | assigned

$trips = match ($scope) {
    'full'     => Trip::forTenant($tenantId),
    'own'      => Trip::forTenant($tenantId)->where('driver_id', $user->id),
    'assigned' => Trip::forTenant($tenantId)->where('supplier_id', $user->supplier_id),
};
```

Note: SNG-TRN-019, 022, 023, 024, 025 are status **Backlog**, not Ready — they are not
approved for implementation yet, whatever the milestone slide shows.
