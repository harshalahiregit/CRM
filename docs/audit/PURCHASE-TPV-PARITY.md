# Purchase ← TPV parity build

**Rule:** Purchase mirrors TPV exactly — same logic, same screens, same field names.
Only the database differs (`purchase_*` tables / `Purchase\*` models). Nothing is
redesigned on the way across.

## Why this exists

Purchase's backend is already at or ahead of TPV on paper (43 controllers vs 41,
51 services vs 35, 61 models vs 44), but ~22 TPV **screens** have no Purchase
page, so the module looks empty to a user. The gap is mostly UI plus a few admin
endpoints that exist on the vendor-portal side but were never exposed to staff.

## Slices (in build order)

### 1. Workforce  ← DONE (bar the vendor-scoped dashboard)
| Piece | Backend | Frontend |
|---|---|---|
| Workers list + stats | ✅ store/update/destroy/stats/badge added | ✅ `PurchaseWorkers.jsx` |
| Worker Wizard (5 step) | ✅ medical/training/induction endpoints added | ✅ `PurchaseWorkerWizard.jsx` |
| Medical fitness register | ✅ `medicals()` | ✅ `PurchaseMedicalFitness.jsx` |
| PPE matrix | ✅ admin catalogue + issue routes added | ✅ `PurchasePpeMatrix.jsx` |
| Gate log / attendance | ✅ built from scratch — 2 tables, service, 7 routes | ✅ `PurchaseGateLog.jsx`, `PurchaseWorkforceAttendance.jsx` |
| Workforce dashboard | `summary()` exists (vendor-scoped) | deferred — overlaps `PurchaseWorkers` + `PurchaseWorkforce` |

The gate is the one piece that was a genuine BUILD rather than a port: Purchase
could decide whether a worker may enter and recorded nothing when it did.
See `PurchaseGateTest` for the two rules worth keeping: a refusal is not a
crossing (it must not consume the in/out alternation or reach the roster), and
attendance hours stay null on a day with no exit.

Nav: Workforce · Medical Fitness · PPE Matrix · Competency.

**PPE differs by design.** TPV's matrix is PRESCRIPTIVE (role → required PPE, from
an admin-configurable requirements table). Purchase has no such table, so its
matrix is OBSERVED: designation → the kit workers in that role actually hold,
coloured by coverage. Making it prescriptive needs a `purchase_ppe_requirements`
table + model + CRUD — a slice of its own, not a port.

Still missing for full PPE parity (all admin-side routes):
tenant-wide `/ppe/summary` and `/ppe/compliance` (service has per-vendor and
per-worker only), `/ppe/item/{product}/holders` and `/image`, issue `replace` and
`use` (no service methods), and the requirements CRUD above.

Routes `/app/purchase/workers` + `/workers/:id`; sidebar gained **Workforce** and
**Competency** (Competency was fully built server-side but had no nav entry, so
it had never been reachable).

#### Two real bugs found and fixed while porting
1. `saveMedical` took `valid_until` / `provider`, but the service writes
   `expiry_date` / `examiner_name` — medical expiry and examiner were accepted,
   reported saved, and silently discarded. Both names are now accepted and mapped.
2. There was **no admin training endpoint**, yet `stepThreeCleared()` requires a
   training AND an induction — so an admin-registered worker could never leave
   step 3 and could never be badged. `POST /workforce/workers/{worker}/training`
   added.

#### Schema parity — CLOSED (migrations 2026_12_14_000001..3)
- `purchase_worker_medicals` +16: exam type, clinic, vitals (height/weight/BP/
  vision), scored screening (responses/score/band), signature, §16 capture
  (photo/geo/IP), `valid_until`, `recorded_by`.
- `purchase_worker_inductions` +11: trainer, training date, duration, topics,
  score, passed, photo/signature/thumbprint, `valid_until`, `recorded_by`.
- `purchase_workers` +22: blood group, skill category, trade, age reason,
  emergency contact/phone, BOCW no., experience, joining/exit date, project,
  site, department, and the card + 3-punch discipline ladder.
  (`photo_path` already existed — the worker photo was always storable, it simply
  had no endpoint or UI reaching it.)

Deliberately NOT copied: TPV's medical_* / induction_* / ppe_* / training_*
columns on the worker row. Purchase keeps those normalised in its child tables,
and mirroring them onto the worker would create a second, drifting answer.

