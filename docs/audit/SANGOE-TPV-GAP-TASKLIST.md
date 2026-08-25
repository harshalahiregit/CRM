# Sangoe TPV — Gap Task List (doc vs. real implementation)

**Source of truth:** `docs/Sangoe TPV.docx`, matched line-by-line against the code on **2026-08-25**
(fresh read of models/migrations/services/routes/React pages — not the stale Aug-22 audit).

**How to use this file:** every item below is either **partial** `[P]` or **missing** `[M]`.
Tick `- [x]` ONLY when the item is genuinely implemented AND verified against the doc + real code.
Fully-built sections (§8 Contracts/WO, §9 Meetings core, §22 Inspections, §24 NCR, §29 Offboarding,
§35 relations, §38/39 nav) are **not** listed here — they're already done.

**Legend:** `[P]` partial · `[M]` missing · `(§n)` doc section · evidence in _italics_.

**Done so far (this session):** the §34 tenant-settings engine — 6 governance knobs moved from
hardcoded → tenant-editable UI (ticked below). Rule 1 (No Approval → No Activation) was already
fixed earlier (`TpvOnboardingService::approve` routes through `VendorService`).

---

## ★ Priority tier — the doc's core thesis ("enforce, don't display", §36)

- [x] **Rule 4 — enforce competency at the point of work** (§15/§19/§36). DONE 2026-08-25: badge issuance (`TpvWorkerService::blockers`, the Gate-Pass stage) now hard-blocks a worker who lacks any competency named by their work package's activities; guarded by `CompetencyGateTest` (5 tests). _Enforced at the Gate-Pass/badge point (correct per doc); the entry-scan gate itself is still not competency-gated — optional follow-up._
- [x] **Rule 6 — enforce permit for high-risk work** (§19/§36). DONE 2026-08-25: activities carry a `requires_permit` flag (+ optional pinned `permit_type`); `TpvWorkerService::blockers()` refuses a badge when the worker's package has a high-risk activity with no valid Approved/Active, non-expired vendor permit of the matching type; mirrored as a REQUIRED check in the authorization verdict; editor UI on the Work Packages page. Guarded by `PermitGateTest` (6 tests).
- [x] **Rule 11 — action owner required: CAPA + NCR** (§36). DONE 2026-08-25: `TpvCapaService::transition` refuses to move a CAPA past Open without `assigned_to`; `TpvNcrService::transition` refuses to move an NCR past Raised without `responsible_by` (this also means auto-raised CAPAs inherit an owner). Guarded by `ActionOwnerRuleTest` (5 tests).
- [ ] **Rule 11 — action owner required: MOM actions + inspection findings** `[P]` (§36). Remaining: owner-gate on MOM action progression (shared Meetings module — deferred to avoid Purchase-side regression) and on direct inspection-finding closure. Note: escalated inspection findings already pass through the now-gated NCR.
- [x] **Rule 9 — auto-enforce vendor violation escalation** (§26/§36). DONE 2026-08-25: `TpvViolationService::record` now auto-applies Suspension (≥10 pts) / Blacklist (≥13 pts) via the shared `VendorService` the moment a violation crosses a ladder threshold (best-effort, never downgrades a blacklist, never re-applies a held state). Worker strikes already auto-terminate. Guarded by `ViolationEscalationTest` (3 tests). _Ladder thresholds still hardcoded — making them tenant-configurable remains under §26._
- [x] **PPE Matrix → Job + Hazard + Activity model** (§18). DONE 2026-08-25: matrix rows now carry `hazard`, `activity`, `ppe_class` (Mandatory/Optional/Conditional), `condition`, `replacement_frequency_days`, and `verification_required` alongside the existing scope (role/skill). Only **Mandatory** rules gate the badge (`PpeInventoryService::missingMandatoryFor` filters on `isMandatory()`); Optional/Conditional are advisory and surface in the compliance view with their class + hazard. Editor UI (add-dialog + rule display) and controller validation updated. Guarded by `PpeMatrixClassTest` (4 tests). _Rows now capture Job+Hazard+Activity as rule context; worker→rule **matching** still keys on the scope column (designation/skill) — hazard/activity are descriptive attributes of the rule, not yet additional match dimensions. Full activity-based matching remains a follow-up._

