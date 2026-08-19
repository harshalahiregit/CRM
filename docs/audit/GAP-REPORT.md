# CRM ⟷ Spec-Docs GAP REPORT

**Purpose:** a complete, evidence-backed map of every requirement in `docs/source-docs/*`
against the actual code, so implementation can proceed without missing an element.
Built from six parallel code audits (onboarding/status, workforce/safety,
governance/commercial/risk/dashboards, CRM-base/enhancements, Purchase parity,
cross-module connections).

**Legend:** ✅ DONE · 🟡 PARTIAL (present but diverges/incomplete) · ❌ MISSING · 🐞 BUG

> **Headline:** The **base CRM** and the **operational TPV pipeline** (vendor onboarding
> → workforce → badge → gate scan) are built and working. The gaps fall into three
> buckets: (1) a short list of **concrete bugs**, (2) the **enhancement-list items** and
> **operational spec details** still missing, and (3) the large **governance / commercial
> / risk / dashboard superstructure** (Docs 1/2/5/6 + Phases 25–46), which is essentially
> greenfield. Nothing is "half-lost"; the report enumerates all of it.

---

## 0. CONFIRMED BUGS — fix first (hours, not days)

| # | Bug | Evidence | Impact |
|---|-----|----------|--------|
| B1 | 🐞 `VendorService::approve()` references a non-existent class `\App\Support\Tpv\OnboardingStatus::APPROVED` (only `TpvOnboardingStatus` exists) | `backend/app/Services/Vendor/VendorService.php:163,173` | `POST /vendors/{vendor}/approve` **fatals** for a TPV vendor. The main wizard-approval path (`TpvOnboardingService::approve`) is correct and works, so this is a latent landmine on the alternate route. |
| B2 | 🐞 Purchase portal ships the dead `mailto:support@company.com` placeholder | `frontend/src/pages/purchase-portal/PurchasePortalShell.jsx:75` | Same anti-pattern we already removed on the TPV portal (`PortalSupport.jsx`). Regression / inconsistency. |
| B3 | 🟡 A project linked by `vendor_id` to a vendor whose `user_id` is null never appears on any portal (portal reads by `vendor_user_id`) | `ProjectService.php:764`, `VendorWorkController.php:48-49` | Silent invisibility for login-less vendors. |

---

## 1. ENHANCEMENT LIST — `CRM_Project_TASKS_Vendor_TPV.md` (highest-priority actionable doc)