`punch_*` and `card_status` are out of `$fillable` on purpose — like the badge,
they are discipline actions the service writes, never mass-assigned from a request.

#### A third silent-drop bug, found by probing
`PurchaseWorkforceService::cleanWorker()` whitelists the columns that reach the
model, so the new worker fields were validated, accepted, reported saved — and
stripped. Adding a column to `$fillable` is NOT enough here; it must also be
listed in `cleanWorker()`. Verified end-to-end with a create+update probe.

#### Still open
Bulk worker upload (CSV + photo ZIP), worker photo upload endpoint, external
medical report upload, PPE issue-from-inventory, and badge QR (`qr_token` is
`$hidden` and `badge()` never returns it) — all endpoint work, no longer schema.

Already built and reusable: `PurchaseWorkforceService` (create, update, delete,
saveMedical, saveTraining, saveInduction, activateBadge, suspend, reinstate,
terminate, readiness, gateDecision, summary), `PurchasePpeService`,
`PurchasePortalWorkforceController` (full vendor-side flow), and every model
(`PurchaseWorker`, `PurchaseWorkerMedical`, `PurchaseWorkerInduction`,
`PurchaseWorkerPpeIssue`, `PurchaseWorkerDocument`, `PurchaseWorkerTraining`,
`PurchaseWorkerCompetency`).

### 2. Work control  ← DONE
| Piece | Backend | Frontend |
|---|---|---|
| Permit To Work + JSA | ✅ lifecycle columns, JSA table, service, 11 routes | ✅ `PurchasePermits.jsx` |
| Work packages + activities | ✅ 2 tables, service, controller | ✅ `PurchaseWorkPackages.jsx` |
| Work authorisation | ✅ derived per call, never stored | ✅ `PurchaseWorkAuthorization.jsx` |

Rules worth keeping (see `PurchasePermitTest`, `PurchaseWorkPackageTest`):
- No permit approval without a JSA, and none for a non-Active vendor.
- Rejection remarks are required; `expireLapsed` leaves `Requested` alone.
- Raising a permit is staff; deciding is admin. Whoever raises must not clear.
- Residual risk is per JSA STEP — one step can stay high-risk while the rest
  are low, which a permit-level figure hides.
- Competency is required only where an ACTIVITY names one, and `Expiring`
  counts as held (it has not lapsed; refusing would stop covered work).
- `required:false` checks are advisory — a worker can be authorised while one
  fails. Work-package assignment and the permit check are both advisory.

### 3. HSSE / Safety
Safety Engagement, Safety Strikes, Site Registers, Evidence Locker.

### 4. Governance & vendor lifecycle
Governance Dashboard, Risk & Due Diligence, Prequalification, Approval Register,
Authority Matrix, Temporary Vendors, Meeting Performance.

---

## 5. Onboarding wizard — one implementation, not two

Added 2026-09-07 after a run of "why is Purchase so different and confusing?"
bugs that all turned out to be the same bug.

### The finding

The **backends are already twins**: `TpvOnboardingService` and
`PurchaseOnboardingService` define the same six steps with the same keys, labels
and detail strings. The divergence is entirely frontend.

| | TPV | Purchase |
|---|---|---|
| Wizard | 2,741 lines, one component for admin **and** portal (`useVendorModule`) | 690-line portal page + separate 79-line admin wrapper |
| Profile fields rendered | 44 | 16 |
| Doc categories · status filter · staged uploads · drag-drop · upload progress · version history · search | ✅ | none |

Purchase's onboarding is a **re-implementation**, not a configuration. That one
fact explains every onboarding bug found this session — each was a copy that
drifted, not a shared thing that broke:

- Purchase's form sent `address`; the server knew `registered_address` → silently
  dropped by `validated()` on every save, 200 OK, gone.
- Purchase's screen keyed on status `'Pending'`; the server says `Under_Review` →
  "Not Uploaded" pill, no admin approve/reject, no re-upload between upload and
  rejection.
- Purchase's portal authenticates as `PurchaseVendor`, and
  `PurchaseDocumentService::upload()` demanded a `User` → every portal upload a
  TypeError 500. It had never worked.
- Purchase's error handler read `message` first → the literal words "Validation
  failed" and nothing else.

None were possible on TPV, which has one implementation.

### Why it happened

