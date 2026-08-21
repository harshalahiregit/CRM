# Sangoe TPV — Master Build Plan (doc → task tracker)

**Source of truth:** `docs/Sangoe TPV.docx` (42 sections). This file is the completeness contract:
every doc section maps to a task with a status box. Nothing ships as "done" until its box is ticked
**and** verified. Companion analysis: `docs/audit/SANGOE-TPV-GAP-REPORT.md`.

**Legend:** `[ ]` todo · `[~]` partial/in-progress · `[x]` done & verified · `[·]` already existed, verified.

---

## Ground rules (standing)

- **Parity (the "one major thing"):** Purchase Vendor and Third-Party Vendor have **separate dashboards +
  separate databases but identical functionality** ("same to same"). This is already the architecture
  (`vendors` vs `purchase_vendors`, decoupled). **Rule going forward:** every TPV feature built per this
  doc gets a Purchase equivalent on its own DB. Build TPV first as the reference, then mirror to Purchase.
- TPV is Harshal's module — changes are **additive**. Purchase is also Harshal's.
- Flow per slice: build end-to-end (visibility + notifications + email agree per role) → verify
  (rolled-back tinker / green vite build) → commit (`Co-Authored-By: Claude Opus 4.8`) →
  push `shivam/raise-ticket-modules` → merge `master`.
- Never commit `frontend/.env`, `docs/source-docs/`, `.docx`, or the `module-briefs/*` deletions.

---

## Phase 0 — Meetings engine (doc §9)  ✅ COMPLETE

Delivered as M1–M15 (see `tpv-meetings-engine` memory). Configurable meeting types, agenda builder,
structured MOM, Action Engine → real Tasks, Decision Register, Issues (+→Task/Incident), templates,
carry-forward, two-level MOM approval + distribution, calendar (M/W/D/Agenda), project/vendor rollups,
live vendor-status template, AI assist. Kickoff is now "Meetings → New → Type = Kickoff".

- [x] §9 Meetings / MOM / Actions / Decisions / Issues / Templates / Approval / AI-ready

---

## Phase 1 — Structural foundation (nav, dashboard, cleanup)

- [x] **§38/§39 Navigation** — flat 20-tab rail regrouped into the doc's clusters
  (Dashboard · Vendors · Mobilisation · Workforce · Work Control · Compliance · Performance ·
  Intelligence). Added an additive two-level `groups` prop to shared `ModuleShell` (cluster row +
  active-cluster sub-row); Purchase/Workforce/Portal keep the flat `items` path untouched. Kickoff is
  now Meetings under Mobilisation, not a top tab. Ecosystem cluster appears once Settings lands (Phase 3);
  clusters list only pages that exist and grow as later phases add pages.
- [~] **§4/§37 Dashboard / Control Tower** —
  - [x] Route the real dashboard: "Dashboard" now renders `TpvDashboard` (HSSE/workforce roll-up)
    instead of the vendor list; added a dedicated **Vendor Master** tab (`/app/tpv/vendors`).
  - [x] Full Control Tower shipped: executive KPI band (vendors by status/risk/temporary, workforce +
    on-site, training/medical %, avg performance, open actions/CAPAs/permits/strikes), **Action Centre**
    (approvals/docs/training/medical/workforce/CAPA/MOM/permit/renewal pending+overdue, zero-rows hidden),
    and **Risk breakdown** (Critical/High/Med/Low/Unclassified). Extends `/tpv/dashboard`; NCR/contract
    KPIs render 0 until those phases land.
- [x] **DB hygiene** — investigated: the `engagements` json column is **NOT dead**. It marks which
  modules a vendor participates in and is load-bearing — `Vendor::hasEngagement()`/`scopeForEngagement()`,
  the vendor create/update validation (`in:purchase,tpv`), the Accounts party directory, and it's set to
  `['tpv']` on TPV onboarding. **No change made** (dropping it would break vendor creation + scoping).
  The DB separation is already correct as-is.

## Phase 2 — Qualification front-end (the missing left edge)