| # | Requirement | Status | Evidence / Gap |
|---|-------------|--------|----------------|
| 1 | Vendor code auto-generated | ✅ | `Vendor.php:57-68` `VEN-YYYY-NNN`; unique per tenant |
| 2 | Vendors & TPVs have their **own employees** (linked to one, not both) | ❌ | **No vendor/TPV "employee" entity exists.** `TpvWorker` is HSSE site-workforce, not an assignable CRM user. Assignee pickers list vendor/TPV **login accounts** directly. This is the upstream blocker for #9 and #10. |
| 3 | "Direct Vendor" (direct payment from Nexfore) distinction | ❌ | No `is_direct`/direct-payment field anywhere; only `vendor_type`/`registration_type`. |
| 4 | Client (B) is NOT a Vendor — kept separate | ✅ | Distinct `Customer/Client` vs `Vendor/Vendor` models; project links exactly one party type. |
| 5 | Vendor dashboard responsive; TPV dashboard fully working | 🟡 | Portal dashboard redesigned responsive; admin `TpvDashboard.jsx` is HSSE-only (lacks Projects/Tasks/KB — see #6). |
| 6 | BOTH dashboards show Projects, Tasks, Tickets, **Knowledge Base** | 🟡 | Projects/Tasks/Tickets present on the portal dashboard; **KB is missing everywhere** (no route, no tile). Admin TPV board shows only HSSE KPIs. |
| 7 | Task creation area enlarged; long-desc usability | ✅ | `TaskFormDrawer.jsx` 760px modal + ReactQuill rich text. |
| 8 | Links in descriptions/notes open in new window | ✅ | Server-side sanitizer forces `target=_blank rel=noopener` (`HtmlSanitizer.php:235-238`). |
| 9 | Assignee cascade: pick Vendor/TPV → show **only its employees** → assign | ❌ | No cascade; pickers list all vendor/TPV login users. Blocked by #2. |
| 10 | Visibility: Project/Task visible **only** to selected Vendor/TPV + its employees | 🟡 | Scoped at vendor **login-user** level (members / `vendor_user_id` / rel-type), not per-employee. Blocked by #2. |
| 11 | Nested subtask system + total/completed/pending/progress% | ✅ | `parent_id/root_id/depth` tree, `TaskTreeService`, `SubtaskTree.jsx`, mail hooks. |
| 12 | Daily auto-backup to OneDrive/pCloud, keep last 3–4, auto-delete | ❌ | No backup command, no scheduler entry. Cloud code is attachment-import only, not DB/file backup. |

**Delivered:** #1, #4, #7, #8, #11. **Missing/partial:** #2, #3, #5, #6, #9, #10, #12.

---

## 2. TPV ONBOARDING & STATUS MACHINE

Built (✅): temp/permanent registration, 6-step wizard (Kickoff MOM → Company → Documents
→ Under Review → Confirmation → Approval), kickoff meeting + MOM/PDF + email/WhatsApp
share, activation + credential email (once-only, logged), vendor-code + registration-number
auto-gen, doc green/amber/red indicators.

| Element | Status | Gap |
|---------|--------|-----|
| Mandated **11-state gate-driven status machine** (Docs_Pending/Workforce_Pending/Safety_Pending/vendor-Suspended/Offboarded) | 🟡/❌ | Status split across two enums (`VendorStatus`, `TpvOnboardingStatus`); the gate-driven states and "no skipping gates" rule are not enforced. |
| The 5 gates (Commercial/Legal, Workforce, Safety, Internal-Approval PMC/Safety/Accounts/Admin, Activation) | 🟡/❌ | Gate 1 partial (statutory docs only, no contract/insurance gate); Gates 2 & 3 missing; Gate 4 uses generic staff→admin not the 4 named authorities; Gate 5 not conditioned on cards+site. |
| Auto-suspension (insurance expiry / fatal incident / stop-work / risk breach) | ❌ | No vendor `Suspended` state; nothing suspends a vendor automatically. |
| **Start Work Letter** issuance on approval | ❌ | Templates exist only as docs (`Leo_Enterprises_HSSE_Work_Start_Letter`, `Contractor_*Letter`); no PDF generation/issuance. Approval sends a plain welcome email. |
| Wireframe steps: Workforce-Readiness, Safety-Declaration | ❌ | Implemented wizard follows `Tpv_doc.md` (6 steps), not the wireframe's workforce/safety steps. |
| Tracker: Blocking-Reason column, true % (Medical%/Induction%), named PMC/Safety/Accounts/Admin approval timeline | 🟡 | Progress is step-based (`current_step/6`); no blocking-reason column; approval tracker shows generic levels. |

---

## 3. TPV WORKFORCE & SAFETY OPERATIONS (Phases 3–6, DOC_4)

Built (✅): worker registration, medical (fit/unfit), induction, PPE issue/return via
Inventory, digital access card + QR, **badge activation gate**, **public gate scan +
check-in/out + attendance**, 3-punch discipline (auto-terminate), safety strikes.

| Element | Status | Gap |
|---------|--------|-----|
| **Medical expiry auto-block** (§8) | ❌ | **Spec-critical.** No `valid_until` on `tpv_worker_medicals`; expired medical only *warns* at the gate (`GateScanService.php:99-101`), never denies; activation blockers ignore expiry. |
| Aadhaar **masking** (§7) | ❌ | Stored and displayed in full. |
| Card **awards** + **BOCW health card**; training on card (§12, Document__15_) | ❌ | No award/reward concept; no BOCW health card; training not surfaced on the card. |
| Toolbox Talk & Micro-training (§10) | ❌ | Entire subsystem absent. |
| Fatigue & Working-Hours control (§11) | ❌ | No hour caps, overtime/night tagging, or breach alerts. |
| Visitor management (§13) | ❌ | Absent. |
| Vehicle in/out & driver control (§14) | ❌ | Absent. |
| Equipment/tool certification register + auto-block on expiry (§15) | ❌ | Absent. |
| Material in/out register (§16) | ❌ | Absent. |
| PPE loss/misuse penalty linkage (§17) | ❌ | PPE issue/return built; no penalty hook. |
| PTW (permit types) + JSA/method statement + versioning + TPV→PMC→Safety approval (§18-19, DOC_4) | ❌ | Entire PTW subsystem absent. |
| Incidents/near-miss + RCA + CAPA (§20, DOC_4) | ❌ | Absent; "cannot close without RCA+CAPA" rule absent. |
| Safety observations (unsafe act/condition) + evidence + validation + closure (DOC_4) | ❌ | Absent. |
| Stop-Work Authority + restart (§21) | ❌ | Absent. |
| Escalation & breach matrix (§22), Supervisor scorecard (§23) | ❌ | Absent (onboarding-approval escalation exists but is not this). |
| Emergency preparedness & mock drills (§24, DOC_4) | ❌ | Absent. |

---

## 4. GOVERNANCE / COMMERCIAL / RISK / DASHBOARDS (Docs 1/2/5/6, Phases 25–46)

**The entire ER superstructure (~40 spec tables) is not built.** This is the greenfield bulk.

- **Doc 1 (Governance & Authority):** authority_roles, authority_permissions (delegation matrix), stop_work_authority + restart, escalation_levels + events, immutable system_audit_logs, system_configuration (module enable/licensing) — all ❌.
- **Doc 2 (Commercial):** sub_vendors (chain-of-responsibility), work_orders, deviations/change-control, penalties, rewards, legal_cases, exit_analysis, offboarding — all ❌ (vendor master itself is ✅).
- **Doc 5 (Intelligence & Risk):** vendor_risk_score (VRS) + risk_events + score_decay, vendor_performance_reports, supervisor_score + events + boundaries, audit_reports + findings — all ❌.
- **Doc 6 (Dashboards & Evidence):** kpi_snapshots, risk_heatmaps, dashboard_alerts, **evidence_locker (immutable)**, report_snapshots (DPR/WPR/MCR), exec_risk_summary, client_dashboard_logs — all ❌.
- **Phases 25–46:** DPR/WPR/MCR ❌, MIS dashboard ❌, VPR/VRS ❌, penalty mgmt ❌, award/reward ❌, notice/legal/claims ❌, ESG/SDG ❌, project handover ❌, evidence locker ❌, data archival ❌, vendor exit/offboarding ❌, config/licensing/white-label 🟡. **DONE in general form:** SLA (#35, Helpdesk), Knowledge Base (#37, Helpdesk), internal notes (#36). **Partial:** invoice/payment (no TPV→PMC→Client 3-tier).

### Reusable building blocks (clone, don't rebuild)
- **Events-based scoring engine** — `hr_employee_scores` + `hr_employee_score_history` (snapshot-from-immutable-events) = exactly the Doc-5 VRS/supervisor pattern.
- **Append-only audit log** — `acc_audit_logs` (`$timestamps=false`, before/after JSON) = best base for immutable `system_audit_logs`.
- **Scored compliance/checklist engine** — `compliance_templates/checklists/signatures` = base for `audit_reports`/`audit_findings`.
- **Polymorphic audit trail** — `audit_logs` + `AuditLogService` (needs immutability hardening).
- **Per-tenant settings** — `tenant_settings` = base for `system_configuration`/licensing.
- **Helpdesk** — SLA + KB + tickets satisfy Phases 35 & 37 in general form.

---

## 5. PURCHASE VENDOR PARITY

Purchase vendor **backend** is strong-to-superior (own authenticated identity + email
verification, 6-step onboarding, workforce, native commercial ownership, richer kickoff).
The gaps are almost entirely **Purchase portal frontend wiring**, mirroring what TPV fixed.

| Area | Status | Gap |
|------|--------|-----|
| Portal **Commercial** (orders/quotations/contracts/invoices/debit-notes/payments) | ❌ UI | `PurchasePortalCommerceController` has **11 live endpoints** with zero frontend (`purchasePortalApi.js`, `routes.jsx`, shell all missing them). A Purchase vendor can't see any of their own commercial docs. |
| Portal **Profile / My Company** | ❌ UI | `PUT /portal/purchase/profile` exists; no nav, no page. |
| Portal **Support** | 🐞 | Dead `mailto` (B2). |
| Workforce gate scan / attendance / strikes | ❌ | Purchase has readiness + `gateDecision()` but no scan/check-in/attendance/strikes (TPV has all). |
| Worker suspend/reinstate/terminate/badge-reveal/bulk-upload | ❌ | Absent (TPV has them). |
| Approval delegation + escalation | ❌ | Purchase is index/approve/reject only. |
| Temporary-access admin lifecycle (extend/expire/convert/countdown) | 🟡 | Countdown read-only; no admin controls. |
| Two activation paths (onboarding-approve vs standalone `/purchase/vendors/{id}/approve`) | 🟡 | Verify they can't leave a vendor Active-without-onboarding or double-notify. |
| TPV↔Purchase link | 🟡 | One-directional (`vendors.purchase_vendor_id`); no back-reference from Purchase. |

---

## 6. CROSS-MODULE CONNECTIONS (Shivam's modules ↔ Vendor/TPV)

**Solidly wired (✅):** Projects, Tasks, Tickets, PPE↔Inventory, Expenses — all end-to-end
(DB scope → controller → admin panel → create form → portal read), sharing single scope
definitions so they can't drift.

**Holes (❌), = enhancement items #6/#2/#9/#10:**
- **Knowledge Base** not surfaced in any vendor/TPV dashboard.
- **Vendor/TPV → employee** assignee cascade + strict employee-level visibility — blocked by the missing employee entity.

---

## 7. RECOMMENDED IMPLEMENTATION WAVES

Ordered by value-to-effort. Each wave is independently shippable.

- **Wave 0 — Bugs (hours):** B1 `VendorService::approve` class fix, B2 Purchase portal Support page, B3 portal vendor_user_id edge.
- **Wave 1 — High-value spec/enhancement wins (days):** medical-expiry auto-block (spec-critical), Aadhaar masking, Start Work Letter PDF on approval, KB tile on dashboards (#6), Purchase portal **Commercial + Profile** wiring, onboarding tracker Blocking-Reason column, award field on the worker card.
- **Wave 2 — Vendor/TPV employees + assignee cascade + visibility (#2/#9/#10) (medium):** the new employee entity that unblocks the assignee/visibility spec.
- **Wave 3 — Automated backup (#12):** daily job → OneDrive/pCloud + 3–4 retention.
- **Wave 4 — Onboarding status machine + gates + auto-suspension:** the 11-state gate-driven machine.
- **Wave 5 — Purchase parity operational layer:** gate/attendance/strikes/worker-lifecycle for Purchase workforce; delegation/escalation; temp-access controls.
- **Wave 6+ — Governance superstructure (large, greenfield):** Doc 1 authority/audit/config, Doc 2 commercial chain, Doc 5 VRS + scorecards (clone HR scoring), Doc 6 dashboards + evidence locker + DPR/WPR/MCR, PTW/JSA, incidents/RCA/CAPA, observations, SWA, emergency drills, ESG. Reuse the building blocks in §4.

---

*Generated from a six-agent code audit. Every ❌/🟡 above has file:line evidence in the
underlying audit transcripts. This report is the checklist implementation is driven from.*