Module isolation is right for data and services and wrong for the presentation
layer, where it buys nothing and costs exactly this. `useVendorModule` **already
resolves a full Purchase config** (`isPurchasePortal → purchasePortalApi`) and
its own docblock says it exists so the same components serve both. The onboarding
wizard is simply the piece never routed through it.

### Phase A — accepted shape ✅ (2026-09-07)

Backend only, zero UI risk, independently valuable. Makes Purchase's API a true
superset so nothing can be silently dropped when the forms are shared.

- [x] **15 missing profile rules added to Purchase.** Its rule set was a strict
  SUBSET of TPV's — 33 of 48, nothing going the other way: `dob`, `gender`,
  `profile_photo`, `emergency_contact`, `emergency_phone`, `authorized_id_proof`,
  `estimated_workforce`, `company_reg_date`, `registration_date`, `linkedin`,
  `facebook`, `twitter`, `instagram`, `youtube`, `portfolio`. Purely additive —
  `profile` is a JSON column, no migration. **Both engines now accept the same 50
  keys**, asserted by test. One edit covered admin AND portal because each engine
  shares one FormRequest across both controllers.
- [x] **Purchase portal work-start letter.** The letter existed and was exposed to
  administrators only; the vendor it is about could not reach it. Added
  `GET /api/portal/purchase/onboarding/{onboarding}/work-start-letter`
  (own-vendor scoped) + `purchasePortalApi.onboarding.workStartLetter`. All four
  API clients now carry it.
- [x] **Purchase admin `saveProfile` aligned** — it was the fourth controller and
  had been missed when the draft/`skipped` handling went into the other three, so
  it would 500 on an empty draft.

Tests: `OnboardingProfileValidationTest` (23, both engines) ·
`OnboardingWorkStartLetterTest` (6, both engines).

### Phase B — config, TPV unchanged (not started)

- [ ] `cfg.kickoff` on `useVendorModule`, defaulting to today's `kickoffApi` +
  `subject_type: 'vendor'` so TPV stays byte-for-byte. **This is the only real
  design work**: the wizard's 363-line kickoff step is hardcoded to
  `kickoffApi.list({subject_type, subject_id})`, while Purchase's portal kickoff
  is a different *shape* — two self-scoped methods (`get`, `accept`).
- [ ] Purchase portal onboarding entry resolver (~40 lines), mirroring
  `PortalOnboardingEntry`. The "no `:id` in the Purchase URL" problem is already
  solved on TPV this way, and `purchasePortalApi.onboarding.self()` exists —
  cleaner than TPV's `list()[0]`. **No wizard change needed.**
- [ ] **Open risk to check first:** the wizard calls `useAuth()` for `user` when
  seeding the profile, and the Purchase portal holds a `PurchaseVendor` token with
  no `User` session.

### Phase C — repoint and delete (not started)

- [ ] Point `/purchase-portal/onboarding` and `/app/purchase/onboarding/:id` at the
  shared wizard; delete `PurchasePortalOnboarding.jsx` (690) and
  `PurchaseVendorOnboardingWizard.jsx` (79). Net ≈ **−650 lines**.
- [ ] Purchase admins gain a real decision modal. Today
  `PurchaseVendorOnboardingWizard` sends the hardcoded strings `"Approved"`,
  `"Rejected"`, `"On hold"` — **no Purchase decision has ever carried a real
  reason**, where TPV requires remarks to reject.

### Known-symmetric, not a gap

`downloadVersion` / `restoreVersion` exist on both ADMIN clients and neither
portal client, so document version history is admin-only on both engines. Confirm
intent before "fixing".

---

## 6. Workforce — the portal now uses the same screens

Added 2026-09-07, from "purchase vendor having workforce different ui — keep same
as tpv everything".

### Root cause: the two clients named the same endpoints differently

TPV's admin and portal clients deliberately share ONE namespace and ONE set of
method names, so every workforce component is written once as
`api.workers.list(...)` and `const api = isPortal ? portalApi : tpvApi` serves
both surfaces. Purchase broke that convention:

| | admin (`purchaseApi`) | portal (`purchasePortalApi`) |
|---|---|---|
| list | `workforce.workers()` | `workers.list()` |
| one worker | `workforce.worker(id)` | `workers.get(id)` |
| medical | `workforce.saveMedical()` | `workers.medical()` |
| counters | `workforce.stats()` | `workers.summary()` |

