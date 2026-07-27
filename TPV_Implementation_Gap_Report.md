# Third Party Vendor Module — Implementation Gap Report

**Baseline spec:** `Third_Party_Vendor_Onboarding_PRD_v1.0.md`
**Reviewed against:** current codebase (backend `app/`, `routes/tpv.php`, `database/migrations/`; frontend `modules/tpv/`, `pages/vendor-portal/`)
**Date:** 2026-07-22
**Nature:** Read-only analysis. No code was modified.

## Legend
- ✅ **Completed** — implemented and functional, matching the PRD (minor naming aside).
- 🟡 **Needs Enhancement** — partially implemented; exists but does not yet meet the PRD.
- 🔴 **Missing** — not present in the code.

## Headline
The **backend onboarding engine, statutory-document lifecycle, and workforce module are the strong, largely-complete core**. The **vendor-facing six-step wizard experience** (Kickoff PDF acceptance, richer profile, declaration/completion capture, four-outcome approval), the **Temporary TPV access system**, and the **enterprise layers** (Registration Number generation, document versioning, multi-level approval, session management, system configuration, reports, activity-timeline API) are the primary gaps.

| PRD Area | Status |
|----------|--------|
| §2/§4.1 Login (auth) | ✅ Completed |
| §14 Session Management (timeout / force-logout / multi-device / Remember-Me) | 🔴 Missing |
| §4.2 Step 1 — Kickoff **PDF** + acceptance capture | 🔴 Missing (a Kickoff **meeting** engine exists instead) |
| §4.3 Step 2 — Company Profile (Bank / Authorized Person / GST / PAN / IFSC validation) | 🟡 Needs Enhancement |
| §4.4 Step 3 — Legal Documents | ✅ Completed |
| §4.5 Step 4 — Under Review (auto-refresh / refresh-now) | 🟡 Needs Enhancement |
| §4.6 Step 5 — Final Confirmation (declaration + completion capture) | 🟡 Needs Enhancement |
| §4.7 Step 6 — Final Approval (reject / hold / release / Registration Number / 4 outcomes) | 🟡 Needs Enhancement |
| §4.8/§18 Dashboard & Widgets (vendor-facing) | 🟡 Needs Enhancement |
| §4.9 Workforce Module | ✅ Completed |
| §2.1/§3.1/§4.10–4.14/§6.13/§7.8 Temporary TPV (access window / countdown / extend / convert) | 🔴 Missing |
| §6 Database (new columns/tables) | 🟡 Needs Enhancement |
| §7 API (new endpoints) | 🟡 Needs Enhancement |
| §9 Notifications (portal centre / reminders / templates) | 🟡 Needs Enhancement |
| §10 Audit Logs | 🟡 Needs Enhancement |
| §11 Permissions | ✅ Completed |
| §12 UI/UX (temporary banner / countdown / confetti) | 🟡 Needs Enhancement |
| §15 Document Versioning | 🔴 Missing |
| §16 Multi-Level Approval / Delegation / Escalation | 🔴 Missing |
| §17 Registration Number Rules (generation + config) | 🔴 Missing |
| §19 Search, Filters & Sorting | 🟡 Needs Enhancement |
| §20 Activity Timeline (dedicated endpoint/UI) | 🟡 Needs Enhancement |
| §21 System Configuration | 🔴 Missing |
| §22 Reports | 🔴 Missing |
| §23 Non-Functional Requirements | 🟡 Needs Enhancement |

---

# 1. What Is Completed

### 1.1 Authentication & Login (§2, §4.1) ✅
- Sanctum bearer auth, role-scoped login (`third_party_vendor`), 30-day token, single active session (prior tokens revoked).
- Login gate blocks pending/suspended/rejected/expired accounts with specific messages (`AuthService`).
- Portal middleware `EnsureVendorPortalAccess` resolves the ambient vendor from the token and re-checks role + active + expiry per request.

### 1.2 Step 3 — Legal Documents (§4.4) ✅
- Full 12-type statutory set with per-vendor-type mandatory sets (`VendorDocument`, `STANDARD_SET`/`TEMPORARY_SET`).
- Upload, replace, preview, download, delete; admin review (approve/reject with remark); resubmission.
- File rules match PRD: **pdf/jpg/jpeg/png ≤ 8 MB**, stored on the private `vendor_docs` disk with randomized names.
- Checklist matrix with required/uploaded/approved/rejected/pending counts and a `complete` flag (`VendorDocumentService::checklist`).

