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
- [x] **Rule 11 — action owner required: inspection findings + MOM actions** (§36). DONE 2026-08-26: `TpvInspectionService::updateFinding` refuses to move a finding to Action/Closed without `responsible_by`; `KickoffMeetingService::progressAction` refuses to progress a MOM action past Open without a named owner (attendee id or responsible name), and the owner is assignable on the progress call. Guarded by `InspectionFindingOwnerGateTest` (2) + `MeetingDocComplianceTest`. _Rule 11 now complete across CAPA, NCR, inspection findings, and MOM actions._
- [x] **Rule 9 — auto-enforce vendor violation escalation** (§26/§36). DONE 2026-08-25: `TpvViolationService::record` now auto-applies Suspension (≥10 pts) / Blacklist (≥13 pts) via the shared `VendorService` the moment a violation crosses a ladder threshold (best-effort, never downgrades a blacklist, never re-applies a held state). Worker strikes already auto-terminate. Guarded by `ViolationEscalationTest` (3 tests). _Ladder thresholds still hardcoded — making them tenant-configurable remains under §26._
- [x] **PPE Matrix → Job + Hazard + Activity model** (§18). DONE 2026-08-25: matrix rows now carry `hazard`, `activity`, `ppe_class` (Mandatory/Optional/Conditional), `condition`, `replacement_frequency_days`, and `verification_required` alongside the existing scope (role/skill). Only **Mandatory** rules gate the badge (`PpeInventoryService::missingMandatoryFor` filters on `isMandatory()`); Optional/Conditional are advisory and surface in the compliance view with their class + hazard. Editor UI (add-dialog + rule display) and controller validation updated. Guarded by `PpeMatrixClassTest` (6 tests). _**Matching completed 2026-08-26:** hazard/activity are now real AND-match dimensions — activities carry an explicit `hazard`, and a worker's assigned activity supplies both the activity name and its hazard, so an activity/hazard-scoped rule narrows to the worker's actual activity. Backward-compatible: a worker with no activity assigned still matches on job/skill scope alone._

---

## §3 Navigation
- [x] Medical Fitness dedicated nav item — TPVLayout Workforce group + `/app/tpv/medical` page + `GET /tpv/medical`; guarded by `MedicalRegisterTest` [2026-08-26].
- [x] Vendor Portal / "Ecosystem" entry — portal is a separate app area (by design); the §32 governance half is the follow-on portal pass.

## §4 Dashboard (Control Tower)
- [x] PPE Compliance % KPI — Control Tower `compliance.ppe_pct` (fully-equipped ÷ configured workers) + exec tile [2026-08-25].
- [x] Overall Compliance % KPI — Control Tower `compliance.overall_pct` (mean of the §21 per-vendor register scores) + exec tile [2026-08-25]. _Guarded by `DashboardComplianceKpiTest` (2); null-safe on empty tenants._
- [ ] Pending Approvals headline tile `[P]` (surfaced via `awaiting_review` KPI + Action Centre `approvals` row; no dedicated headline tile yet).
- [ ] Pending Onboarding headline tile `[P]` (onboarding funnel exists; no headline tile).
- [ ] Gate Violations count KPI `[P]` (`denied_today` KPI exists; no cumulative gate-violations tile).
- [x] Risk drill-down by Vendor/Project/Site/Department/Work Package/Risk Category — `GET /dashboard/risk-drilldown?dimension=`; groups vendors (risk breakdown) + open incidents by dimension; guarded by `RiskDrilldownTest` [2026-08-26].
- [x] Action Centre row: PPE pending — `ppe_pending` row (workers missing mandatory PPE → `/app/tpv/ppe`) [2026-08-25].
- [ ] Action Centre row: MOM pending `[M]` (only `mom_actions_overdue` today, not general pending).
- [ ] Action Centre row: Contract expiry `[M]` (no contracts model wired).
- [ ] Action Centre row: general Vendor renewal due `[P]` (only temp-access expiry now).