---

## §3 Navigation
- [ ] Medical Fitness dedicated nav item `[M]` — currently only inside the worker wizard.
- [ ] Vendor Portal / "Ecosystem" entry in TPV nav `[P]` — portal is a separate app area (may be by design).

## §4 Dashboard (Control Tower)
- [x] PPE Compliance % KPI — Control Tower `compliance.ppe_pct` (fully-equipped ÷ configured workers) + exec tile [2026-08-25].
- [x] Overall Compliance % KPI — Control Tower `compliance.overall_pct` (mean of the §21 per-vendor register scores) + exec tile [2026-08-25]. _Guarded by `DashboardComplianceKpiTest` (2); null-safe on empty tenants._
- [ ] Pending Approvals headline tile `[P]` (surfaced via `awaiting_review` KPI + Action Centre `approvals` row; no dedicated headline tile yet).
- [ ] Pending Onboarding headline tile `[P]` (onboarding funnel exists; no headline tile).
- [ ] Gate Violations count KPI `[P]` (`denied_today` KPI exists; no cumulative gate-violations tile).
- [ ] Risk drill-down by Vendor/Project/Site/Department/Work Package/Risk Category `[M]` (only risk_level tier counts today).
- [x] Action Centre row: PPE pending — `ppe_pending` row (workers missing mandatory PPE → `/app/tpv/ppe`) [2026-08-25].
- [ ] Action Centre row: MOM pending `[M]` (only `mom_actions_overdue` today, not general pending).
- [ ] Action Centre row: Contract expiry `[M]` (no contracts model wired).
- [ ] Action Centre row: general Vendor renewal due `[P]` (only temp-access expiry now).

## §5 Vendor Master
- [ ] Vendor Status vocabulary — add **Invited, Registered, Under Review, Approved, Expired**; reconcile Closed vs `Offboarded` `[M]` (_VendorStatus.php_).
- [ ] Vendor profile field: **Project** `[M]`.
- [ ] Vendor profile field: **Department** `[M]`.
- [ ] Vendor profile field: **Client** on the profile `[P]` (reverse relation only via `clients.vendor_id`).
- [ ] `vendor_class` enum constraint `[P]` (free string today).
- [ ] Risk factor: Regulatory requirements `[M]`.
- [ ] Risk factor: Previous incidents `[M]`.
- [ ] Risk factor: Compliance history `[M]`.
- [ ] Risk factor: Vendor performance `[M]` (deliberately kept separate as VRS — confirm intent).

## §6 Prequalification (taxonomy depth)
- [ ] Company: regional capability `[M]`.
- [ ] Company: manpower capability `[M]`.
- [ ] HSE: organization `[M]`.
- [ ] HSE: safety statistics `[M]`.
- [ ] HSE: training system `[M]`.
- [ ] HSE: risk assessment system `[M]`.
- [ ] HSE: emergency preparedness `[M]`.
- [ ] Compliance: licences (discrete item) `[M]`.
- [ ] Commercial: commercial capability `[M]`.
- [ ] Commercial: contract history `[M]`.
- [ ] Promote proxied items to explicit: legal existence, experience, HSE policy, labour compliance, certifications, previous clients `[P]`.