Same endpoints, same server, two vocabularies. So `PurchaseWorkers` (637 lines)
and `PurchaseWorkerWizard` (2,244) could not be pointed at the portal, and
`PurchasePortalWorkforce.jsx` re-implemented the list plus a cut-down 5-step
wizard in 541 lines — a different layout showing less of the same data, sitting
beside a wizard the portal never used.

### Done ✅

- [x] **`purchasePortalApi.workforce`** — the admin client's shape over the portal's
  endpoints. All 21 admin method names now resolve; `workers`/`ppe` are untouched
  so existing portal screens keep working. Lifecycle calls (activate, suspend,
  terminate, reinstate) and the tenant-wide registers/gate log are REFUSED here,
  as `portalApi` refuses them for TPV — whether a worker may walk on site is the
  site's decision.
- [x] **`PurchaseWorkers` + `PurchaseWorkerWizard` serve both surfaces** via
  `useVendorModule()`. Purchase resolves the portal from the PATH, not a role:
  a Purchase vendor holds a PurchaseVendor token and has no `user.role`.
- [x] **`manage = canManagePR(user) || isPortal`** — without it every gate was
  false on the portal and the vendor lost the Register Worker button on their own
  screen. Grants only register / delete-pending / issue-PPE; activation stays on
  the separate `admin` gate, which stays false.
- [x] Portal routes `/purchase-portal/workforce` and `/workforce/:id` point at the
  shared components; vendor picker hidden (one employer, assigned from the token).
- [x] **Deleted `PurchasePortalWorkforce.jsx`** (541 lines).

### The rail — 4 of TPV's 5 sections

`PurchasePortalWorkforceShell` mirrors `PortalWorkforceShell`, mounted at
`/purchase-portal/workforce` with `index → dashboard`.

| Section | Purchase |
|---|---|
| Dashboard | ✅ **built** — `PurchaseWorkforceDashboard` |
| Workers | ✅ shared components (above) |
| PPE | ✅ `PurchasePortalPpe`, moved into the rail as it is on TPV |
| Attendance | ✅ **built** — see below |
| Safety Strikes | ❌ still a whole engine — see "Remaining" |

- [x] **Attendance / gate log / on-site roster in the portal.** Purchase had built
  the entire gate engine (two tables, a service, seven admin routes) and exposed
  none of it to the vendor, while TPV's portal has shown it since day one.
  `PurchaseGateService::stats()` and `onSite()` gained an OPTIONAL `?int $vendorId`
  (null = tenant-wide, so the admin path is byte-for-byte unchanged); four
  read-only portal routes were added under `PurchasePortalWorkforceController`.
  `purchasePortalApi.gate` mirrors `purchaseApi.gate`'s names, so
  `PurchaseWorkforceAttendance` is one screen on both surfaces.
  **Read-only on purpose**: recording a crossing stays the security desk's act —
  a vendor able to write its own scans could manufacture attendance.
  Scope comes from the TOKEN: a supplied `vendor_id` cannot widen it.
  Tests: `PurchasePortalGateTest` (10).
- [x] **Vendor-scoped workforce dashboard** — the six counters TPV derives
  (total · active · pending medical · pending induction · PPE pending · expiring
  badges), from one worker-list request so no second source of truth can
  disagree with the rows beneath it. Purchase reads `current_step` (the highest
  step cleared) where TPV inspects nested medical/induction records.
  Terminated workers are excluded from "pending": chasing someone who no longer
  works there is noise.
- [x] PPE's duplicate top-level nav entry removed — it lives in the rail now, as
  on TPV. The `/purchase-portal/ppe` route stays so existing links keep working.

### Remaining — Safety Strikes

The last TPV section, and the only one that is a genuine BUILD rather than
exposure: Purchase has **no strikes engine at all** — no table, no model, no
service, no controller, no admin screen. TPV has `TpvStrikes` plus its backend.

Scope, roughly: migration + model + service + admin controller/routes + admin
register UI + portal read-only view. It is a slice of its own, not a tail end of
this one, and it is deliberately NOT in the rail until it exists.

### Note

`PurchasePortalPpe`'s docblock claims "Purchase vendors have no workers of their
own". That is false — they do, and this is their screen. Stale comment, worth
correcting when that file is next touched.