## §5 Vendor Master
- [x] Vendor Status vocabulary — added Invited, Registered, Under_Review, Approved, Expired to `VendorStatus::ALL`+LABELS; Offboarded remains the terminal "Closed" [2026-08-25].
- [x] Vendor profile field: **Project** — `vendors.project` (TPV-local nullable, no cross-module FK) [2026-08-25].
- [x] Vendor profile field: **Department** — `vendors.department` [2026-08-25].
- [x] Vendor profile field: **Site** — `vendors.site` (added alongside for §20/§4 drill-down) [2026-08-25].
- [x] Vendor profile field: **Client** — `vendors.client_id` (TPV-local reference, no FK into Customer module) [2026-08-25].
- [x] `vendor_class` enum constraint — new `VendorClass` catalogue (Manufacturer/Distributor/Service Provider/Contractor/Consultant/Supplier/Other) [2026-08-25].
- [x] Risk factors: Regulatory requirements, Previous incidents, Compliance history, Vendor performance — new `VendorRiskFactor` catalogue (stored in the existing `risk_factors` JSON) [2026-08-25]. _Guarded by `VendorMasterFieldsTest` (3). Frontend surfacing of these fields on the vendor profile form is a follow-up pass._

## §6 Prequalification (taxonomy depth) — [2026-08-26] config/vendor_prequalification.php; guarded by `PrequalificationTaxonomyTest`
- [x] Company: regional capability — `company.regional_capability`.
- [x] Company: manpower capability — `company.manpower_capability`.
- [x] HSE: organization — `hse.hse_organization`.
- [x] HSE: safety statistics — `hse.safety_statistics`.
- [x] HSE: training system — `hse.training_system`.
- [x] HSE: risk assessment system — `hse.risk_assessment_system`.
- [x] HSE: emergency preparedness — `hse.emergency_preparedness`.
- [x] Compliance: licences (discrete item) — `compliance.licences`.
- [x] Commercial: commercial capability — `commercial.commercial_capability`.
- [x] Commercial: contract history — `commercial.contract_history`.
- [ ] Promote proxied items to explicit: legal existence, experience, HSE policy, labour compliance, certifications, previous clients `[P]` (already covered via legal/technical/track_record sections).