## §7 Risk & Due Diligence
- [ ] **Due-Diligence checklist entity** `[M]` — company/document/licence/insurance verification, background checks, reference checks. Page renders risk-scoring only.
- [ ] Risk dimension: Legal `[M]`.
- [ ] Risk dimension: Cyber/Data `[M]`.
- [ ] Risk dimension: Reputational `[M]`.
- [ ] Risk dimension: Environmental `[M]`.
- [ ] Risk tier → onboarding/approval depth gating `[P]` (tier stored/surfaced but doesn't drive depth).

## §9 Meetings (mostly done — remaining polish)
- [ ] Global "Decisions" register view/nav `[P]` (per-meeting records exist).
- [ ] Global "Issues Raised" register view/nav `[P]`.
- [ ] Agenda item: supporting-documents field `[M]`.
- [ ] Agenda item: previous-discussion-reference field `[P]`.
- [ ] Distinct Organizer field `[P]` (implicit `created_by` today).
- [ ] Per-agenda discussion field + free-form MOM attachments `[P]`.

## §10 Onboarding
- [ ] Configurable onboarding checklist by Risk Level `[M]`.
- [ ] …by Project `[M]`.
- [ ] …by Site `[M]`.
- [ ] …by Work Type `[M]`.
- [ ] General configurable checklist (beyond documents) that gates activation `[P]` (only per-vendor-type doc set today).

## §11 Temporary Vendors
- [ ] Capture Purpose `[M]`.
- [ ] Capture Sponsor `[M]`.
- [ ] Capture Project `[M]`.
- [ ] Capture Scope `[M]`.
- [ ] Capture Workforce `[M]`.
- [ ] Capture Risk level `[M]`.
- [ ] Capture Required documents `[M]`.
- [ ] Route extension through the approval engine `[P]` (manual admin action today).
- [ ] Raise an approval request on temp-vendor creation `[M]` (`ApprovalType::TEMPORARY_VENDOR` exists, unused).

## §12 Approvals — dimension-based routing
- [ ] Configurable routing by Risk `[M]`.
- [ ] …Project `[M]`.
- [ ] …Value `[M]`.
- [ ] …Work type `[M]`.
- [ ] …Workforce size `[M]`.
- [ ] …Site `[M]`.
- [ ] …Department `[M]`.
- (17 approval **types** already present ✅ — not listed.)

## §13 Work Packages
- [ ] Worker → Activity link (`activity_id` on workers + `TpvActivity::workers()`) `[M]`.
- [x] Wire `work_package_id` into the worker create/update service. DONE 2026-08-25: added to `$fillable` + `Store/UpdateTpvWorkerRequest`, set via service with `assertWorkPackage` (same-vendor/tenant scoping), `TpvWorker::workPackage()` relation.

## §14 Workforce
- [ ] Worker field: Project `[M]` — deferred: crosses into the Projects module; needs a cross-module decision (kept out of this slice).
- [x] Worker field: Experience — `experience_years` (decimal) column + wizard field + validation [2026-08-25].
- [x] Worker field: Joining date — `joining_date` column + wizard field + validation [2026-08-25].
- [x] Worker field: Exit date — `exit_date` column + wizard field + `after_or_equal:joining_date` validation [2026-08-25]. _Guarded by `WorkerEmploymentFieldsTest` (2)._
- [x] Work Package field on the worker. DONE 2026-08-25: assignable in the worker wizard (Step 1) via a vendor-scoped Work Package selector; persisted + gates the badge.
- [ ] Explicit Trade field `[P]` (proxied by skill_category + certs).
- [ ] Single training_status `[P]` (represented via induction flag + training records).
- [ ] Named lifecycle states: Nomination, Verification, Training, Gate Pass, Exit `[P]` (collapsed into 4 statuses + wizard flags).

## §15 Competency & Training
- [ ] Explicit "Job-specific training" type `[P]` (only catch-all `Other`).
- [ ] Competency: Experience field `[M]`.
- [ ] Skill Matrix grid on the Competency page itself `[P]` (currently only on Work Packages page).
- [ ] (Rule 4 gate enforcement — see ★ priority tier.)

## §16 Medical Fitness
- [ ] "Pending" fitness status `[M]`.
- [ ] Store "Expired" as a real status `[P]` (derived from expiry today).
- [ ] Distinct approved-by / sign-off (vs `recorded_by`) `[P]`.
- [ ] Fitness certificate + general supporting-document upload `[P]` (only a signature path today).

## §17 PPE
- [ ] Project-level PPE scope `[M]`.
- [ ] Atomic Replacement action + "Replaced" status `[M]`.
- [ ] "Used" status `[M]`.
- [ ] Vendor-level PPE stock/allocation entity `[P]` (derived from workers today).

## §18 PPE Matrix — (see ★ priority tier for the core rebuild)
- [x] Mandatory classifier made explicit — `ppe_class` defaults to `mandatory`; `isMandatory()` is the single gate predicate [2026-08-25].
- [x] Optional PPE class — advisory, never blocks the badge; verified by `PpeMatrixClassTest::test_optional_requirement_does_not_block` [2026-08-25].
- [x] Conditional PPE class — advisory + free-text `condition` note; verified by `test_conditional_requirement_does_not_block` [2026-08-25].
- [x] Replacement frequency — `replacement_frequency_days` captured per rule + surfaced in the compliance view [2026-08-25].
- [x] Verification requirement — `verification_required` boolean captured per rule + surfaced in the compliance view [2026-08-25].

## §19 Permits & Work Authorization
- [x] Permit type: Isolation — added to `WorkPermit::TYPES` [2026-08-25].
- [x] Permit type: Shutdown — added to `WorkPermit::TYPES` [2026-08-25].
- [x] Permit type: Critical Work — added as `Critical_Work` to `WorkPermit::TYPES` [2026-08-25].
- [x] Rename/add "Other" (was "General") — `Other` now the offered catch-all; retired `General` kept in `WorkPermit::acceptedTypes()` so historical rows still validate. Flows through PermitController + activity permit_type validation + both frontend pickers (TpvPermits, TpvWorkPackages). Guarded by `PermitTypeVocabularyTest` (4 tests) [2026-08-25].
- [x] (Rule 6 gate enforcement — done, see ★ priority tier.)

## §20 Gate Log / Access
- [ ] Equipment Entry/Exit events `[M]`.
- [ ] Material Entry/Exit events `[M]`.
- [ ] Unify Vehicle/Visitor into the gate-events model (currently parallel registers) `[P]`.
- [ ] Live-view filter: Project `[M]`.
- [ ] Live-view filter: Work Package `[M]`.
- [ ] Live-view filter: Location `[M]`.
- [ ] Roster server-side vendor filter `[P]` (client-side only today).

## §21 Compliance — categories (now 24; all added to `ComplianceCatalog::CATEGORIES`, flow to matrix + roster % + register API) [2026-08-25]
- [x] Waste — `Waste`.
- [x] Chemicals — `Chemicals`.
- [x] Pollution — `Pollution`.
- [x] Environmental requirements (distinct from generic Environment) — `Environmental_Requirements`.
- [x] Certifications — `Certifications`.
- [x] Inspection — `Inspection`.
- [x] QA/QC (distinct from generic Quality) — `QA_QC`.
- [x] Identification — `Identification`.
- [x] Background verification — `Background_Verification`.
- [x] Access — `Access`. _Guarded by `ComplianceCategoryVocabularyTest` (3). Note: the compliance % denominator now counts all categories, so vendors that haven't recorded the new ones will show a lower % until filled — correct per the doc._

## §23 Incidents
- [x] Type: First Aid — `First_Aid` added to `HsseIncident::TYPES` [2026-08-25].
- [x] Type: Medical Treatment — `Medical_Treatment` [2026-08-25].
- [x] Type: LTI — `LTI` [2026-08-25].
- [x] Type: Security — `Security` [2026-08-25].
- [x] Type: Unsafe Act — `Unsafe_Act` [2026-08-25].
- [x] Type: Unsafe Condition — `Unsafe_Condition` [2026-08-25]. _All flow through IncidentController validation + the TpvIncidents type picker; original event types retained. Guarded by `IncidentTypeVocabularyTest` (2)._
- [ ] Field: Project `[M]`.
- [ ] Field: Work Package `[M]`.
- [ ] Field: Activity `[M]`.

## §25 CAPA
- [x] "Immediate correction" field — `immediate_correction` column + form field (containment) [2026-08-25].
- [x] Separate Preventive-action field — `preventive_action` added; `action` is now the corrective action (relabelled in the form) [2026-08-25].
- [x] Dedicated Problem-statement field — `problem_statement` column + form field; `title` stays the short summary [2026-08-25].
- [x] "Compliance failure" as a source kind — `compliance_failure` added to `CapaSource::KINDS` (label "Compliance failure"), flows to the filter + modal [2026-08-25]. _All guarded by `CapaFieldsTest` (2); Rule 11 owner-gate still green._
- [ ] Reconcile the two CAPA registers (`TpvCapa` vs `IncidentCapa`) `[P]`.

## §26 Strikes & Violations
- [x] Vendor violation ladder configurable (tenant/settings) — new `violation_ladder` settings group (severity points + threshold→level steps) edited via a Settings tab; `TpvViolationService`, the `TpvVendorViolation` points hook, and `TpvRenewalService` all read the tenant ladder through `TpvSettings`. Because Rule 9 auto-suspend/blacklist is driven by the ladder levels, those thresholds are now configurable too. Guarded by `ViolationLadderConfigTest` (3); existing `ViolationEscalationTest` still green [2026-08-25].
- [ ] Per-project / per-client rule config `[M]` (settings are tenant-level only).

## §27 Vendor Performance (VPI)
- [ ] Dimension: Productivity `[M]`.
- [ ] Dimension: Timeliness `[M]`.
- [ ] Dimension: Training `[M]`.
- [ ] Dimension: Environmental (standalone) `[M]`.
- [ ] Dimension: Security (standalone) `[M]`.
- [ ] Dimension: Incident (standalone) `[M]`.
- [ ] Dimension: Meeting action closure `[M]`.
- [ ] Persist performance history/snapshots across projects `[M]` (live-computed only today).
- [ ] Band C label "Watch" `[P]` (currently "Fair").

## §28 Renewal & Extension (assessment inputs)
- [x] Input: Compliance (from §21 register) — `assessment.compliance` (score + problem/expiring counts) via new `TpvComplianceService::scoreFor`; shown on the renewal modal [2026-08-25].
- [ ] Input: Contract performance `[M]` — no performance metric/data source yet (TpvContract exists but has no scored performance); left rather than showing an empty placeholder.
- [ ] Input: Commercial performance `[M]` — no data source.
- [ ] Input: Workforce performance `[M]` — proxied by VRS/strikes today; no standalone metric.
- [ ] Input: Client feedback `[M]` — no data source.
- [x] Read the §25 CAPA register (not `IncidentCapa`) — `assessment.open_tpv_capas` counts open `TpvCapa` for the vendor, alongside the existing incident-CAPA count [2026-08-25]. _Guarded by `RenewalAssessmentInputsTest` (1)._

## §30 Documents (Vault)
- [ ] Surface worker documents in the vault `[P]`.
- [ ] Surface competency/training certificates in the vault `[P]`.
- [ ] Per-document renewal workflow object `[P]`.
- [ ] Distinct verify-vs-approve step `[P]`.

## §31 Communications — missing/partial triggers
- [ ] Trigger: Approval `[P]`.
- [ ] Trigger: Training expiry `[M]`.
- [ ] Trigger: Medical expiry `[M]`.
- [ ] Trigger: Contract expiry `[M]`.
- [ ] Trigger: Permit expiry `[M]`.
- [ ] Trigger: Meeting invitation `[M]`.
- [ ] Trigger: MOM distribution (into comms feed) `[P]`.
- [ ] Trigger: Action reminder `[M]`.
- [ ] Trigger: Strike `[M]`.
- [ ] Trigger: Suspension `[P]`.
- [ ] Dedicated in-app inbox for vendors `[P]` (pull-based alerts only).

## §32 Vendor Portal — governance-response half
- [ ] Respond to NCR `[M]`.
- [ ] Submit CAPA evidence `[M]`.
- [ ] Request approvals `[M]`.
- [ ] Request extensions `[M]`.
- [ ] View meeting invitations (general, not just kickoff) `[P]`.
- [ ] View MOM (general) `[P]`.
- [ ] Respond to actions `[M]`.
- [ ] Upload training/competency certificates `[P]` (medical/induction only today).
- [ ] View compliance register `[P]`.
- [ ] View PPE requirement matrix `[P]`.
- [ ] Self-registration `[M]` (may be by design — TPV vendor is staff-created).

## §33 Reports & Analytics
- [ ] Unified Reports hub enumerating the doc's named reports `[M]`.
- [ ] Operational report exports: Workforce, Gate, PPE, Training, Medical, Audit, Strikes, Meetings/MOM/Actions `[M/P]`.
- [ ] Management reports: Workforce Exposure, Project-wise Vendor Perf, Vendor-wise Project Perf `[M]`.
- [ ] Management reports: Compliance Exposure, Expiry, CAPA Closure rate, Incident Trend `[P]`.

## §34 TPV Settings (admin-configurable catalogs)
**Done this session (tenant-editable via new Settings UI + verified by tests + browser E2E):**
- [x] Approval workflows (mode/levels/SLA) — _TpvSettings `approval_workflow`_
- [x] Strike rules (limit/warn-at/critical) — _TpvSettings `strike_rules`_
- [x] Performance scoring (VPI weights/deductions/bands/window) — _TpvSettings `vpi`_
- [x] Authority matrix — _TpvSettings `authority_matrix`_
- [x] Approval types (label/active/custom) — _TpvSettings `approval_types`_
- [x] Gate PPE enforcement (warn/deny/off) — _TpvSettings `gate`_
- [x] Violation escalation ladder (severity points + thresholds) — _TpvSettings `violation_ladder`_ (added 2026-08-25, see §26)

**Still hardcoded / config-only (not yet tenant-editable):**
- [ ] Vendor types `[M]`.
- [ ] Vendor categories `[M]`.
- [ ] Risk levels `[M]`.
- [ ] Meeting types in TPV settings `[P]` (editable in the shared Meetings module).
- [ ] Meeting templates `[P]` (shared module).
- [ ] Onboarding templates `[M]`.
- [ ] Compliance templates/categories `[M]`.
- [ ] Document types `[M]`.
- [ ] Training types `[M]`.
- [ ] Competency requirements catalog `[M]`.
- [ ] PPE catalogue in settings `[P]` (managed in Inventory).
- [ ] PPE matrix in settings `[P]` (dedicated screen; also see §18).
- [ ] Permit types `[M]`.
- [ ] Violation types `[M]`.
- [ ] Notification rules `[M]` (.env/config only).
- [ ] Expiry rules `[P]` (only VPI doc-window editable; others hardcoded constants).
- [ ] Project-specific rules `[M]`.

## §35 Core Relationships
- [ ] Explicit vendor↔project pivot `[P]` (project link is via work packages today).
- [ ] Worker → Activity relation `[M]` (dup of §13).
- [ ] First-class MeetingAction owner model `[P]`.

## §40 Positioning
- [ ] Full positioning label ("Third-Party Vendor, Contractor & Workforce Governance") `[P]` (cosmetic).

## §41 Future AI Layer
- Out of scope for v1 (doc marks as future). Not counted as a gap. Revisit after the above.

---

### Progress counter (update as you tick)
- **Completed:** 24 — the 6 §34 settings groups; **Rule 4** competency enforcement + worker→work-package wiring (§13) + Work Package field (§14); **Rule 6** permit-for-high-risk (+ activity `requires_permit`/`permit_type` + editor); **Rule 11 (CAPA + NCR)** owner-required-to-progress; **Rule 9** auto-escalate vendor violations; **§18 PPE Matrix rebuild** (Job/Hazard/Activity context + Mandatory/Optional/Conditional class + replacement frequency + verification requirement; only Mandatory gates the badge); **§19 permit-type vocabulary** (Isolation/Shutdown/Critical Work added, General→Other with legacy accepted); **§23 incident-type vocabulary** (First Aid/Medical Treatment/LTI/Security/Unsafe Act/Unsafe Condition added); **§25 CAPA fields** (problem statement, immediate correction, separate preventive action, compliance-failure source); **§14 worker employment fields** (experience/joining date/exit date); **§21 compliance categories** (+10 → 24, doc's fuller set); **§26 configurable violation ladder** (7th settings group; severity points + thresholds; drives Rule 9 auto-escalation); **§4 dashboard compliance KPIs** (PPE Compliance %, Overall Compliance %, Action Centre PPE-pending row); **§28 renewal inputs** (compliance score + §25 CAPA register) [2026-08-25].
- **★ tier remaining:** the PPE-Matrix Job+Hazard+Activity rebuild; the Rule 11 MOM/inspection follow-on.
- **Open:** everything else above (§3–§35 depth items).
- **Note:** all of this is implemented & verified locally (feature tests green: CompetencyGate 5, PermitGate 6, ActionOwnerRule 5, ViolationEscalation 3, TpvSettings 5) but **not yet committed**.
- Keep this file updated: tick an item only when it's implemented AND matches the doc against real code.
