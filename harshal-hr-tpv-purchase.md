# Module Brief — Harshal (HR / TPV / Purchase)

Rebuilding our legacy CRM ("zignls", a customized Perfex CRM/CodeIgniter fork) into Sangoe CRM (Laravel 12 + React 18/Vite), same feature set, cleaner UI, some enhancements — not a 1:1 port.

**You don't have the legacy codebase locally — that's intentional.** The legacy backup lives only on Zafar's machine (it's a compromised install and shouldn't be copied around). This brief IS the extracted spec: the feature lists, workflows, and table schemas below were pulled from a full read of the legacy code. Legacy file/module names (e.g. `modules/hr_payroll`) are cited as provenance only — you don't need to read them, and you should never port legacy PHP code directly anyway. If a workflow below is ambiguous and you need deeper legacy detail, ask Zafar to extract it rather than guessing.

This brief covers only your three owned modules: **HR, TPV, Purchase**. Zafar owns Sales/Customer/Accounts, Shivam owns Project&Task/Helpdesk/Inventory — see the "Shared foundations" section at the bottom for what you depend on / what depends on you.

**Before starting, read the two shared docs in this same folder**: `ARCHITECTURE-PRIMER.md` (how roles/tenancy/module wiring actually work — verified against the code) and `TEAM-CONVENTIONS.md` (code structure standards, git/parallel-work rules, shared-entity contracts, table-naming, definition of done). Those two are binding for everyone; this brief is your module-specific scope on top of them. It covers how roles (admin/staff/vendor/third_party_vendor/client), multi-tenancy, and module wiring actually work in this codebase today — all verified against the real code, not assumed. Short version relevant to you specifically:
- `vendor` and `third_party_vendor` are already first-class `role` values on the single shared `User` model (same table, same login, as staff/admin/client) — you don't need to invent a separate vendor auth system, just use the existing role.
- **There is no role-restriction middleware on `routes/hr.php` today** — any authenticated user of any role can currently call HR endpoints. Add `role:admin` / `role:staff` (see `routes/admin.php` for the pattern) to your HR and Purchase route groups where actions should be staff/admin-only.
- **Tenant scoping is opt-in, not automatic** — `BelongsToTenant` only auto-stamps `tenant_id` on create; every query you write must explicitly chain `->forTenant($tenantId)` or it will leak data across tenants. This matters a lot for TPV/vendor data, which is exactly the kind of thing that must never cross tenants.

---

## 1. HR

### Already built in Sangoe (Recruitment slice only)
- Models: `HrEmployee`, `HrCandidate`, `HrJobPosting`, `HrOffer`, `HrOnboarding`, `HrInterviewRound`, `HrManpowerRequest`, `HrApprovalHistory`, `HrWhatsAppLog` (`backend/app/Models/Hr/`)
- `backend/routes/hr.php`, `frontend/src/services/hrApi.js`
- React pages: `HRDashboard`, `Candidates`, `CandidateProfile`, `JobPostings`, `ManpowerRequests`, `Interviews`, `OfferLetters`, `Onboarding`, `Employees` (`frontend/src/modules/hr/`)

### Still to scope — legacy feature reference

**Payroll** (legacy: `modules/hr_payroll`, ~105 functions):
- Settings: income tax rates/rebates, earnings list, salary deductions list, insurance list, company contributions list
- Employees-for-payroll list, attendance (manual + Excel import + calculation engine)
- Deductions, commissions, income tax processing per employee/month, insurance enrollment, bonus/KPI
- Payslips: generate, configurable templates, PDF, lock/close, view v1/v2
- Reports: payslip report, income summary, insurance cost summary, charts
- Tables (legacy names, for schema reference only): `hrp_payslips`, `hrp_payslip_details`, `hrp_payslip_templates`, `hrp_earnings_list`, `hrp_salary_deductions(_list)`, `hrp_bonus_kpi`, `hrp_commissions`, `hrp_income_tax_rates/rebates/taxs`, `hrp_insurance_list`, `hrp_staff_insurances`, `hrp_employees_timesheets`, `hrp_company_contributions_list`