- [~] **§6 Prequalification** —
  - [x] Top-level **Prequalification** queue page (`/app/tpv/prequalification`, in Vendors cluster):
    every vendor's status/score, worst-first, → per-vendor scored questionnaire (existing panel).
  - [ ] (scoring engine + per-vendor questionnaire already existed; only the module-level surface was missing.)
- [~] **§7 Risk & Due Diligence** —
  - [x] Top-level **Risk & Due-Diligence** queue page (`/app/tpv/risk`, in Vendors cluster): tier/score,
    worst-first, → per-vendor risk assessment (existing panel).
  - [ ] **Due-diligence checklist entity** (company/document/licence/insurance/background/reference verification).
  - [ ] Wire **risk tier → onboarding depth / monitoring** (doc: "risk determines depth").
- [·] **§5 Vendor Risk Classification** — `risk_level`/`risk_score` columns present; now surfaced on the
  Control Tower risk breakdown + the Risk queue page + drill-downs.

## Phase 3 — Vendor Master completion + Settings

- [~] **§5 Vendor Master fields** —
  - [x] Added trade_name, subcategory, vendor_class (Contractor/Subcontractor/Consultant/Service-Provider),
    parent_company, CIN, Udyam, site_address, emergency_contact, internal_sponsor, contract_owner —
    additive migration + model fillable + both FormRequests + the vendor-workspace Profile form.
  - [ ] Extend status enum (Invited/Registered/Under-Review/Expired/Closed vs current 9) — deferred; the
    current 9-value enum covers the live lifecycle, extra states land with the Approvals engine (Phase 4).
- [ ] **§34 Settings / Config module** — admin UI for the ~19 masters: vendor types, categories, risk
  levels, meeting types (done), meeting/onboarding templates, approval workflows, compliance templates,
  document types, training types, competency requirements, PPE catalogue, PPE matrix, permit types,
  violation types, strike rules, performance scoring, notification rules, expiry rules, project rules.

## Phase 4 — Commercial spine + Work Packages + central Approvals