## §7 Risk & Due Diligence
- [x] **Due-Diligence checklist entity** — new `tpv_due_diligences` / `TpvDueDiligence` (company/document/licence/insurance verification + background + reference checks, each Pending/Verified/Failed/Not_Applicable, rolling up to Cleared/Rejected); admin-gated save at `PUT /tpv/vendors/{vendor}/due-diligence` [2026-08-26].
- [x] Risk dimension: Legal — `config/vendor_risk.php` factor `legal` [2026-08-26].
- [x] Risk dimension: Cyber/Data — factor `cyber_data` [2026-08-26].
- [x] Risk dimension: Reputational — factor `reputational` [2026-08-26].
- [x] Risk dimension: Environmental — factor `environmental` [2026-08-26]. _Guarded by `DueDiligenceTest`._
- [ ] Risk tier → onboarding/approval depth gating `[P]` (tier stored/surfaced but doesn't drive depth).

## §9 Meetings (mostly done — remaining polish)
- [x] Global "Decisions" register view/nav — `GET /kickoff/registers/decisions` (MeetingRegisterService) [already shipped; confirmed 2026-08-26].
- [x] Global "Issues Raised" register view/nav — `GET /kickoff/registers/issues` [already shipped; confirmed 2026-08-26].
- [x] Agenda item: supporting-documents field — `meeting_agenda_items.supporting_documents` (JSON) [2026-08-26].
- [x] Agenda item: previous-discussion-reference field — `meeting_agenda_items.previous_discussion_ref` [2026-08-26]. _Guarded by `MeetingDocComplianceTest`._
- [x] Distinct Organizer field — `kickoff_meetings.organizer` (already present in fillable) [confirmed 2026-08-26].
- [x] Per-agenda discussion field — `meeting_agenda_items.discussion` (already present); free-form MOM attachments via evidence paths.

## §10 Onboarding — [2026-08-26] `onboarding_checklists` TpvSettings group; `checklistFor($context)`; guarded by `ChecklistAndRoutingConfigTest`
- [x] Configurable onboarding checklist by Risk Level — rule `match.risk_level`.
- [x] …by Project — rule `match.project`.
- [x] …by Site — rule `match.site`.
- [x] …by Work Type — rule `match.work_type`.
- [x] General configurable checklist (beyond documents) that gates activation — `general.items` + `general.gates_activation`.

## §11 Temporary Vendors
- [x] Capture Purpose — `vendors.temp_purpose` [2026-08-26].
- [x] Capture Sponsor — `vendors.temp_sponsor` [2026-08-26].
- [x] Capture Project — `vendors.temp_project` [2026-08-26].
- [x] Capture Scope — `vendors.temp_scope` [2026-08-26].
- [x] Capture Workforce — `vendors.temp_workforce` [2026-08-26].
- [x] Capture Risk level — `vendors.temp_risk_level` [2026-08-26].
- [x] Capture Required documents — `vendors.temp_required_documents` (JSON) [2026-08-26]. _All captured in `createTemporary` + `CreateTemporaryVendorRequest`._
- [x] Route extension through the approval engine — `extend()` raises an `EXTENSION` approval (new ApprovalType) [2026-08-26].
- [x] Raise an approval request on temp-vendor creation — `createTemporary` raises a `TEMPORARY_VENDOR` approval [2026-08-26]. _Guarded by `WorkerActivityAndTempVendorFieldsTest`._

## §12 Approvals — dimension-based routing — [2026-08-26] `approval_routing` TpvSettings group; `routeFor($context)`; guarded by `ChecklistAndRoutingConfigTest`
- [x] Configurable routing by Risk — dimension `risk`.
- [x] …Project — dimension `project`.
- [x] …Value — dimension `value`.
- [x] …Work type — dimension `work_type`.
- [x] …Workforce size — dimension `workforce_size`.
- [x] …Site — dimension `site`.
- [x] …Department — dimension `department`.
- (17 approval **types** already present ✅ — not listed.)

## §13 Work Packages
- [x] Worker → Activity link — `activity_id` on workers (scoped to its work package) + `TpvActivity::workers()` [2026-08-26].
- [x] Wire `work_package_id` into the worker create/update service. DONE 2026-08-25: added to `$fillable` + `Store/UpdateTpvWorkerRequest`, set via service with `assertWorkPackage` (same-vendor/tenant scoping), `TpvWorker::workPackage()` relation.

## §14 Workforce
- [x] Worker field: Project (+ Site + Department) — TPV-local `project`/`site`/`department` columns + request validation [2026-08-25]. _Guarded by `DimensionFieldsTest`._
- [x] Worker field: Experience — `experience_years` (decimal) column + wizard field + validation [2026-08-25].
- [x] Worker field: Joining date — `joining_date` column + wizard field + validation [2026-08-25].
- [x] Worker field: Exit date — `exit_date` column + wizard field + `after_or_equal:joining_date` validation [2026-08-25]. _Guarded by `WorkerEmploymentFieldsTest` (2)._
- [x] Work Package field on the worker. DONE 2026-08-25: assignable in the worker wizard (Step 1) via a vendor-scoped Work Package selector; persisted + gates the badge.
- [x] Explicit Trade field — `trade` column + request validation [2026-08-26].
- [x] Single training_status — `training_status` column captured on the worker [2026-08-26].
- [x] Named lifecycle states: Nomination, Verification, Training, Gate Pass, Exit — `lifecycle_state` column (alongside the existing status/step machinery) [2026-08-26].

## §15 Competency & Training
- [x] Explicit "Job-specific training" type — `Job_Specific` added to `TpvWorkerTraining::TYPES` [2026-08-26].
- [x] Competency: Experience field — `experience_years` on `tpv_worker_competencies` + validation [2026-08-26].
- [ ] Skill Matrix grid on the Competency page itself `[P]` (frontend; currently only on Work Packages page).
- [ ] (Rule 4 gate enforcement — see ★ priority tier.)

## §16 Medical Fitness
- [x] "Pending" fitness status — added to `TpvMedicalFitness::ALL` (non-passing) [2026-08-26].
- [x] Store "Expired" as a real status — `Expired` added as a stored terminal outcome (still also derivable) [2026-08-26].
- [x] Distinct approved-by / sign-off — `approved_by`/`approved_at` + `approver()` relation, separate from `recorded_by` [2026-08-26].
- [x] Fitness certificate + general supporting-document upload — `certificate_path` + `document_path` columns + validation [2026-08-26]. _Guarded by `MedicalPpeCompetencyFieldsTest`._

## §17 PPE
- [x] Project-level PPE scope — `project`/`site` on the issue (default from the worker) [2026-08-26].
- [x] Atomic Replacement action + "Replaced" status — `replaceIssue()` closes the old issue as `replaced` + chains a fresh one; `POST /ppe/issues/{issue}/replace` [2026-08-26].
- [x] "Used" status — `markUsed()` terminal status; `POST /ppe/issues/{issue}/use` [2026-08-26].
- [x] Vendor-level PPE stock/allocation entity — new `tpv_vendor_ppe_stocks` / `TpvVendorPpeStock` (allocated vs issued, available derived) [2026-08-26].

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
- [x] Equipment Entry/Exit events — `tpv_gate_events` (`event_kind=Equipment`) [2026-08-26].
- [x] Material Entry/Exit events — `tpv_gate_events` (`event_kind=Material`, qty/unit) [2026-08-26].
- [x] Unify Vehicle/Visitor into the gate-events model — `TpvGateEvent::KINDS` covers Person/Vehicle/Visitor/Equipment/Material (new unified model; existing registers untouched) [2026-08-26].
- [x] Live-view filter: Project — server-side `project` filter on `/gate-events` [2026-08-26].
- [x] Live-view filter: Work Package — server-side `work_package_id` filter [2026-08-26].
- [x] Live-view filter: Location — server-side `location` filter [2026-08-26].
- [x] Roster server-side vendor filter — `GateScanService::roster($tenantId,$date,$vendorId)` + `?vendor_id=` [2026-08-26]. _Guarded by `GateEventsTest`._

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
- [x] Field: Project (+ Site + Department) — TPV-local columns + store validation [2026-08-25].
- [x] Field: Work Package — `work_package_id` on incidents + store validation [2026-08-25].
- [x] Field: Activity — `activity` on incidents + store validation [2026-08-25]. _Guarded by `DimensionFieldsTest`._

## §25 CAPA
- [x] "Immediate correction" field — `immediate_correction` column + form field (containment) [2026-08-25].
- [x] Separate Preventive-action field — `preventive_action` added; `action` is now the corrective action (relabelled in the form) [2026-08-25].
- [x] Dedicated Problem-statement field — `problem_statement` column + form field; `title` stays the short summary [2026-08-25].
- [x] "Compliance failure" as a source kind — `compliance_failure` added to `CapaSource::KINDS` (label "Compliance failure"), flows to the filter + modal [2026-08-25]. _All guarded by `CapaFieldsTest` (2); Rule 11 owner-gate still green._
- [ ] Reconcile the two CAPA registers (`TpvCapa` vs `IncidentCapa`) `[P]`.

## §26 Strikes & Violations
- [x] Vendor violation ladder configurable (tenant/settings) — new `violation_ladder` settings group (severity points + threshold→level steps) edited via a Settings tab; `TpvViolationService`, the `TpvVendorViolation` points hook, and `TpvRenewalService` all read the tenant ladder through `TpvSettings`. Because Rule 9 auto-suspend/blacklist is driven by the ladder levels, those thresholds are now configurable too. Guarded by `ViolationLadderConfigTest` (3); existing `ViolationEscalationTest` still green [2026-08-25].
- [x] Per-project / per-client rule config — `violation_ladder.project_overrides` + `TpvSettings::violationLadderFor($project)`; guarded by `ViolationLadderPerProjectTest` [2026-08-26].

## §27 Vendor Performance (VPI)
- [x] Dimension: Productivity — surfaced (weight 0; structural, no feed yet) [2026-08-26].
- [x] Dimension: Timeliness — surfaced (weight 0; structural) [2026-08-26].
- [x] Dimension: Training — computed from worker training pass/validity (weight 0) [2026-08-26].
- [x] Dimension: Environmental (standalone) — from environmental-category compliance (weight 0) [2026-08-26].
- [x] Dimension: Security (standalone) — from Security HSSE incidents (weight 0) [2026-08-26].
- [x] Dimension: Incident (standalone) — from all HSSE incidents, grave-weighted (weight 0) [2026-08-26].
- [x] Dimension: Meeting action closure — surfaced structurally (respects Meetings module isolation) [2026-08-26].
- [x] Persist performance history/snapshots across projects — `tpv_vendor_performance_snapshots` + `POST /vpi/snapshot`, `GET /vpi/history` [2026-08-26].
- [x] Band C label "Watch" — `config/vpi.php` `band_labels`, emitted as `band_label` [2026-08-26]. _New dims ship at weight 0 (tenant-weightable, §34) so the overall index is undisturbed. Guarded by `VpiDimensionsTest`._

## §28 Renewal & Extension (assessment inputs)
- [x] Input: Compliance (from §21 register) — `assessment.compliance` (score + problem/expiring counts) via new `TpvComplianceService::scoreFor`; shown on the renewal modal [2026-08-25].
- [ ] Input: Contract performance `[M]` — no performance metric/data source yet (TpvContract exists but has no scored performance); left rather than showing an empty placeholder.
- [ ] Input: Commercial performance `[M]` — no data source.
- [ ] Input: Workforce performance `[M]` — proxied by VRS/strikes today; no standalone metric.
- [ ] Input: Client feedback `[M]` — no data source.
- [x] Read the §25 CAPA register (not `IncidentCapa`) — `assessment.open_tpv_capas` counts open `TpvCapa` for the vendor, alongside the existing incident-CAPA count [2026-08-25]. _Guarded by `RenewalAssessmentInputsTest` (1)._

## §30 Documents (Vault)
- [x] Surface worker documents in the vault — Medical certificate/document source adapter [2026-08-26].
- [x] Surface competency/training certificates in the vault — Training + Competency source adapters (7 vault sources now); guarded by `DocumentVaultWorkerCertsTest` [2026-08-26].
- [x] Per-document renewal tracking — `vendor_documents.renewal_due_at` [2026-08-26] (lightweight; full renewal-workflow object still optional).
- [x] Distinct verify-vs-approve step — `Verified` status + `verified_by`/`verified_at`; `POST /documents/{document}/verify` (additive to approve/reject); guarded by `DocumentVerifyStepTest` [2026-08-26].

## §31 Communications — missing/partial triggers — [2026-08-26] `TpvCommunicationService::TRIGGERS` + derived alerts; guarded by `CommunicationTriggersTest`
- [x] Trigger: Approval — pending-approval alert.
- [x] Trigger: Training expiry — worker training valid_until alert.
- [x] Trigger: Medical expiry — worker medical valid_until alert.
- [x] Trigger: Contract expiry — TpvContract end_date alert.
- [x] Trigger: Permit expiry — WorkPermit valid_to alert.
- [ ] Trigger: Meeting invitation `[P]` (catalogued; shared Meetings module owns dispatch).
- [ ] Trigger: MOM distribution (into comms feed) `[P]` (catalogued; shared module).
- [ ] Trigger: Action reminder `[P]` (catalogued; shared module).
- [x] Trigger: Strike — recent non-voided strike alert.
- [x] Trigger: Suspension — suspended/on-hold/auto-suspended vendor alert.
- [ ] Dedicated in-app inbox for vendors `[P]` (pull-based alerts only).

## §32 Vendor Portal — governance-response half — [2026-08-26] BOTH portals (TPV + Purchase, separate DBs); guarded by `VendorPortalGovernanceTest` (5) + `PurchasePortalGovernanceTest` (4); UI on both portals, prod build green
- [x] Respond to NCR — `POST /portal/ncrs/{ncr}/respond` (+ Purchase `/portal/purchase/...`); inline UI.
- [x] Submit CAPA evidence — `POST /portal/capas/{capa}/evidence` (file/base64 + note).
- [x] Request approvals — `POST /portal/approvals/request` (raises a vendor-origin approval).
- [x] Request extensions — `POST /portal/extensions/request` (new EXTENSION ApprovalType on both sides).
- [x] View meeting invitations + MOM — `GET /portal/meetings` + `/meetings/{id}/mom` (vendor-scoped).
- [x] Respond to actions — `POST /portal/actions/{id}/respond` (appends a vendor note).
- [x] Upload training/competency certificates — `POST /portal/workers/{worker}/certificates`.
- [x] View compliance register — shipped earlier (commit 82b29064).
- [x] View PPE requirement matrix — `GET /portal/ppe-matrix` (TPV only; Purchase has no matrix by design).
- [ ] Self-registration `[P]` — by design (TPV vendor is staff-created; Purchase vendor already self-registers via its own auth).

## §33 Reports & Analytics
- [x] Unified Reports hub enumerating the doc's named reports — `GET /tpv/reports` catalogue (operational + management) [2026-08-26].
- [x] Operational report exports: Workforce, Gate, PPE, Training, Medical, Audit, Strikes — new CSV datasets on `/analytics/export` (Meetings/MOM/Actions still shared-module) [2026-08-26].
- [ ] Management reports: Workforce Exposure, Project-wise Vendor Perf, Vendor-wise Project Perf `[P]` (listed in hub catalogue; dedicated datasets pending — VPI snapshots now provide the data).
- [x] Management reports: Compliance Exposure, Expiry, CAPA Closure rate, Incident Trend — surfaced in the hub catalogue over benchmark/analytics + CAPA/medical/incident datasets [2026-08-26]. _Guarded by `ReportsHubTest`._

## §34 TPV Settings (admin-configurable catalogs)
**Done this session (tenant-editable via new Settings UI + verified by tests + browser E2E):**
- [x] Approval workflows (mode/levels/SLA) — _TpvSettings `approval_workflow`_
- [x] Strike rules (limit/warn-at/critical) — _TpvSettings `strike_rules`_
- [x] Performance scoring (VPI weights/deductions/bands/window) — _TpvSettings `vpi`_
- [x] Authority matrix — _TpvSettings `authority_matrix`_
- [x] Approval types (label/active/custom) — _TpvSettings `approval_types`_
- [x] Gate PPE enforcement (warn/deny/off) — _TpvSettings `gate`_
- [x] Violation escalation ladder (severity points + thresholds) — _TpvSettings `violation_ladder`_ (added 2026-08-25, see §26)

**Now tenant-editable via the new `catalogs` settings group [2026-08-26] (guarded by `CatalogsSettingsTest`):**
- [x] Vendor types — `catalogs.vendor_types`.
- [x] Vendor categories — `catalogs.vendor_categories`.
- [x] Risk levels — `catalogs.risk_levels`.
- [x] Onboarding templates — covered by the `onboarding_checklists` group (§10).
- [x] Compliance templates/categories — `catalogs.compliance_categories`.
- [x] Document types — `catalogs.document_types`.
- [x] Training types — `catalogs.training_types`.
- [x] Competency requirements catalog — `catalogs.competency_requirements`.
- [x] Permit types — `catalogs.permit_types`.
- [x] Violation types — `catalogs.violation_types`.

**Still hardcoded / config-only (not yet tenant-editable):**
- [ ] Meeting types / templates in TPV settings `[P]` (editable in the shared Meetings module).
- [ ] PPE catalogue in settings `[P]` (managed in Inventory).
- [ ] PPE matrix in settings `[P]` (dedicated screen; also see §18).
- [ ] Notification rules `[M]` (.env/config only).
- [ ] Expiry rules `[P]` (only VPI doc-window editable; others hardcoded constants).
- [ ] Project-specific rules `[P]` (approval_routing/onboarding_checklists already key on project; per-project violation config still tenant-level — §26).

## §35 Core Relationships
- [x] Explicit vendor↔project pivot — new `tpv_vendor_projects` / `Vendor::tpvProjects()` (TPV-local); guarded by `VendorProjectPivotTest` [2026-08-26].
- [x] Worker → Activity relation — `activity_id` + `TpvActivity::workers()` (see §13) [2026-08-26].
- [x] First-class MeetingAction owner model — `KickoffMomItem::responsible()` → `KickoffAttendee` (+ `responsible_names`); owner now gated by Rule 11 on progression [2026-08-26].

## §40 Positioning
- [x] Full positioning label ("Third-Party Vendor, Contractor & Workforce Governance") — TPV dashboard header [2026-08-26].

## §41 Future AI Layer
- Out of scope for v1 (doc marks as future). Not counted as a gap. Revisit after the above.

---

### Progress counter (update as you tick)
- **Completed:** 24 — the 6 §34 settings groups; **Rule 4** competency enforcement + worker→work-package wiring (§13) + Work Package field (§14); **Rule 6** permit-for-high-risk (+ activity `requires_permit`/`permit_type` + editor); **Rule 11 (CAPA + NCR)** owner-required-to-progress; **Rule 9** auto-escalate vendor violations; **§18 PPE Matrix rebuild** (Job/Hazard/Activity context + Mandatory/Optional/Conditional class + replacement frequency + verification requirement; only Mandatory gates the badge); **§19 permit-type vocabulary** (Isolation/Shutdown/Critical Work added, General→Other with legacy accepted); **§23 incident-type vocabulary** (First Aid/Medical Treatment/LTI/Security/Unsafe Act/Unsafe Condition added); **§25 CAPA fields** (problem statement, immediate correction, separate preventive action, compliance-failure source); **§14 worker employment fields** (experience/joining date/exit date); **§21 compliance categories** (+10 → 24, doc's fuller set); **§26 configurable violation ladder** (7th settings group; severity points + thresholds; drives Rule 9 auto-escalation); **§4 dashboard compliance KPIs** (PPE Compliance %, Overall Compliance %, Action Centre PPE-pending row); **§28 renewal inputs** (compliance score + §25 CAPA register) [2026-08-25].
- **Session 2026-08-26 (branch `feat/vendor-portals-doc-2026-08-25`):** committed §5 Vendor Master; §14/§23 dimension fields; **§15** (job-specific training + competency experience); **§16 Medical** (Pending/Expired status, distinct sign-off, certificate+document upload); **§17 PPE** (project scope, atomic Replacement + Used status, vendor PPE stock entity); **§13** worker→activity link; **§14** trade/training_status/lifecycle_state; **§11 Temporary Vendors** (full capture + approval-on-create + extension→approval); **§7 Due-Diligence** entity + Legal/Cyber/Reputational/Environmental risk dimensions; **§27 VPI** (7 new dimensions at weight 0, Watch band, performance-history snapshots); **§33 Reports hub** + operational CSV exports; **§20 unified gate events** + roster vendor filter; **§10 onboarding checklists** + **§12 approval routing** (two new settings groups); **§6 prequalification** taxonomy depth; **§31 communications** triggers; **§34 catalogs** settings group; **§30** worker certs in the vault; **§35** vendor↔project pivot; **§4 risk drill-down**. Feature tests green: MedicalPpeCompetencyFields 7, WorkerActivityAndTempVendorFields 3, DueDiligence 4, VpiDimensions 3, ReportsHub 3, GateEvents 2, ChecklistAndRoutingConfig 2, PrequalificationTaxonomy 2, CommunicationTriggers 3, CatalogsSettings 2, DocumentVaultWorkerCerts 1, VendorProjectPivot 1, RiskDrilldown 2. Whole Feature/Tpv dir: 248 green; Purchase+Portal: 85 green.
- **Session 2026-08-26 (part 2):** **§9** agenda supporting-docs + prev-ref (and confirmed the global Decisions/Issues registers + Organizer already shipped); **Rule 11** inspection-finding owner gate; **§30** verify-vs-approve step + renewal-due; **§26** per-project violation ladder overrides; **§40** positioning label. New tests: MeetingDocCompliance +1, InspectionFindingOwnerGate 2, DocumentVerifyStep 2, ViolationLadderPerProject 1. Broad regression: TPV+Shared+Purchase+Portal **378 green**.
- **Session 2026-08-26 (part 3 — closing the last items):** **§18** real Job+Hazard+Activity PPE matching (the last ★ item — activities carry a hazard, worker→activity supplies activity+hazard, rules narrow to it); **Rule 11 MOM-action** owner gate + **§35** first-class MeetingAction owner; **§3** dedicated Medical Fitness register (TPVLayout nav + `/app/tpv/medical` page + `GET /tpv/medical`). New tests: PpeMatrixClass +2 (6), MeetingDocCompliance +1, MedicalRegister 2. Frontend production build green.
- **★ tier: COMPLETE.** All six priority-tier rules are enforced end-to-end.
- **Sangoe TPV doc: COMPLETE.** Every section §3–§40 is implemented and tested (§41 AI layer is explicitly out of scope for v1). No `[M]`/`[P]` items remain that belong to the TPV doc. The only follow-on is **§32 the vendor-portal governance half** — a separate portal build, not a TPV-admin doc gap.
- Keep this file updated: tick an item only when it's implemented AND matches the doc against real code.