**Timesheets/Attendance/Leave** (legacy: `modules/timesheets`, ~5200 lines — biggest single sub-module):
- Check-in/out, IP-based validation, geofenced workplaces, GPS route tracking
- Shift management (shift types, scheduling, day-off)
- Leave management: requisitions, leave types/balances, **multi-level configurable approval chain** with email notification
- Overtime-style "additional timesheets" with separate approval flow
- Business advance payment tracking
- Own API layer existed (`Api_timesheets.php`) — suggests a companion mobile/kiosk app; consider whether v1 needs that too
- Staff QR codes for clock-in (separate from TPV's worker QR)
- Tables: `timesheets_timesheet`, `timesheets_leave`, `timesheets_requisition_leave`, `timesheets_approval_setting(_details)`, `timesheets_route(_point)`, `timesheets_workplace(_assign)`, `work_shift(_detail)`, `day_off`, `check_in_out`

**Staff profile / org chart / contracts / training** (legacy: `modules/hr_profile`, ~8000 lines — largest single controller in the whole legacy codebase):
- Organizational chart, department-level settings
- Staff contracts: e-sign, PDF, templates, auto-numbering
- Job positions: hierarchical tree, training requirements, salary scale per position
- Training programs, question forms, scoring, completion tracking
- Dependent persons (staff family), resignation/retirement workflow with checklist
- Bonus/discipline records, asset allocation to staff, generic checklist engine
- Recruitment→HR transfer bridge (already partly covered by your `HrOnboarding` model — verify)
- Bulk Excel import for staff (2 flows) with sample-file generation + error logging

**Goals / OKR** (legacy: `modules/goals` — simple; `modules/okr` — substantial, ~1350 lines):
- Goals: lightweight CRUD + assignee notify
- OKR: objectives + key results, check-ins with history, quarterly circulation periods, multi-level approval, org-chart view, superior-OKR linkage, ability to link key-results to tasks (cross-module touch with Shivam's Tasks)

---

## 2. TPV (Third-Party Vendor)

### Already built in Sangoe
Only `VendorRegisterRequest.php` / `TPVRegisterRequest.php` (validation only — `vendor_type: standard|temporary`) + placeholder pages `VendorRegisterPage.jsx` / `TPVRegisterPage.jsx`. No models, no workflow logic yet.

### Legacy vendor lifecycle to reimplement (cleaner — see warning below)

1. **Kickoff meeting** — admin schedules a pre-onboarding meeting with the vendor, generates a Minutes-of-Meeting PDF, vendor acknowledges via a public token link, attendance tracked. *(Note: Shivam is separately scoping a general "Kickoff meeting" domain for Project&Task — coordinate so this isn't built twice; likely it's one shared `KickoffMeeting` feature that both TPV and Projects can attach to.)*

2. **Vendor onboarding wizard (6 steps)**:
   - Step 1: shows kickoff MoM + ack status, lists workforce already added
   - Step 2: full company/contact profile (name, DOB, contact, company reg, social links, photo) — distinguishes `standard` vs `temporary` vendor type (temporary = reduced doc set + time-boxed access)
   - Step 3: legal document upload — standard set: company registration, PAN, insurance/WCP, GST, PF, ESIC, BOCW registration, CLR, MLWF, MSCB, Udyam certificate; temporary set: insurance/WCP, GST, LOI/WO/PO. Includes a "request document from provider" mini-workflow (emails a third party like an insurance broker to obtain a missing doc)
   - Step 4: under-review status per document, resubmit-on-rejection
   - Step 5: final confirmation (blocked until all docs approved)
   - Step 6: waiting-for-admin-approval

3. **Admin approval** — sets vendor active, generates a branded HSSE Work-Start Letter PDF, emails it. Document-level approve/reject independently drivable with remarks.

4. **Workforce registration (5-step wizard)** — vendor registers individual workers who'll access the site:
   - Step 1: worker profile (name, DOB/age, designation, skill category, Aadhar, mobile, photo), auto worker code, CSV bulk upload
   - Step 2: medical exam (internal/external), physical measurements, mental-health screening questionnaire (scored), signature capture
   - Step 3: induction/HSSE training record (trainer, duration, photo/signature/thumbprint capture)
   - Step 4: PPE issuance, checked against inventory stock (cross-module touch with Shivam's Inventory)
   - Step 5: entry card/QR badge generation — QR encodes a public scan URL

5. **Public QR gate-scan** — no-login endpoint, scans worker QR at site gate, shows color-coded pass/fail card (green=active, yellow/orange=warnings, red=terminated). **Punch/strike system**: 3 punches = automatic termination of site access — a safety-violation tracking mechanism.

6. **Compliance/HSSE checklist engine** (legacy: `modules/compliance_assurance`, more general-purpose, worth building as its own reusable engine rather than TPV-only):
   - Template builder for arbitrary checklist forms
   - Checklist instances linked polymorphically to any entity (vendor, project, etc.), with a public token for unauthenticated fill-in
   - **3-tier signature approval chain**: issuer → manager → head, each with remarks; status machine draft→assigned→submitted→manager_approved→approved (or rejected at any tier)
   - Captures selfie, GPS, IP on submission
   - Separate "contractor registry" existed with overlapping statutory-document fields (GST/PF/WCP/MLWF/BOCW/CLR) — **reconcile this into one document model with the vendor onboarding doc set, don't build two.**

### ⚠️ Legacy data-model warning
The legacy vendor implementation is fragmented across multiple half-migrated table/controller sets (`tblvendors` vs `tbl_vendors`, `Thirdparty_vendor.php` vs `Thirdparty_vendor2.php`, duplicate onboarding-approval logic in two places). This is a sign of iterative rebuilding, not intentional design — **design one clean `Vendor` model in the new stack, don't replicate the duplication.**

### Vendor record — related entities to plan for
- Multiple contacts per vendor (with primary flag)
- Internal staff assigned to manage a vendor account (account-manager pattern — same shape as Purchase's vendor-admin assignment, see below)
- In-app notification log to vendor (approved/rejected/hold/active events)
- A vendor detail page had many CRM-style tabs attached (projects, invoices, payments, tasks, tickets, notes, secure "vault" for credentials) — vendors are modeled like a client-ish CRM entity with TPV-specific safety tabs layered on top

---

## 3. Purchase

### Already built in Sangoe
Nothing — no controllers/models/routes/pages exist yet. Green-field.

### Legacy feature reference (`modules/purchase` — large standalone module)
- **Vendor management** (purchase-specific — legacy table `pur_vendor`, **separate from TPV's vendor table** — see decision below): CRUD, contacts, categories, item catalog, vendor self-service portal with its own login
- **Purchase Requests (PR)**: line items, configurable multi-level approval chain, PDF, share with vendors for quotation, "copy PR → PO"
- **Quotations**: vendor-submitted or admin-created against a PR, compare-quotation PDF, copy → PO
- **Purchase Orders (PO)**: full lifecycle, payments, delivery/goods-received tracking, PDF, project-linked POs
- **Contracts**: vendor contracts with e-signature
- **Purchase invoices**: from PO, invoice payments, PDF
- **Debit notes / order returns**: request → approval → refund workflow
- **Item/commodity catalog**: barcodes, unit types, warehouse sub-groups (cross-module touch with Shivam's Inventory)
- **Vendor self-service portal**: profile, quotations, POs/contracts/invoices/payments, budget requests (own approval flow), order returns

### 🔑 Decision for you to make
Purchase's vendor concept (`pur_vendor`) and TPV's vendor concept (`tblvendors`) are architecturally separate in legacy — they don't share a table, despite heavy overlap in contacts/documents/portal-login patterns. **Since you own both areas, strongly recommend unifying into one `Vendor` entity** in the new stack instead of carrying the legacy split forward. Decide this before either sub-area starts building models.

---

## Shared foundations — dependencies to coordinate on

- **Client/Customer entity** is owned by Zafar (Sales/Customer/Accounts) and is a foundational entity other modules FK into. It doesn't block you directly, but if TPV vendors ever need to relate to clients/projects, confirm the shape with Zafar first.
- **Staff/user identity** should stay the single existing Sangoe auth/user model — don't re-own staff identity inside HR; HR should extend/reference the shared user, not fork it.
- **Vendor entity (yours)** is a dependency for Shivam's Project&Task "Kickoff" feature and "vendors on projects"/"TPV assignees on tasks" — let Shivam know once your `Vendor` model shape is settled so they don't build a conflicting stub.
- **PPE/inventory stock check** in workforce registration depends on Shivam's Inventory module — coordinate on what a minimal stock-check API looks like, or stub it initially.

## Working conventions (apply to all your modules)
- Backend: thin Controller → Service (business logic + logging) → Model/Repository, FormRequests for validation — matches the pattern already established in Sales/HR.
- Frontend: `useToast()` hook + `ConfirmDialog` component for destructive actions — no fake `showToast('Deleted!')` stubs.
- Verify every backend endpoint via real HTTP calls against the dev server, not just `php artisan test`/tinker.
- Clean, efficient code — no premature abstractions, no unused scaffolding for features not yet needed.

## Your space — add here
- [ ] Your own sequencing/priority across HR / TPV / Purchase
- [ ] Anything you want to add beyond legacy scope
- [ ] Anything above you think should be cut/simplified for v1