### 1.3 Workforce Module (§4.9) ✅
- Worker onboarding wizard: Profile → Medical → Induction → PPE → Badge (`TpvWorkerService`, `TpvWorkerController`).
- Activation gate enforced exactly as PRD: vendor **Active**, age ≥ 18, passing medical, passed induction, mandatory PPE; issues badge number + QR token.
- Lifecycle: activate/suspend/reinstate/terminate; QR revocation on termination; safety-strike thresholds; gate scans + daily attendance.

### 1.4 Permissions & Tenancy (§11) ✅
- RBAC via role middleware: management `admin,staff`; document review and onboarding approval **admin-only**.
- Row-level multi-tenancy (`tenant_id` on every table) with tenant-guarded route-model binding (404 across tenants).

### 1.5 Audit Infrastructure (§10, partial) ✅ base
- `AuditLog` + `AuditLogService` + `audit_logs` migration + `Auditable` trait are present and wired.
- Existing actions: `TPV Onboarding Started`, `Onboarding Step Changed`, `Profile Saved`, `Onboarding Submitted`, `Onboarding Approved`, `Resubmission Requested`, `Document Uploaded/Approved/Rejected/Resubmitted`, vendor status changes.

### 1.6 Design System (§12, base) ✅
- kit3d glassmorphism components, status pills, gradient buttons, skeleton/spinner loaders, responsive tables — the visual foundation the PRD describes.

---

# 2. What Needs Enhancement

### 2.1 Step 2 — Company Profile (§4.3) 🟡
**Current:** `tpv_onboardings.profile` (JSON) stores `contact_person, designation, dob, emergency_contact, emergency_phone, estimated_workforce, linkedin, registered_address, scope_of_work, website` (`SaveOnboardingProfileRequest`). GST/PAN live on the vendor master.
**Gap vs PRD:**
- No structured **Bank Details** section (`account_holder, bank_name, account_number, ifsc, branch, account_type`) — PRD wants a `bank` object and/or `vendor_bank_accounts` table.
- No structured **Authorized Person** block.
- No in-form **GST/PAN** capture with **GSTIN checksum**, **PAN pattern**, **IFSC**, and **account-number** validation.
- No **Company Details** sub-object persisted in the profile (company name is only on the master).

### 2.2 Step 4 — Under Review (§4.5) 🟡
**Current:** checklist returns counts and a completeness flag; the wizard renders counters/badges.
**Gap:** no **auto-refresh (polling)** and no **"Refresh Now"** control in the review UI; the PRD requires both. A visible **progress bar** and dynamic **status banner** should be confirmed/added.

### 2.3 Step 5 — Final Confirmation (§4.6) 🟡
**Current:** `TpvOnboardingService::submit` sets status `Submitted` and vendor `Pending_Approval`; blocks unless profile complete and all required docs approved.
**Gap:** no **declaration checkbox** capture and no **completion metadata** columns (`declaration_accepted_at`, `onboarding_complete`, `completed_at`, `completed_ip`, `completed_browser`, `completed_device`).

### 2.4 Step 6 — Final Approval (§4.7) 🟡
**Current:** `approve` (→ vendor Active) and `requestResubmit` (→ `Resubmit_Required`) only.
**Gap:**
- No **reject**, **hold**, or **release** transitions (PRD status model needs `On Hold`; enum has `Rejected` but it is never set).
- No **Registration Number generation** on approval (see §2.9).
- No four-outcome vendor UI (**Pending / Approved / Hold / Rejected**), success hero, **confetti**, or Go-to-Dashboard / Add-Workforce / Logout actions.

### 2.5 Dashboard & Widgets (§4.8, §18) 🟡
**Current:** an admin TPV roll-up dashboard exists; the vendor portal shows a read-only onboarding progress card + document upload page.
**Gap:** the enumerated **vendor Dashboard widgets** — Pending Tasks, Document Status, Countdown, Notifications, Recent Activity, Workforce Summary — are not assembled as specified.

### 2.6 Notifications (§9) 🟡
**Current:** `NotificationService::email()` is live; `whatsapp()`/`sms()` are queued **stubs**; a multi-channel welcome fires on activation.
**Gap:** no **portal notification centre**, no **temporary-expiry reminder schedule** (7d/3d/1d/6h), no per-event/per-channel **templates**, and live WhatsApp/SMS providers.