---

## 7. Bulk worker upload + the measured admin gap

Added 2026-09-07, from "many things are missing in purchase for both side and
bulk uploads one of them is missing".

### Bulk upload ✅

Purchase had **no bulk import at all** — no route, no service, no button, on
either surface. A vendor arriving with forty people registered them one at a
time. TPV has had it for both its admin and its portal throughout.

- [x] **`App\Support\Shared\WorkerImport`** — the READING, shared. All the
  knowledge is in the edges, and copying 230 lines across would have been the
  fifth Purchase-copy-of-a-TPV-screen to drift this week: the UTF-8 BOM Excel
  writes into cell A1; four date formats people actually type; Excel silently
  rewriting a 12-digit Aadhaar as `1.23E+11` on save; ZIP photos matched against
  five plausible names per row; and the honest summary ("Nothing was imported"
  rather than "0 worker(s) imported successfully").
- [x] **`PurchaseWorkforceService::bulkUpload()`** — the WRITING, Purchase's own,
  against `purchase_workers`. The isolation that matters is the data, not the CSV
  parser. Same column order as TPV so one template serves both engines.
- [x] `POST /api/purchase/workforce/workers/upload` (admin — `vendor_id`
  **required**: TPV once fell back through a chain of maybes and could land an
  import on the first vendor in the tenant) and
  `POST /api/portal/purchase/workers/upload` (portal — vendor from the TOKEN, so
  an import cannot be aimed at anyone else's books).
- [x] Bulk Upload button + modal on `PurchaseWorkers`, serving both surfaces:
  sample-CSV download, per-row duplicate and error lists.

Tests: `PurchaseWorkerBulkUploadTest` (9) alongside `WorkerBulkUploadTest` (8).

- [x] **TPV moved onto the same reader.** Both engines now share one importer;
  TPV's own 8 tests passed unchanged through the refactor, which is what made it
  safe to do. Leaving two implementations of one thing is the exact pattern this
  file exists to record, so it was not left.

### The measured gap — 13 worker endpoints

Counted from `route:list`, normalising Purchase's `workforce/` against TPV's
`workers/`. TPV 27 worker endpoints, Purchase 26, with these 13 absent:

| Endpoint | Note |
|---|---|
| `workers/upload` | ✅ **done above** |
| `workers/{id}/decide` | approve/reject a worker |
| `workers/{id}/toggle-status` | |
| `workers/{id}/mark-medical` · `mark-induction` · `mark-ppe` · `mark-card-status` · `mark-punch` | the admin "record it for them" shortcuts |
| `workers/{id}/progress` | Purchase computes this client-side instead |
| `workers/{id}/trainings` | Purchase has tenant-wide `workforce/trainings`, not per-worker |
| `workers/{id}/authorization` | work authorisation |
| `workers/{id}/attendance` | ✅ exists as `gate/workers/{id}/attendance` |
| `workers/{id}/strikes` | blocked on the strikes engine (§6) |

### Whole namespaces Purchase does not have

`access` · `approvalRegister` · `employees` · `evidence` · `governance` (admin) ·
`permits` · `ppe` (13 admin endpoints — Purchase derives its matrix client-side) ·
`registers` · `safety` · `strikes` · `workAuthorization` · `workOrders` ·
`workPackages`

Plus 27 `vendors/*` endpoints (temporary-access convert/expire/extend, awards,
referrals, scorecard, shipments, VPI snapshot/history, escalation, employees,
projects, gates).

### Portal-side gaps still open

`governance.ppeMatrix` (the one Governance tab Purchase lacks) ·
`myWork.{summary, kb, kbArticle, ticket, raiseTicket, replyTicket}` ·
`ppe.{returnIssue, workerCompliance}` · `strikes` (5) · `workPackages`

## Backend controllers still missing on Purchase

`Access` · `EvidenceLocker` · `GateEvent` · `GateLog` · `GateScan` · `Governance` ·
`Medical` · `OnboardingApproval` · `Permit` · `Ppe` · `PpeRequirement` ·
`SafetyEngagement` · `SafetyStrike` · `SiteRegister` · `VendorProject` ·
`VendorRisk` · `WorkAuthorization` · `WorkPackage` · `Worker`

(`Setting`, `DueDiligence` and `Prequalification` exist under slightly different
names and need no port.)
