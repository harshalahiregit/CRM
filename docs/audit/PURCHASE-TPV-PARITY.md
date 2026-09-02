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

### 2. Work control
Work Packages, Work Authorization, Permits (`purchase_work_permits` table exists).

### 3. HSSE / Safety
Safety Engagement, Safety Strikes, Site Registers, Evidence Locker.

### 4. Governance & vendor lifecycle
Governance Dashboard, Risk & Due Diligence, Prequalification, Approval Register,
Authority Matrix, Temporary Vendors, Meeting Performance.

## Backend controllers still missing on Purchase

`Access` · `EvidenceLocker` · `GateEvent` · `GateLog` · `GateScan` · `Governance` ·
`Medical` · `OnboardingApproval` · `Permit` · `Ppe` · `PpeRequirement` ·
`SafetyEngagement` · `SafetyStrike` · `SiteRegister` · `VendorProject` ·
`VendorRisk` · `WorkAuthorization` · `WorkPackage` · `Worker`

(`Setting`, `DueDiligence` and `Prequalification` exist under slightly different
names and need no port.)
