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

- [ ] **§24 NCR entity** — Raised→Assigned→Response→Corrective-Action→Verification→Closed;
  vendor/project/requirement/finding/severity/evidence/responsible/due. (Meetings issue→NCR convert.)
- [ ] **§22 Inspections & Audits** — inspection-type catalogue + Plan→Inspect→Finding→Action→CAPA→Verify→Close.
- [~] **§25 CAPA generalisation** — decouple from incident-only; link audit/inspection/NCR/meeting/
  compliance-failure/repeated-violation; add immediate-correction, root-cause, effectiveness fields + **evidence files**.
- [~] **§26 Strikes & Violations** — configurable violation catalogue + Warning→Strike1→2→3→Suspension→
  Blacklist escalation (per project/client) + vendor `blacklist()` action. (Today: worker-level auto-terminate only.)

## Phase 7 — Governance polish

- [~] **§21 Compliance engine** — 14 categories + 7 statuses (Compliant/Partial/Non/Expiring/Expired/Waived/Under-Review) per vendor.
- [~] **§27 Vendor Performance (VPI)** — expand VRS from 3 → 13 dimensions, band A–E, cross-project history.
- [ ] **§28 Renewal & Extension** — pre-expiry workflow consuming performance/compliance/incidents/NCR/
  CAPA/strikes → Renew/Renew-with-Conditions/Extend/Requalify/Replace/Suspend/Exit. (Rule 10.)
- [~] **§29 Offboarding / Closure** — controlled exit **checklist** (contract/workforce/gate/ID/equipment/
  PPE/docs/open-actions/NCR-CAPA/financial/final-review) + Closed/Replaced outcomes.
- [~] **§30 Documents** — unified Document Vault (today scattered across 4 stores; CAPA evidence unstored).
- [~] **§31 Communications** — in-app feed + SMS/WhatsApp + expiry/incident/strike/CAPA/renewal notifications.
- [~] **§32 Vendor Portal** — add respond-to-NCR, submit-CAPA-evidence, view meetings/MOM, respond-to-actions.
- [~] **§33 Reports & Analytics** — trend/benchmark analytics + CSV/Excel export (today print-PDF only).

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
