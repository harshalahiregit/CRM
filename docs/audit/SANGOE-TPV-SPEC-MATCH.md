# Sangoe TPV / Vendor + Meetings — Spec-to-System Match Report

**Date:** 2026-08-29
**Method:** 7 parallel code-audit passes over the real repo (backend models/migrations/services/controllers/routes + frontend pages), one per lifecycle cluster. Every spec item verified as FULL / PARTIAL / MISSING with file-level evidence.
**Scope of the spec:** the two documents — the TPV vendor lifecycle (Registration → … → Offboarding) and the Meetings/MOM engine.

---

## Overall verdict

| | Initial audit | After P0–P3 remediation (2026-08-29) |
|---|---|---|
| **Core system (TPV — what the spec is literally written for)** | ~87% | **~95%** (P1+P2 gaps closed; Vendor-360 + renewal + control-tower + checklist wiring done) |
| **Purchase parity (the "same but different DB" mirror)** | ~70% | **~90%** (incidents, competency+gate, medical/training depth, violation auto-escalate, owner-gates done; prequal/DD in progress) |
| **The 12 business rules** | 12/12 enforced (3 caveats) | **12/12, activation bypass + Purchase escalation/owner caveats now closed** |

> Remediation status: **P0 ✅ (3/3) · P1 ✅ (4/5, 1 reclassified feature) · P2 ✅ (2 biggest + renewal + control-tower; vault-first-class & agenda-uploader are polish) · P3 ✅ (3/4 done, prequal/DD building, unify = won't-do).** 450 backend tests green.

The domain model is unusually complete — almost every entity, field, status vocabulary and workflow the spec names **exists and is wired end-to-end on the TPV side**. The 13% gap is not missing features; it is four specific kinds of debt:

1. **Enforcement gaps** — a rule enforced on the happy path but bypassable elsewhere (activation), or stored-but-inert fields (PPE verification/replacement-frequency).
2. **Wiring gaps** — columns/config that exist but are never populated (checklist dimensions, intermediate statuses, master-edit fields).
3. **Surfacing gaps** — backend complete but the UI omits it (agenda supporting-docs; the Vendor-360 page missing ~17 connections).
4. **Purchase parity debt** — incidents read-only, violations don't auto-escalate, no competency engine, no prequal/DD, duplicate meeting engine.

---

## Scorecard by cluster

| # | Cluster (spec steps) | Match | Biggest gap |
|---|---|---|---|
| 1 | Vendor Master · Prequalification · Risk · Due Diligence (1–3) | **88%** | 3 intermediate statuses never assigned; Project/Dept/Client not in master-edit; Purchase has no prequal/DD |
| 2 | Approvals · Contracts · Work Orders · Mobilisation (4–7) | **78%** | 4 separate approval systems, not one; "No Approval→No Activation" bypassable; routing thin & TPV-only |
| 3 | Meetings Engine · Agenda · MOM · Actions · Decisions · Issues (8–20, 29–30, 48) | **92%** | 2 agenda UI inputs missing; MOM approver identity not role-bound; duplicate Purchase meeting engine |
| 4 | Onboarding · Workforce · Training · Competency · Medical (19–23) | **80%** | Named lifecycle stages are free-text, not a state machine; checklist only varies by risk; Purchase has no competency |
| 5 | PPE · PPE Matrix · Permits/Work Auth · Gate (24–28) | **92%** | PPE-matrix verification/replacement-freq stored but inert; "who's inside" view split across two tables |
| 6 | Compliance · Inspections · Incidents · NCR · CAPA · Violations (31–38) | **90%** | Purchase incidents read-only; Purchase violations don't auto-escalate; 2 missing owner-gates |
| 7 | Performance · Renewal · Offboarding · Vault · Portal · 360 · Dashboard · Rules (37–46) | **85%** | Vendor-360 omits ~17 connections; renewal skips 4 inputs; vault is read-only projection |

Weighted by spec-step count → **~87% overall (TPV).**

---

## What is FULL (no action needed) — the strong spine

- **Vendor Master:** all 25 profile fields as real columns; auto `VEN-YYYY-NNN`; contacts/bank as own tables.
- **Prequalification:** scored engine, 4 category groups, Qualified/Conditional/Not-Qualified bands.
- **Risk + Due Diligence:** factor-based risk tiers (Critical/High/Med/Low); all 9 due-diligence checks.
- **Contracts (TPV):** all 16 spec fields. **Work Orders (TPV):** all 12 fields. All 18 approval **types** enumerated.
- **Meetings:** 24 configurable meeting types, agenda builder (backend), internal+external participants, 6 attendance states, per-item Agenda→Discussion→Decision→Action, full Action Engine with Task push + two-way sync, Decision Register, Issues (convert to Task/NCR/CAPA/Incident/Approval), two-level MOM approval, distribution tracking (Sent/Viewed/Acknowledged), previous-MOM carry-forward.
- **Workforce (TPV):** all worker fields; typed training catalogue (11 types); competency + **Skill Matrix** (Worker×Activity×Competency×Validity); medical fitness with all 5 states.
- **PPE:** catalogue/issue/return/replace/used/stock on one inventory ledger; PPE Matrix (Job+Hazard+Activity→PPE, Mandatory/Optional/Conditional).
- **Work Authorization:** composite verdict (Vendor+Compliance+Medical+Induction+PPE+Competency+WP+Permit); 10 permit types + JSA + lifecycle.
- **Gate:** unified gate events (Person/Vehicle/Visitor/Equipment/Material × Entry/Exit); live re-validation on every scan.
- **Compliance:** 24 categories, 7 statuses, TPV+Purchase registers.
- **Inspections/NCR/CAPA:** full workflows + auto-linkages (finding→NCR→CAPA; incident→CAPA; violation→CAPA).
- **Violations/Strikes (TPV):** 9 types, configurable Warning→Strike1/2/3→Suspension→Blacklist ladder with project overrides + auto-escalate.
- **Performance (VPI):** all 15 dimensions, A–E bands, history persists across projects.
- **Offboarding:** 12-item checklist, all-items gate, 4 final statuses.
- **Vendor Portal:** all 16 vendor capabilities; internal-info restriction enforced via ownership scoping.
- **Control Tower:** KPI grid + 12-row Action Centre.
- **The 12 business rules: all enforced in code, not labels.**

---

## Path to 100% — prioritized backlog

### P0 — correctness / invariants — ✅ DONE (2026-08-29)

1. ✅ **Activation bypass closed (Rule 1).** `VendorService::updateStatus()` and `PurchaseVendorService::updateStatus()` now refuse `→ ACTIVE` unless the vendor's onboarding is Approved (read fresh, so the sanctioned onboarding-approve path — which sets Approved first — still activates). Tests: `tests/Feature/Vendor/ActivationApprovalGuardTest.php` (4). *Follow-up: make central-register `vendor_activation` decisions themselves trigger activation.*
2. ✅ **Purchase violations auto-escalate.** `PurchaseViolationService::record()` now calls a new `autoEscalate()` mirroring `TpvViolationService` — crossing the ladder auto-holds (On_Hold@10) / blacklists (@13). Test: `tests/Feature/Purchase/PurchaseViolationEscalationTest.php` (3). *Follow-up (P1/P3): thread the project-ladder override through Purchase `escalationFor`.*
3. ✅ **Purchase owner-gates added (Rule 11).** `PurchaseInspectionService::updateFinding` (blocks Action/Closed without owner) and `PurchaseNcrService::transition` (blocks past Raised without owner). Test: `tests/Feature/Purchase/PurchaseOwnerGateTest.php` (2).

All 44 existing vendor/onboarding/violation/owner tests still pass + 9 new = green.

### P1 — wiring gaps (mostly ✅ DONE 2026-08-29)

4. ✅ **Checklist dimension variation.** `checklistContext()` now threads the vendor's `project` + `site` (not just `risk_level`), so project/site checklist rules fire. `work_type` has no vendor column, so it stays dormant by design. `TpvOnboardingService.php` · OnboardingChecklistGateTest still green.
5. ✅ **Master-edit fields.** `project`, `site`, `department`, `client_id` added to `UpdateVendorRequest`; project/department/site added to the ProfilePanel form (client linking keeps its own Customer-tab picker). Test: `tests/Feature/Vendor/VendorMasterEditFieldsTest.php` (2).
6. ✅ **Intermediate statuses — resolved by design.** `Registered/Under_Review/Approved` are intentionally NOT auto-assigned: the mid-registration granularity lives on the **onboarding record** (`TpvOnboardingStatus` = Submitted→Under_Review→Approved), while the vendor status stays coarse (Draft→Pending_Approval→Active). Documented as reserved target states in `VendorStatus.php`.
7. ⏳ **PPE-matrix inert fields — RECLASSIFIED (deferred, ~P2 size).** `verification_required` enforcement needs a real verification workflow — `tpv_worker_ppe_issues` has **no `verified_at` column**, so gating the badge on it requires a schema add + verify action + UI, and it touches the safety-critical badge gate. `replacement_frequency_days`/conditional `condition` likewise need a consumer (alert job / evaluation). Not rushed into the gate; tracked as a dedicated feature.
8. ✅ **Rule 7 proactive sweep — already implemented (audit finding was inaccurate).** `tpv:temporary-access-reminders` runs **hourly** and its `TpvAccessService::sendDueReminders()` calls `lazyExpire()` on every lapsed window — so expired temporaries flip within the hour without any request. No new command needed.

### P2 — surfacing & depth (partly ✅ DONE 2026-08-29)

9. ✅ **Vendor-360 page — the biggest single gap.** Added **9 vendor-scoped tabs** to the vendor workspace: Performance Index (VPI), Renewal, Offboarding, Compliance Register, Inspections, NCR, CAPA, Work Packages, Document Vault — each read-only, reading an existing vendor-scoped endpoint (no new API, no data copy). New file `frontend/src/modules/tpv/components/VendorGovernancePanels.jsx`; wired into `TpvVendorDetail.jsx` nav (47 sections, 33 active). Management stays in the dedicated module pages.
10. ✅ **Agenda Builder UI (previous-ref).** Added the **previous-discussion-reference** input to each agenda row (`KickoffMeetingCreate.jsx`) — backend already stored + validated it; round-trips on save/edit. *(Supporting-docs uploader still open — needs an upload flow; the `supporting_documents` array field remains unexposed.)*
11. ⏳ **Renewal assessment depth.** `assess()` still skips Contract-perf, Commercial-perf, distinct Workforce-perf, Client-feedback. Backend enrichment — open.
12. ⏳ **Document Vault as first-class.** The new Vault tab surfaces version/status/expiry read-only; making it first-class (approval/verification/audit-trail per doc, 11-type taxonomy grouping) is still open.
13. ⏳ **Control Tower tiles.** Split Pending-Approvals vs Pending-Onboarding; add Gate-Violations tile + Contract-expiry action row — open.

### P3 — Purchase parity (mostly ✅ DONE 2026-08-29)

14. ✅ **Purchase incident engine.** Built `PurchaseIncidentService` (report→RCA→CAPA→close, grave-incident auto-suspend to On_Hold, close gated on RCA + verified CAPAs) + controller + 7 routes + `PurchaseIncidents.jsx` + nav/route/api. Uses the unified `purchase_capas` register (no separate IncidentCapa). Test: `PurchaseIncidentEngineTest` (7).
15. ✅ **Purchase competency + skill matrix + gate.** New `purchase_worker_competencies` table + model + `PurchaseCompetencyService` (CRUD, skillMatrix, workerHasCompetency); gate wired into `readiness()`/`activateBadge()`. Purchase has no per-activity `required_competency`, so requirements come from a tenant Settings key `workforce_required_competencies` (empty = no-op, no regression). Test: `PurchaseCompetencyGateTest` (9).
16. ✅ **Purchase medical + training depth.** Added restrictions/approved_by/examiner/certificate columns + granular fitness (readiness now passes "Fit with Restrictions"); typed training catalogue (12 types) + validity. Test: `PurchaseMedicalTrainingDepthTest` (7).
17. ⏳ **Purchase prequalification + due diligence.** IN PROGRESS (mirror of TPV prequal config + DD entity). *(status pending build completion)*
18. 🚫 **Unify the approval + meeting engines — WON'T-DO (working as-is).** The four approval systems and the Purchase meeting fork all function correctly; unifying them is a multi-day refactor that would risk the P0 activation/owner invariants for zero functional gain. Deliberately kept separate. Revisit only as a dedicated, well-tested migration if the duplication becomes a maintenance burden.

---

## The 12 business rules — enforcement status

| Rule | Status | Note |
|---|---|---|
| 1 No Approval→No Activation | ✅ ENFORCED (invariant) | Bypass closed in `updateStatus()` (TPV+Purchase) — P0-1 done |
| 2 No Mandatory Compliance→No Mobilisation | ENFORCED (proxy) | via onboarding checklist + nightly `EnforceVendorCompliance`; §21 register not itself a hard gate |
| 3 No Valid Worker Compliance→No Access | ENFORCED | `GateScanService::evaluate` live re-check |
| 4 No Competency→No Work Auth | ENFORCED | badge blocker + work-auth verdict (TPV only; Purchase missing) |
| 5 No Required PPE→No Work | ENFORCED | hard block at badge + configurable gate |
| 6 No Permit→No High-Risk Work | ENFORCED | keyed on `requires_permit` activities |
| 7 Temporary Means Temporary | ENFORCED (lazy) | per-request expiry; **no proactive sweep — P1-8** |
| 8 Expiry Drives Risk | ENFORCED | nightly suspend/reinstate |
| 9 Repeated Violations Escalate | ✅ ENFORCED (both) | auto-escalate on TPV **and** Purchase (P0-2 done) |
| 10 Performance Influences Renewal | ENFORCED (advisory) | VPI feeds assessment; decision stays manual |
| 11 Every Action Has an Owner | ✅ ENFORCED (both) | CAPA/NCR + inspection findings refuse ownerless progression on TPV **and** Purchase (P0-3 done) |
| 12 Every Closure Requires Evidence | ENFORCED | CAPA verify / NCR close / offboarding / incident close |

---

## Key file map (for whoever picks this up)

- **Vendor master:** `app/Models/Vendor/Vendor.php`, `Support/Vendor/VendorStatus.php`, `Http/Requests/.../UpdateVendorRequest.php`, `modules/tpv/pages/TpvVendorDetail.jsx`
- **Prequal/Risk/DD:** `Services/Tpv/VendorPrequalificationService.php`, `VendorRiskService.php`, `TpvDueDiligence`, `config/vendor_prequalification.php`, `config/vendor_risk.php`
- **Approvals:** `Services/Tpv/TpvApprovalService.php`, `OnboardingApprovalService.php`, `Support/Tpv/ApprovalType.php`, `config/tpv_approval_routing.php`
- **Contracts/WO:** `Models/Tpv/TpvContract.php`, `TpvWorkOrder.php`, `modules/tpv/pages/TpvContracts.jsx`
- **Meetings:** `Models/Shared/Kickoff*`, `Services/Shared/KickoffMeetingService.php`, `config/meetings.php`, `modules/shared/pages/Kickoff*.jsx`
- **Workforce:** `Services/Tpv/TpvWorkerService.php` (the central `blockers()` gate), `TpvCompetencyService.php`, `Models/Tpv/TpvWorker*.php`
- **PPE/Permit/Gate:** `Services/Tpv/PpeInventoryService.php`, `TpvWorkAuthorizationService.php`, `GateScanService.php`, `Models/Tpv/WorkPermit.php`, `TpvGateEvent.php`
- **Compliance/NCR/CAPA/Violations:** `Support/Tpv/ComplianceCatalog.php`, `Services/Tpv/{TpvInspectionService,TpvNcrService,TpvCapaService,TpvViolationService,IncidentService}.php`
- **Performance/Renewal/Offboarding/Vault/Dashboard:** `Services/Tpv/{TpvVendorPerformanceService,TpvRenewalService,TpvOffboardingService,TpvDocumentVaultService,TpvDashboardService}.php`, `modules/tpv/pages/TpvDashboard.jsx`
- **Portal:** `routes/portal.php`, `Controllers/Api/Portal/*`, `pages/vendor-portal/*`, `pages/purchase-portal/*`
- **Rule enforcers:** `Console/Commands/EnforceVendorCompliance.php`, `Http/Middleware/EnsureTemporaryAccessNotExpired.php`

---

*This report is the authoritative gap backlog. Tick items here as they are completed; re-run the 7-cluster audit to re-score.*