### 2.7 Audit Logs (§10) 🟡
**Gap actions not yet emitted:** `PDF Viewed`, `PDF Downloaded`, `PDF Printed`, `Kickoff Accepted`, `Declaration Accepted`, `Onboarding Completed`, `Vendor Rejected`, `Vendor On Hold`, `Document Version Created/Restored`, all session actions, all Temporary-TPV actions, `Registration Number Overridden`, `Setting Updated`, `Report Generated`.

### 2.8 Search, Filters & Sorting (§19) 🟡
**Current:** the onboarding list filters by `status` and `vendor_id`.
**Gap:** no **search** (company/vendor code/registration number/email/GST/PAN), no **document** search/filter (status, expiry window, type), no **sort** options, no temporary-access-status filter.

### 2.9 Activity Timeline (§20) 🟡
**Current:** audit logs are eager-loaded on the onboarding `show()`.
**Gap:** no dedicated **timeline endpoint** (`GET …/timeline`), no ordered event set, category filtering, or vendor-facing timeline UI.

### 2.10 Database & API surface (§6, §7) 🟡
Core tables/endpoints exist; the deltas are enumerated in §4 below.

### 2.11 Non-Functional (§23) 🟡
Tenancy, RBAC, private storage, hashing, and the audit trail are in place. **Backup cadence, ≥ 7-year audit retention, disaster-recovery (RPO/RTO), and formal browser/accessibility (WCAG 2.1 AA) targets** are not codified.

---

# 3. What Is Missing

### 3.1 Step 1 — Kickoff PDF & Acceptance (§4.2) 🔴
The current "kickoff" is a **meeting** engine (`KickoffMeeting`, agenda/attendees/MOM), **not** the PRD's Step-1 **PDF viewer** with View/Download/Print/Zoom and an **acknowledgement checkbox**. Missing entirely on `tpv_onboardings`:
- `kickoff_pdf_path`, `acknowledged`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`.
- `GET …/kickoff` (stream) and `POST …/kickoff/accept` endpoints.

### 3.2 Temporary TPV Access System (§2.1, §3.1, §4.10–4.14, §6.13, §7.8) 🔴
A basic notion exists (`users.tpv_type`, `users.access_expires_at` set at activation), but the PRD's **admin-created, time-boxed access model is absent**:
- Missing vendor columns: `is_temporary`, `access_start_at`, `access_expires_at`, `access_status`, `access_extended_at`, `access_extended_by`, `extension_reason`, `converted_to_permanent_at`, `converted_by`, `temporary_created_by`, `validity_days`.
- Missing endpoints: create temporary (`POST /tpv/vendors/temporary`), `…/access/extend`, `…/access/expire`, `…/access/convert`, `GET /tpv/access/countdown`, `…/access/status`.
- Missing UI: sticky countdown banner (all screens), colour bands, expiry screen, extend/convert dialogs, expiry-warning popups.
- Missing enforcement: middleware expiry lock (`403 access_expired`), session termination on expiry, reminder scheduler.

### 3.3 Registration Number Rules (§17, §6.11) 🔴
`vendors.registration_number` exists as a **free-text nullable field** only. Missing: **atomic generation** of `TPV-YYYY-NNNNN` on approval, tenant-wise sequential counter, financial-year option, configurable prefix/padding, and manual-override-with-audit restrictions.

### 3.4 Document Versioning (§15) 🔴
Replace/resubmit **overwrite** the current file. Missing: `vendor_document_versions` table, version history, per-version download, restore, and `Document Version Created/Restored` audit.

### 3.5 Multi-Level Approval / Delegation / Escalation (§16) 🔴
Only single-step `approve` exists. Missing: `onboarding_approvals` + `approval_delegations` tables, level-aware approval chain, delegation, SLA escalation, and the approval-history panel.

### 3.6 Session Management (§14) 🔴
Beyond single-session token issuance, missing: idle **timeout** with warning, **Force Logout**, **multi-device policy**, **Remember Me**, the `user_sessions` registry, and the session APIs/audit.

### 3.7 System Configuration (§21) 🔴
No `tenant_settings` table or admin settings APIs. File size/types and thresholds are **hardcoded** (8 MB; pdf/jpg/jpeg/png). Missing configurability for document rules, countdown thresholds, registration prefix/year type, temporary defaults, approval mode/SLA, and notification templates.

### 3.8 Reports (§22) 🔴
No report endpoints for Pending / Approved / Rejected / Temporary / Expired vendors or Workforce Status, and no CSV/PDF export.

---

# 4. Concrete Database & API Deltas

## 4.1 New columns on `tpv_onboardings`
`kickoff_pdf_path`, `acknowledged`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`, `declaration_accepted_at`, `onboarding_complete`, `completed_at`, `completed_ip`, `completed_browser`, `completed_device`, `registration_number`, `hold_reason`. *(Add `On Hold` to the status set; wire `Rejected`.)*