- [x] **§8 Contracts & Work Orders** — TPV-owned `tpv_contracts` (type/scope/dates/value/SLA/KPI/
  penalties/insurance/HSE/compliance clauses/renewal, CT-YYYY-### ref) + `tpv_work_orders`
  (WO-YYYY-### ref, contract link, work-package/scope/location/manpower/equipment/terms). Models +
  `TpvContractService` (CRUD) + `TpvContractController` + routes + a two-tab **Contracts & Work Orders**
  page with create/edit/delete. Distinct from Purchase's contracts. Verified via rolled-back tinker
  (refs + relations + counts) + green build. TODO later: feed contract/WO expiry into Action Centre + performance.
- [x] **§13 Work Packages** — `tpv_work_packages` (WP-YYYY-### ref, vendor/project/contract link,
  scope/location/dates/status) + `tpv_activities` (name, `required_competency` — the Skill-Matrix hook,
  status, sort) + additive `tpv_workers.work_package_id` for deployment. Models + `TpvWorkPackageService`
  + controller + routes + a **Work Packages** page (list + create/edit + expandable per-package activity
  management + deployed-workers view) in the Mobilisation cluster. Verified via rolled-back tinker + green
  build. TODO later: assign workers to a package from the worker wizard; enforce activity competency (Phase 5).
- [~] **§12 Approvals engine** —
  - [x] Central **Approval Register** built ADDITIVELY (zero risk to the working onboarding chain):
    new `tpv_approvals` table (APR-YYYY-### ref, polymorphic subject, 18 `ApprovalType` kinds, priority,
    Pending→Approved/Rejected/Cancelled with re-decide guard + admin-gated decisions), `TpvApprovalService`
    (`raise()`/`decide()`/`list()` — other services can call `raise()` to route an action), controller +
    routes (`/tpv/approval-requests`), and an **Approval Register** page (raise + admin approve/reject/cancel)
    under Mobilisation. Onboarding approvals renamed to "Onboarding Approvals" in nav, untouched otherwise.
  - [ ] Configurable routing by risk/project/value/work-type/site/dept (deferred — needs the Settings module, Phase 3).
  - [ ] Wire existing flows (contract/WO/suspension/renewal/exception) to auto-raise into the register.

## Phase 5 — Control of Work hardening (business rules 4/5/6)

- [x] **§15 Competency & Training + Skill Matrix** — `tpv_worker_competencies` (name/category
  [Qualification/Trade-Cert/Licence/Certification/Skill]/authority/skill-level/validity → Valid/Expiring/
  Expired status) + `tpv_worker_trainings` (typed catalogue: Site/HSE-Induction/Toolbox/Fire/Height/
  Electrical/Confined-Space/Lifting/Equipment/Emergency, provider/score/validity). `TpvCompetencyService`
  with `workerHasCompetency()` + `skillMatrix()` (Worker×Activity×Competency×Validity per work package,
  the Rule-4 check) + controller + routes. Frontend: **Competency & Training** page (worker roster with
  counts + expiring flag, expandable per-worker competency & training management) in the Workforce cluster.
  Verified via rolled-back tinker (status derivation, workerHasCompetency electrical=yes/rigging=no, roster
  counts) + green build. TODO: enforce the matrix at work-authorization (below) + surface the matrix UI on Work Packages.
- [x] **§19 Work Authorization (unified)** — `TpvWorkAuthorizationService` composes ONE verdict over
  Vendor-Approval + Compliance + Medical + Induction + PPE + Competency (the §15 matrix, Rule 4) + Permit +
  Work-Package. **Read-only aggregator** — reuses the same signals the badge gate uses; changes NO
  enforcement path. Controller + routes + a **Work Authorization** page (roster with authorized/blocked +
  expandable per-check breakdown, required vs advisory) as the first Work-Control item. Verified via tinker
  (full check breakdown, roster) + green build.
- [ ] **Rule 5 PPE-at-gate** — enforce mandatory PPE at gate scan (today only at badge issue). Deferred:
  this MODIFIES GateScanService enforcement — its own slice with gate-harness verification.
- [ ] Permit types add isolation/shutdown/critical; link Permit↔Worker↔Gate (deferred with PPE-at-gate).

## Phase 6 — Corrective-action completeness

- [x] **§24 NCR entity** — `tpv_ncrs` (NCR-YYYY-### ref, vendor/project link, polymorphic source for the
  Meetings issue→NCR target, requirement/finding/severity/responsible/due, is_overdue accessor) with the
  ordered lifecycle Raised→Assigned→Response→Corrective_Action→Verification→Closed (close-guarded: needs a
  corrective action; stamps verifier + closed_at). `TpvNcrService` + controller + routes + an **NCR** page
  (list, raise/edit modal, one-click status advance, overdue flag) in the Compliance cluster. Control Tower
  `open.ncrs` + Action Centre `ncr_overdue` now count the real table. Verified via rolled-back tinker
  (lifecycle + close-guard) + green build.
- [x] **§22 Inspections & Audits** — `tpv_inspections` (INS-YYYY-### ref, InspectionType catalogue
  [pre-mobilisation/HSE/site/PPE/workforce/equipment/compliance/behavioural/housekeeping/environmental/
  vendor-audit], vendor/project/WP link, score, Planned→In_Progress→Completed→Closed) + `tpv_inspection_findings`
  (category/severity/status, corrective action, **escalate-to-NCR** which creates a real NCR + links back).
  `TpvInspectionService` (injects TpvNcrService) + controller + routes + an **Inspections** page (list +
  create/edit + expandable per-inspection finding management + one-click "Raise NCR") in Work Control.
  Verified via rolled-back tinker (inspection, finding, finding→NCR NCR-2026-001, double-escalate guard,
  counts) + green build. Closes the Plan→Inspect→Finding→Action→NCR chain.
- [x] **§25 CAPA generalisation** — a NEW generalised register added ADDITIVELY (`incident_capas` stays the
  incident-close gate, untouched): `tpv_capas` (CAPA-YYYY-### ref, `CapaSource` catalogue — kinds
  ncr/inspection/audit/meeting/violation/renewal/incident/manual with a class map, types
  Corrective/Preventive, priorities, Open→In_Progress→Done→Verified). Polymorphic source pointer set from
  kind+id; `raiseFrom(kind,id,…)` convenience for other services to call later. **Rule 12**: `transition`
  refuses Verified without `evidence_path`. Register page = stats strip + source/status filters + evidence-gated
  advance + full CRUD modal (root-cause/action/evidence). Wired under Compliance → CAPA.
  Verified via rolled-back tinker (manual CAPA-2026-001 overdue, raiseFrom NCR CAPA-2026-002 linked, evidence
  gate blocks then passes, stats, kind/overdue filters) + green build.
  *Follow-up:* wire existing NCR/inspection/violation services to auto-`raiseFrom`; surface generalised CAPA on
  the exec dashboard (today the KPI still counts incident_capas).
- [x] **§26 Strikes & Violations** — vendor-level violation engine added ADDITIVELY (the worker-level
  auto-terminate strike engine is untouched): `tpv_vendor_violations` (VIO-YYYY-### ref, `ViolationType`
  catalogue [PPE/unauthorized-worker/expired-doc/unsafe-work/gate/security/environmental/repeated-
  non-compliance/training], severity→points, polymorphic source, Open/Closed) + a points-based
  escalation ladder Warning→Strike-1/2/3→Suspension→Blacklist (`ViolationType::levelFor`).
  `TpvViolationService` (record/list/escalationFor/escalations + `enforce()` = suspend/**blacklist** via the
  shared VendorService::suspend/updateStatus) + controller (enforcement admin-gated) + routes. Frontend:
  **Violations & Strikes** page (per-vendor escalation ladder cards with suspend/blacklist actions at
  threshold + violations list + record modal) in the Compliance cluster. Verified via rolled-back tinker
  (6pts→Strike 2, 14pts→Blacklist, blacklist→vendor Blacklisted) + green build. Configurable thresholds later (Settings).

## Phase 7 — Governance polish

- [x] **§21 Compliance engine** — `tpv_vendor_compliance` (per-vendor per-category register, one row per
  vendor+category) over the 14 categories (legal/labour/licences/statutory/contractual/HSE/training/medical/
  risk-assessment/method-statement/PPE/environment/quality/security) × 7 statuses (Compliant/Partially/
  Non-Compliant/Expiring/Expired/Waived/Under-Review). `effective_status` accessor makes **expiry drive
  status (Rule 8)**. `TpvComplianceService` (vendorMatrix — always all 14, upsert, roster with compliance %).
  Controller + routes. Frontend: **Compliance Register** page (vendor roster with compliance-% bar +
  problem/expiring counts, expandable 14-category matrix with inline status + validity editors) in the
  Compliance cluster, additive to the evidence locker. Verified via rolled-back tinker (expired→Expired
  override, 14-cat matrix, roster %) + green build.
- [~] **§27 Vendor Performance (VPI)** — expand VRS from 3 → 13 dimensions, band A–E, cross-project history.
- [x] **§28 Renewal & Extension** — `tpv_renewals` (REN-YYYY-### ref, vendor/contract link, due date,
  JSON assessment snapshot, Pending→Assessed→Decided) with an **assessment that pulls the VRS scorecard +
  open NCRs/CAPAs/active-strikes/violation-level** (Rule 10: performance influences renewal).
  `TpvRenewalService` (assess/initiate/reassess/decide) — decide applies Extend/Renew (pushes a linked
  contract's end date → Renewed) and Suspend (via VendorService); outcomes Renew/Renew-with-Conditions/
  Extend/Requalify/Replace/Suspend/Exit. Controller (decisions admin-gated) + routes. Frontend: **Renewal**
  page (list with VRS + open-item snapshot, Initiate modal with live assessment preview, Decide modal) in
  the Performance cluster. Verified via rolled-back tinker (assess VRS=77/B, initiate REN-2026-001, decide) + green build.
- [x] **§29 Offboarding / Closure** — `tpv_offboardings` (OFF-YYYY-### ref, 12-item exit checklist
  [contract/workforce/gate/ID/PPE/equipment/docs/open-actions/NCR-CAPA/financial/asset/final-review],
  progress accessor, lessons learned, In_Progress→Completed). `TpvOffboardingService`: initiate (one open
  per vendor), updateChecklist, and complete — **gated on all items done**, then applies the final status
  (Closed/Replaced → VendorService::offboard which terminates workers + revokes badges + locks login;
  Suspended → suspend; Blacklisted → updateStatus). Controller (completion admin-gated) + routes. Frontend:
  **Offboarding & Closure** page (list with progress bar, Start modal, expandable interactive checklist +
  Complete with final-status + lessons) in the Performance cluster. Verified via rolled-back tinker
  (12-item checklist, complete-guard, complete→vendor Offboarded) + green build.
- [x] **§30 Documents** — unified Document Vault added as a read-only aggregator (`TpvDocumentVaultService`)
  over all four stores: statutory `vendor_documents`, the `compliance_evidence` locker, and CAPA/NCR
  closure evidence. Each source is normalised to one row shape with a computed `expiry_state`
  (valid/expiring≤30d/expired/none); roster supports source/vendor/expiry/q filters and most-urgent-first
  sort; per-vendor vault groups by source; summary gives by-source + expiry buckets + attention list.
  Read-only and additive — no store's write path touched. Vault page = clickable expiry stat cards +
  search/source/expiry filters + unified table with open-file links, under Intelligence → Document Vault.
  Verified via rolled-back tinker (4-source seed, 25-doc summary, expiry buckets 1 expired/1 expiring,
  uploader-name resolve, source/expiry filters, vendor vault grouping) + green build.
- [~] **§31 Communications** — in-app feed + SMS/WhatsApp + expiry/incident/strike/CAPA/renewal notifications.
- [~] **§32 Vendor Portal** — add respond-to-NCR, submit-CAPA-evidence, view meetings/MOM, respond-to-actions.
- [x] **§33 Reports & Analytics** — a new Analytics hub added ADDITIVELY (the DPR/WPR/MCR print report at
  `/reports` is untouched): `TpvAnalyticsService` computes cross-module overview (portfolio by status,
  governance open/overdue NCR/CAPA/violation/inspection, compliance %), a 6-month month-over-month trend
  series (NCRs/CAPAs/violations/inspections), a per-vendor benchmark leaderboard (compliance %, open NCRs/CAPAs,
  violation points — worst-first), and CSV export of 6 datasets (vendors/ncrs/capas/violations/inspections/
  benchmark) via an RFC-4180 writer + attachment response. Read-only. Analytics page = KPI row + stacked trend
  bars + CSV export bar + benchmark table, under Intelligence → Analytics. Verified via rolled-back tinker
  (overview, 6-month trends Mar–Aug, 8-vendor benchmark, CSV header/rows, multi-dataset export) + green build.

## Cross-cutting — the 12 Critical Business Rules (§36)

- [·] R1 No Approval No Activation · [·] R2 No Compliance No Mobilisation · [·] R3 No Worker-Compliance No Access ·
  [·] R7 Temporary means Temporary · [·] R9 Repeated Violations Escalate (worker)
- [~] R4 No Competency No Auth (induction only) · [~] R5 No PPE No Work (badge only, not gate) ·
  [~] R6 No Permit No High-Risk Work (not gate-linked) · [~] R8 Expiry Drives Risk (not into VRS) ·
  [~] R11 Every Action Has Owner · [~] R12 Closure Requires Evidence
- [ ] R10 Performance Influences Renewal (no renewal workflow yet)

## Parity backlog (mirror each shipped TPV feature onto Purchase, own DB)

- [ ] Track per-feature as TPV slices land. Purchase already has: separate vendor table, workforce stack,
  onboarding→activation, Overview/Customer tabs (parity pass done earlier). Everything Phase 1–7 needs a Purchase mirror.