## 4.2 New columns on `vendors` (Temporary access — §6.13)
`is_temporary`, `access_start_at`, `access_expires_at`, `access_status`, `access_extended_at`, `access_extended_by`, `extension_reason`, `converted_to_permanent_at`, `converted_by`, `temporary_created_by`, `validity_days`.

## 4.3 New tables
`vendor_bank_accounts`, `vendor_document_versions`, `onboarding_approvals`, `approval_delegations`, `user_sessions`, `tenant_settings`. *(Optional: normalize profile bank/authorized-person.)*

## 4.4 New / changed endpoints
- Kickoff: `GET /tpv/onboarding/{id}/kickoff`, `POST …/kickoff/accept`.
- Approval: `POST …/reject`, `POST …/hold`, `POST …/release`, `POST …/escalate`, `GET …/approvals`, `POST /admin/approval-delegations`.
- Temporary: `POST /tpv/vendors/temporary`, `…/access/extend|expire|convert`, `GET /tpv/access/countdown`, `…/access/status`.
- Documents: `GET …/versions`, `…/versions/{v}/download`, `POST …/versions/{v}/restore`.
- Timeline: `GET /tpv/onboarding/{id}/timeline`.
- Sessions: `GET/DELETE /auth/sessions`, `POST /admin/users/{id}/force-logout`, `POST /auth/heartbeat`.
- Settings: `GET /admin/settings`, `PUT /admin/settings/{key}`.
- Reports: `GET /tpv/reports/{pending|approved|rejected|temporary|expired|workforce}`.
- Search: query params on `GET /tpv/onboarding` and `…/documents`.

---

# 5. Suggested Implementation Roadmap (priority order)

**Phase 1 — Close the core vendor onboarding loop (high value, contained):**
1. Kickoff PDF + acceptance capture (§4.2) — columns + 2 endpoints + UI.
2. Approval completeness (§4.7) — add `reject`/`hold`/`release`, `On Hold` status.
3. Registration Number generation (§17) — atomic `TPV-YYYY-NNNNN` on approval.
4. Step 5 declaration + completion metadata (§4.6).
5. Step 6 four-outcome vendor UI + confetti (§4.7).

**Phase 2 — Temporary TPV (self-contained feature slice):**
6. `vendors` access columns + create/extend/expire/convert endpoints (§7.8).
7. Countdown API + middleware expiry lock + banner/dialogs/popups (§4.10–4.14).
8. Reminder scheduler (§9.1).

**Phase 3 — Profile depth & document robustness:**
9. Step 2 Bank/Authorized-Person/GST/PAN + IFSC/account/GSTIN/PAN validation (§4.3).
10. Document versioning (§15).
11. Step 4 auto-refresh + Refresh-Now + progress bar (§4.5).

**Phase 4 — Enterprise layers:**
12. Session management (§14).
13. Multi-level approval / delegation / escalation (§16).
14. System configuration (§21) — unlock the hardcoded file rules & thresholds.
15. Search/filters/sorting (§19) + Activity Timeline endpoint (§20).
16. Reports (§22) + dashboard widget assembly (§18).
17. Notification centre + templates + live WA/SMS (§9).
18. NFR hardening: audit retention, backup/DR runbooks, WCAG 2.1 AA pass (§23).

---

# 6. Notes
- Every gap is **additive** to the existing schema and services; nothing above requires reworking the completed Documents or Workforce modules.
- The completed Kickoff **meeting** engine is a separate, valid feature; the PRD's Step-1 Kickoff **PDF** is a distinct addition and does not replace it.
- `Resubmit` appears as a document badge/status in the PRD while the code transitions rejected → `Under_Review` on resubmission; align naming when versioning lands.

*End of report — TPV_Implementation_Gap_Report.md*
