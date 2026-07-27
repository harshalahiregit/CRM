# Third Party Vendor (TPV) Onboarding — Product Requirements Document

**Document:** `Third_Party_Vendor_Onboarding_PRD_v1.0.md`
**Product:** Sangoe CRM
**Module:** Third Party Vendor (TPV)
**Version:** 1.0 — Final Product Specification
**Platform:** Laravel (Sanctum API, MySQL) · React (Vite, react-router-dom) · Tailwind + kit3d design system
**Status:** Authoritative single source of truth for development.

> This document is the **final product specification** for the Third Party Vendor Onboarding module. It defines exactly how the product shall be built and behave. It is written as build instructions for the engineering, design, and QA teams.

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Actors](#2-actors)
3. [Complete End-to-End Workflow](#3-complete-end-to-end-workflow)
4. [Every Screen Specification](#4-every-screen-specification)
   - 4.1 [Screen — Third Party Vendor Login](#41-screen--third-party-vendor-login)
   - 4.2 [Screen — Step 1: Kickoff PDF](#42-screen--step-1-kickoff-pdf)
   - 4.3 [Screen — Step 2: Company Profile](#43-screen--step-2-company-profile)
   - 4.4 [Screen — Step 3: Legal Documents](#44-screen--step-3-legal-documents)
   - 4.5 [Screen — Step 4: Under Review](#45-screen--step-4-under-review)
   - 4.6 [Screen — Step 5: Final Confirmation](#46-screen--step-5-final-confirmation)
   - 4.7 [Screen — Step 6: Final Approval](#47-screen--step-6-final-approval)
   - 4.8 [Screen — Dashboard](#48-screen--dashboard)
   - 4.9 [Screen — Workforce Module](#49-screen--workforce-module)
   - 4.10 [Global Element — Temporary Access Banner & Countdown](#410-global-element--temporary-access-banner--countdown)
   - 4.11 [Screen — Temporary Access Expired](#411-screen--temporary-access-expired)
   - 4.12 [Dialog — Extend / Renew Temporary Access](#412-dialog--extend--renew-temporary-access)
   - 4.13 [Dialog — Convert Temporary to Permanent](#413-dialog--convert-temporary-to-permanent)
   - 4.14 [Popup — Expiry Warning](#414-popup--expiry-warning)
5. [Controller Flow](#5-controller-flow)
6. [Database Design](#6-database-design)
7. [API Specification](#7-api-specification)
8. [Business Rules](#8-business-rules)
9. [Notification Flow](#9-notification-flow)
10. [Audit Logs](#10-audit-logs)
11. [Permissions](#11-permissions)
12. [UI / UX Guidelines](#12-ui--ux-guidelines)
13. [Acceptance Criteria](#13-acceptance-criteria)
14. [Session Management](#14-session-management)
15. [Document Versioning](#15-document-versioning)
16. [Admin Approval Workflow (Advanced)](#16-admin-approval-workflow-advanced)
17. [Registration Number Rules](#17-registration-number-rules)
18. [Dashboard Widgets](#18-dashboard-widgets)
19. [Search, Filters & Sorting](#19-search-filters--sorting)
20. [Activity Timeline](#20-activity-timeline)
21. [System Configuration](#21-system-configuration)
22. [Reports](#22-reports)
23. [Non-Functional Requirements](#23-non-functional-requirements)

---

# 1. Introduction

## 1.1 Purpose
The Third Party Vendor (TPV) module onboards external vendors and contractors into the Sangoe CRM through a guided, self-service, six-step workflow. The vendor logs in, acknowledges a Kickoff document, completes their company profile, uploads legal documents, tracks the review of those documents, confirms a declaration, and receives a final approval decision. On approval the system issues a **Registration Number**, activates the vendor account, and unlocks the vendor **Dashboard** and **Workforce** modules.

## 1.2 Goals
- Deliver a clear, resumable, six-step onboarding for third party vendors.
- Enforce statutory document verification before activation.
- Produce a fully auditable, tenant-isolated record of every action.
- Issue a unique Registration Number in the format `TPV-YYYY-NNNNN` on approval.
- Unlock the Dashboard and Workforce modules only after approval and activation.

## 1.3 Scope
Login and session; the six-step onboarding wizard (Kickoff PDF, Company Profile, Legal Documents, Under Review, Final Confirmation, Final Approval); the admin review and approval console; the vendor Dashboard; and the Workforce module (worker onboarding, medical, induction, PPE, QR badge). All validations, business rules, controller flow, database logic, API contracts, notifications, audit logging, permissions, UI/UX, and acceptance criteria are specified herein.

## 1.4 Definitions
- **TPV** — Third Party Vendor.
- **Onboarding** — the six-step workflow instance for one vendor.
- **Mandatory documents** — the statutory documents required for the vendor's type.
- **Registration Number** — the unique identifier issued on approval (`TPV-YYYY-NNNNN`).
- **Tenant** — the organisation that owns the data (row-level multi-tenancy).
- **Temporary TPV** — a third party vendor granted time-boxed access created by Admin/Staff, bounded by an access start and expiry date and a validity window.
- **Validity Window** — the number of days a Temporary TPV may operate (1, 3, 7, 15, or custom).
- **Countdown** — the live remaining-time indicator shown on every screen for a Temporary TPV.
- **Conversion** — the admin action that promotes a Temporary TPV to a Permanent TPV, removing expiry and issuing a Registration Number.

## 1.5 Temporary vs Permanent Third Party Vendors
The module supports **two modes** of the same Third Party Vendor actor. Both traverse the identical six-step onboarding; they differ only in access lifetime and the controls around it.

| Aspect | Permanent TPV | Temporary TPV |
|--------|---------------|---------------|
| Access lifetime | Non-expiring | Time-boxed between `access_start_at` and `access_expires_at` |
| Created by | Self-registration or Admin/Staff | **Admin / Staff only** |
| Credentials | Vendor-chosen at registration | System-generated temporary credentials, emailed on creation |
| Countdown | None | Live countdown on every screen |
| Expiry | None | All access terminated at zero |
| Registration Number | Issued on approval | Issued on approval **and** on conversion to Permanent |
| Renewal | N/A | Admin may extend / reset / change expiry with a reason |
| Promotion | N/A | Admin may **Convert to Permanent** (removes expiry, preserves history) |

Temporary TPV is a **core, first-class workflow**: a Temporary vendor completes the same Kickoff → Profile → Documents → Review → Confirmation → Approval flow, but under a persistent, colour-coded countdown, hard expiry enforcement, renewal, and a one-click conversion path to Permanent.

---

# 2. Actors

| Actor | Role Key | Description |
|-------|----------|-------------|
| **Super Admin** | `admin` (elevated) | Highest authority. All Admin capabilities plus configuration of document sets, notification templates, registration-number rules, and tenant settings. |
| **Admin** | `admin` | Reviews documents, approves/rejects/holds onboarding, issues Registration Numbers, manages vendors and workforce. |
| **Staff** | `staff` | Assists onboarding, uploads and manages documents on behalf of vendors, prepares submissions. Cannot grant final approval, rejection, or hold. |
| **Third Party Vendor** | `third_party_vendor` | Logs in, completes Steps 1–6, manages own profile and documents, resubmits rejected documents, and — after approval — uses the Dashboard and Workforce module. |

**Role hierarchy:** Super Admin ⊇ Admin ⊇ Staff (management), with Third Party Vendor as the external self-service actor. Approval authority belongs to Admin and Super Admin only.

## 2.1 Third Party Vendor Modes — Permanent & Temporary
The **Third Party Vendor** actor operates in one of two modes. Mode governs access lifetime, not the onboarding steps.

**What a Temporary TPV is:** a third party vendor whose portal access is time-boxed. The account is created by Admin/Staff with an explicit **Access Start Date**, **Access Expiry Date**, and **Validity** window, and is issued **system-generated temporary credentials**. The vendor completes the full six-step onboarding while a live countdown is displayed on every screen; access is hard-terminated on expiry unless an admin extends it or converts the vendor to Permanent.

**Who can create a Temporary TPV:**
- **Admin** and **Super Admin** — create, extend, force-expire, and convert.
- **Staff** — create and extend Temporary TPVs; **cannot** convert to Permanent or approve onboarding.
- A **Third Party Vendor cannot** self-register as Temporary; temporary access is always administrator-granted.

**How it differs from a Permanent TPV:** identical onboarding steps and validations, but a Temporary TPV additionally carries `is_temporary = 1`, an access window (`access_start_at` → `access_expires_at`), an `access_status`, a persistent countdown banner, hard expiry enforcement, renewal controls, and a conversion path. Conversion to Permanent removes the temporary flag and expiry, issues a Registration Number, and preserves all onboarding and audit history.

---

# 3. Complete End-to-End Workflow

```
Third Party Vendor Login
        │
        ▼
Step 1 — Kickoff PDF
  • View · Download · Print · Zoom
  • Tick "I have read and understood the Kickoff Document."
  • System saves: acknowledged=1, acknowledged_at, acknowledged_ip,
    acknowledged_browser, acknowledged_device
  • Continue enabled
        │
        ▼
Step 2 — Company Profile
  • Company · Contact · Authorized Person · Bank · GST · PAN · Registered Address
  • Validate GST, PAN, IFSC, Account Number
  • Save Draft / Save & Continue
        │
        ▼
Step 3 — Legal Documents
  • Upload · Replace · Preview · Download · Delete
  • Status: Pending / Approved / Rejected / Resubmit
  • Progress indicator · PDF/PNG/JPG/JPEG · max 8 MB
        │
        ▼
Step 4 — Under Review
  • Total / Approved / Pending / Rejected · Progress % · Admin Remarks
  • Refresh · Auto Refresh
  • Continue disabled until ALL mandatory documents approved
  • Rejected documents can be re-uploaded
        │
        ▼
Step 5 — Final Confirmation
  • Company Summary · Profile Summary · Document Summary
  • Declaration + mandatory checkbox
  • Finish Onboarding
  • System saves: onboarding_complete, completed_at, completed_ip,
    completed_browser, completed_device
        │
        ▼  (redirect)
Step 6 — Final Approval  →  Pending | Approved | Hold | Rejected
        │  (on Approved)
        ▼
Dashboard  (visible only after approval)
        │
        ▼
Workforce Module
  • Add Worker · Medical · Induction · PPE · QR Badge · Worker Ready
```

**Onboarding status model:**
`Draft → In Progress → Submitted → Under Review → Approved | Rejected | On Hold`
`Rejected → Under Review` (after resubmission) · `On Hold → Under Review` (after admin release)

**Document status model:**
`Pending → Approved | Rejected` · `Rejected → Resubmit → Pending` · `Approved → Expired` (on validity lapse)

## 3.1 Temporary TPV Access Overlay
A Temporary TPV traverses the **same** six-step onboarding, overlaid with a bounded access lifecycle enforced on every request and surfaced by a persistent countdown.

```
Admin/Staff creates Temporary TPV
  • set Access Start Date, Access Expiry Date, Validity (1/3/7/15/custom)
  • system generates temporary credentials → emails login → audit "Temporary TPV Created"
        │
        ▼
access_status = Active   (countdown running on every screen)
        │
        ├─ Login → Step 1 → … → Step 6 → Dashboard → Workforce   (all under the countdown)
        │
        ├─ Admin Extend/Renew → reset countdown → audit "Temporary Access Extended" → notify
        │
        ├─ Countdown thresholds → reminders at 7d / 3d / 1d / 6h
        │
        ▼
Countdown reaches 0
  • access_status = Expired
  • terminate all sessions · block login · block APIs · block uploads · block edits · block workforce
  • show "Your temporary access has expired. Please contact your administrator."
        │
        ▼  (either path)
Admin Extend → access_status = Active (new window)      Admin Convert to Permanent → is_temporary = 0,
                                                        expiry removed, Registration Number issued,
                                                        permanent access enabled, history preserved
```

**Access status model:** `Active → Expiring (<24h) → Expired`; `Expired → Active` (on extension); `Active/Expired → Converted` (on conversion to Permanent).

---

# 4. Every Screen Specification

> The following template is applied to every screen: **Purpose · UI Layout · Components · Cards · Buttons · Icons · Badges · Validation · Business Rules · Controller Flow · API Flow · Database Updates · Notifications · Audit Logs · Navigation · Success State · Error State · Edge Cases.**

---

## 4.1 Screen — Third Party Vendor Login

**Purpose:** Authenticate a third party vendor and route them to their current onboarding step (or Dashboard if already approved).

**UI Layout:** Split layout — a branded left panel (product identity, feature highlights) and a right authentication card centered vertically. Fully responsive; the left panel collapses on small screens.

**Components:** Email input, Password input, Show/Hide password toggle, Remember Me (optional), Login button, "Forgot password?" link, "Register" link, error banner region.

**Cards:** Single authentication card (glassmorphism, radius 16).

**Buttons:** **Login** (primary gradient), **Register** (link), **Forgot Password** (link).

**Icons:** Mail, Lock, Eye/EyeOff, ShieldCheck (branding).

**Badges:** None.

**Validation:**
- Email: required, valid email format.
- Password: required.
- Server rejects invalid credentials with `401`.

**Business Rules:**
- Authenticate against `role = third_party_vendor`.
- Issue a 30-day Bearer token; revoke any prior token (single active session).
- Deny login for accounts that are pending, suspended, rejected, or expired, each with a specific message.
- On success, route to the wizard resumed at `current_step`, or to the Dashboard when onboarding is `Approved`.

**Controller Flow:** `AuthController@login` → `AuthService::login` → validate credentials → assert account can log in → issue token → return user.

**API Flow:** `POST /api/auth/login` → `200 { access_token, token_type, user }`.

**Database Updates:** New `personal_access_tokens` row; prior tokens deleted. `users.last_login_at` set (if present).

**Notifications:** None on login. (A new-device sign-in alert may be dispatched by policy.)

**Audit Logs:** `Login` (actor, IP, browser, device).

**Navigation:** → Wizard (current step) or Dashboard. Links to Register and Forgot Password.

**Success State:** Redirect to the resumed step; toast "Welcome back".

**Error State:** Inline red banner with the exact server message; fields preserved.

**Edge Cases:**
- Expired temporary access → "Your temporary access has expired. Contact your administrator."
- Suspended/rejected account → specific message, no token issued.
- Concurrent login elsewhere invalidates the prior session.

---

## 4.2 Screen — Step 1: Kickoff PDF

**Purpose:** Present the Kickoff document, let the vendor read it in full (view, download, print, zoom), and capture an explicit acknowledgement before the vendor may continue.

**UI Layout:** Full-width document viewer occupying the main pane, a floating toolbar (zoom, print, download), and a footer bar containing the acknowledgement checkbox and the Continue button. The stepper is shown at the top.

**Components:**
- **PDF Viewer** (embedded) with page navigation.
- **Toolbar:** Zoom In, Zoom Out, Fit-to-width, Print, Download.
- **Acknowledgement checkbox:** "I have read and understood the Kickoff Document."
- **Continue** button.

**Cards:** Viewer card; acknowledgement card in the footer.

**Buttons:** **Zoom In**, **Zoom Out**, **Print**, **Download PDF**, **Continue** (primary, disabled until acknowledged).

**Icons:** FileText, ZoomIn, ZoomOut, Printer, Download, CheckCircle.

**Badges:** "Acknowledged" (green) once accepted, with timestamp.

**Validation:**
- The acknowledgement checkbox is **required** to enable Continue.
- The server rejects a Continue request without a recorded acknowledgement (`422`).

**Business Rules:**
- The vendor may **View**, **Download**, **Print**, and **Zoom** the PDF.
- Ticking the checkbox records `acknowledged = 1`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`.
- Continue is enabled only after acknowledgement is recorded.
- On acknowledgement, onboarding status becomes `In Progress` and `current_step` advances to `2`.
- Re-entering Step 1 after acknowledgement shows the accepted state (checkbox ticked, timestamp displayed); acknowledgement is immutable.

**Controller Flow:** `TpvOnboardingController@kickoff` (stream PDF) · `TpvOnboardingController@acceptKickoff` → validate → set acknowledgement fields → advance step → write audit → return onboarding.

**API Flow:**
- `GET /api/tpv/onboarding/{id}/kickoff` → PDF stream.
- `POST /api/tpv/onboarding/{id}/kickoff/accept` → `200 { onboarding }`.

**Database Updates:** `tpv_onboardings`: `kickoff_pdf_path` (read), `acknowledged = 1`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`, `status = In Progress`, `current_step = 2`.

**Notifications:** Portal confirmation "Kickoff acknowledged."

**Audit Logs:** `PDF Viewed`, `PDF Downloaded`, `PDF Printed`, `Kickoff Accepted` (with IP, browser, device).

**Navigation:** → Step 2 on Continue. Back is disabled (Step 1 is the entry point).

**Success State:** Green "Acknowledged" badge with timestamp; Continue enabled; advance to Step 2.

**Error State:** Attempting Continue without acknowledgement shows an inline message "Please acknowledge the Kickoff document to continue."

**Edge Cases:**
- Download/print blocked by browser → the viewer still allows reading; acknowledgement remains available.
- Repeated acknowledgement requests are idempotent (first write wins; timestamp preserved).
- PDF fails to load → error card with a Retry action; acknowledgement disabled until the document is available.

---

## 4.3 Screen — Step 2: Company Profile

**Purpose:** Capture the vendor's full company profile across seven sections and validate statutory identifiers.

**UI Layout:** Two-column responsive form grouped into labelled sections; sticky footer with Save Draft and Save & Continue.

**Sections & Fields:**
1. **Company Details:** Company Name*, Legal Name, Registration Number, Category/Industry, Website, Company Phone.
2. **Contact Details:** Contact Person*, Designation, Email, Mobile*, Emergency Contact Name, Emergency Phone.
3. **Authorized Person:** Name*, Designation, Email, Mobile, ID Proof Reference.
4. **Bank Details:** Account Holder Name, Bank Name, Account Number, IFSC, Branch, Account Type.
5. **GST Details:** GST Number, GST Registration State.
6. **PAN Details:** PAN Number.
7. **Registered Address:** Address Line*, City*, State*, Country*, Pincode*.

*(\* = required.)*

**Components:** Text inputs, selects (Country, State, Account Type, Category), section headers, inline validation messages.

**Cards:** One card per section.

**Buttons:** **Save Draft** (secondary), **Save & Continue** (primary).

**Icons:** Building2, User, ShieldCheck, Landmark (bank), Receipt (GST), CreditCard (PAN), MapPin.

**Badges:** "Draft saved" (neutral) after a draft save.

**Validation:**
- **Required:** Company Name, Contact Person, Contact Mobile, Authorized Person Name, Address Line, City, State, Country, Pincode.
- **GST Number:** required for standard vendors; 15-character GSTIN pattern `^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$`; checksum validated.
- **PAN Number:** required; pattern `^[A-Z]{5}[0-9]{4}[A-Z]$`; stored uppercased.
- **IFSC:** pattern `^[A-Z]{4}0[A-Z0-9]{6}$` when bank details provided.
- **Account Number:** 9–18 digits when bank details provided; confirm-account-number match.
- **Email:** valid email; **Mobile/Phone:** valid phone; **Pincode:** 6 digits.

**Business Rules:**
- Save Draft persists partial data without validation gates and keeps the step editable.
- Save & Continue validates all required fields and advances `current_step` to `max(current_step, 3)`.
- Company Name, GST, and PAN are mirrored to the vendor master.
- The profile is editable while status ∈ {Draft, In Progress, Resubmit Required} and locked once Submitted.

**Controller Flow:** `TpvOnboardingController@saveProfile` → `SaveOnboardingProfileRequest` → persist `profile` JSON → mirror to vendor master → advance step → audit `Profile Updated`. Draft: `@saveDraft` → persist → audit `Draft Saved`.

**API Flow:**
- `POST /api/tpv/onboarding/{id}/profile` → `200 { onboarding }`.
- `POST /api/tpv/onboarding/{id}/profile/draft` → `200 { onboarding }`.

**Database Updates:** `tpv_onboardings.profile` (JSON: company, contact, authorized_person, bank, gst, pan, address); `vendors.gst_number`, `vendors.pan_number`, `vendors.company_name`; optional normalized `vendor_bank_accounts` row; `current_step` advanced on continue.

**Notifications:** Portal "Profile saved."

**Audit Logs:** `Draft Saved`, `Profile Updated`.

**Navigation:** ← Step 1 · → Step 3 on Save & Continue.

**Success State:** Toast "Profile saved"; advance to Step 3.

**Error State:** Field-level messages; a summary banner listing invalid fields; scroll to first error.

**Edge Cases:**
- Duplicate GST/PAN across vendors in the tenant → warning (configurable to block).
- Partial bank details → IFSC/Account validated only when any bank field is filled.
- Re-entry after rejection (Resubmit Required) → editable again.

---

## 4.4 Screen — Step 3: Legal Documents

**Purpose:** Collect all mandatory statutory documents for the vendor's type, with per-document lifecycle actions.

**UI Layout:** A document checklist table: one row per required type plus an "Additional Documents" section. Each row shows type label, status badge, uploaded filename, and actions. A progress header shows uploaded vs required.

**Complete Document List:**

| # | Document | Key | Standard | Temporary |
|---|----------|-----|:---:|:---:|
| 1 | Company Registration | `company_registration` | Mandatory | — |
| 2 | PAN Card | `pan` | Mandatory | — |
| 3 | GST Certificate | `gst` | Mandatory | Mandatory |
| 4 | Insurance / WCP | `insurance_wcp` | Mandatory | Mandatory |
| 5 | PF Registration | `pf` | Mandatory | — |
| 6 | ESIC Registration | `esic` | Mandatory | — |
| 7 | BOCW Registration | `bocw` | Mandatory | — |
| 8 | CLRA / Labour Licence | `clr` | Mandatory | — |
| 9 | MLWF | `mlwf` | Mandatory | — |
| 10 | MSCB | `mscb` | Mandatory | — |
| 11 | Udyam Certificate | `udyam` | Mandatory | — |
| 12 | LOI / WO / PO | `loi_wo_po` | Optional | Mandatory |

**Components:** File input (hidden, triggered by Upload/Replace), status badge, filename chip, action buttons, progress bar, "Additional Documents" uploader.

**Cards:** Documents panel card; progress summary card.

**Buttons per row:** **Upload**, **Replace**, **Preview**, **Download**, **Delete**. Panel: **Add Additional Document**, **Continue**.

**Icons:** Upload, Repeat (replace), Eye (preview), Download, Trash2, FileText.

**Badges:** Pending (amber), Approved (green), Rejected (red), Resubmit (violet), Not Uploaded (neutral).

**Validation:**
- Allowed formats: **PDF, PNG, JPG, JPEG**. Maximum size: **8 MB**.
- Reject unknown types, disallowed formats, oversize files (`422`).
- Approved documents cannot be replaced or deleted.

**Business Rules:**
- The mandatory set is derived from the vendor type (standard vs temporary).
- Upload sets a document to **Pending**.
- Replace (non-approved) deletes the prior file and sets **Pending**.
- Delete is allowed only for non-approved documents.
- Preview opens in a new tab; Download downloads the file.
- Step 3 completes when **all mandatory documents are uploaded**, unlocking Step 4.
- Additional (non-mandatory) documents are permitted and tracked separately.

**Controller Flow:** `VendorDocumentController@checklist` → matrix. `@upload` → validate file → store on private disk → status Pending → audit `Document Uploaded`. `@replace` → audit `Document Replaced`. `@destroy` → audit `Document Deleted`. `@download` → stream.

**API Flow:**
- `GET /api/tpv/vendors/{vendor}/documents`
- `POST /api/tpv/vendors/{vendor}/documents` (multipart: `type`, `file`)
- `POST /api/tpv/documents/{id}/replace` (multipart: `file`)
- `DELETE /api/tpv/documents/{id}`
- `GET /api/tpv/documents/{id}/download`

**Database Updates:** `vendor_documents`: `type`, `file_path`, `original_name`, `mime`, `size`, `status = Pending`, `expires_at?`. Files under `tenant_{id}/vendor_{id}/` on a private disk with randomized names.

**Notifications:** Portal "Document uploaded."

**Audit Logs:** `Document Uploaded`, `Document Replaced`, `Document Deleted`.

**Navigation:** ← Step 2 · → Step 4 (Continue enabled when all mandatory documents uploaded).

**Success State:** Row shows Pending badge + filename; progress advances; toast "Uploaded".

**Error State:** Inline row error for invalid file; retry preserved.

**Edge Cases:**
- Re-upload over an existing Pending/Rejected document replaces it.
- Attempt to delete/replace an Approved document → blocked with a message.
- Large file or slow network → progress spinner; failure shows retry.

---

## 4.5 Screen — Step 4: Under Review

**Purpose:** Show the live review status of every document, drive resubmission of rejected documents, and gate progression until all mandatory documents are approved.

**UI Layout:** A review dashboard: a summary strip (counters + progress %), a status banner, and a document table with per-row status, admin remark, and actions. A manual Refresh control plus automatic refresh.

**Components:**
- **Summary strip:** Total Documents, Approved, Pending, Rejected, Progress %.
- **Status banner:** contextual message.
- **Document table:** type, status badge, admin remark, actions (View, Resubmit).
- **Refresh** button; **Auto Refresh** indicator.
- **Continue** button.

**Cards:** Summary card; review table card.

**Buttons:** **Refresh Now**, **View Document**, **Resubmit** (rejected rows), **Continue** (disabled until all mandatory approved).

**Icons:** RefreshCw, Eye, Upload, CheckCircle2, Clock, XCircle, AlertTriangle.

**Badges:** Approved (green), Pending (amber), Rejected (red), Resubmit (violet).

**Validation:** Resubmission enforces PDF/PNG/JPG/JPEG ≤ 8 MB; only rejected documents may be resubmitted.

**Business Rules:**
- Counters: **Total** = mandatory count; **Approved/Pending/Rejected** from the checklist; **Progress %** = approved ÷ total × 100.
- The status banner reflects state ("All documents approved", "N documents pending review", "N documents rejected — action required").
- **Auto Refresh** polls the checklist on a fixed interval; **Refresh Now** fetches immediately.
- Only **Rejected** documents can be re-uploaded; resubmission sets the document back to **Pending** and clears the prior admin remark.
- **Continue is disabled until ALL mandatory documents are Approved.**
- Approved documents are immutable.

**Controller Flow:** `VendorDocumentController@checklist` (polled) → matrix with counts. `@resubmit` → validate → replace file → status Pending → clear remark → audit `Document Resubmitted`. Admin side: `@review` → Approved/Rejected (remark required on reject) → audit `Document Approved`/`Document Rejected`.

**API Flow:**
- `GET /api/tpv/vendors/{vendor}/documents` (polled)
- `POST /api/tpv/documents/{id}/resubmit` (multipart: `file`)
- Admin: `POST /api/tpv/documents/{id}/review` (`decision`, `remarks`)

**Database Updates:** `vendor_documents.status`, `remarks`, `reviewed_by`, `reviewed_at` on admin decisions; status back to Pending on resubmit.

**Notifications:** Vendor notified on each Approved/Rejected decision (Portal + Email). Admin notified when a document is resubmitted.

**Audit Logs:** `Document Approved`, `Document Rejected`, `Document Resubmitted`.

**Navigation:** ← Step 3 · → Step 5 (Continue enabled only when all mandatory approved).

**Success State:** When all mandatory approved: banner turns green, progress = 100%, Continue enabled.

**Error State:** Rejected banner with count; resubmit errors shown inline.

**Edge Cases:**
- A document rejected after the vendor reached Step 5 returns the flow to Step 4 with an action-required banner.
- Concurrent admin decisions appear on the next auto-refresh or Refresh Now.
- Network failure during resubmission → inline error + retry.

---

## 4.6 Screen — Step 5: Final Confirmation

**Purpose:** Present a full read-only summary, capture the mandatory declaration, and finish onboarding.

**UI Layout:** Stacked summary cards (Company, Profile, Documents), a declaration card with the mandatory checkbox, and a footer with Finish Onboarding.

**Components:**
- **Company Summary** card (name, registration reference, category, address).
- **Profile Summary** card (contact, authorized person, bank, GST, PAN).
- **Document Summary** card (each mandatory document with Approved status).
- **Declaration** text + checkbox.
- **Finish Onboarding** button.

**Cards:** Company, Profile, Document, Declaration.

**Buttons:** **Finish Onboarding** (primary, disabled until the checkbox is ticked), **Back**.

**Icons:** Building2, User, FileCheck, ShieldCheck, CheckCircle2.

**Badges:** Approved badges on each document row.

**Validation:**
- The declaration checkbox is **required**; Finish Onboarding is disabled until ticked.
- The server re-verifies before finishing that the profile is complete and **all mandatory documents are Approved**; otherwise it blocks with a clear message.

**Business Rules:**
- Declaration text: **"I hereby declare that all information submitted is true and correct."**
- Finish Onboarding sets onboarding **Submitted**, vendor **Pending Approval**, records completion metadata, and redirects to Step 6.
- Steps 1–5 become read-only after finishing.

**Controller Flow:** `TpvOnboardingController@submit` → re-verify completeness → record declaration + completion metadata → status Submitted → vendor Pending Approval → audit `Declaration Accepted`, `Onboarding Completed` → redirect.

**API Flow:** `POST /api/tpv/onboarding/{id}/submit` (`declaration: true`) → `200 { onboarding }`.

**Database Updates:** `tpv_onboardings`: `declaration_accepted_at`, `onboarding_complete = 1`, `completed_at`, `completed_ip`, `completed_browser`, `completed_device`, `status = Submitted`, `submitted_at`, `current_step = 6`; `vendors.status = Pending Approval`.

**Notifications:** Vendor: "Onboarding submitted — awaiting approval" (Portal + Email). Admin: "New onboarding submitted for review" (Portal + Email).

**Audit Logs:** `Declaration Accepted`, `Onboarding Completed`.

**Navigation:** ← Step 4 · → Step 6 (redirect on finish).

**Success State:** Redirect to Step 6 (Pending state); toast "Onboarding submitted".

**Error State:** If a document became rejected, block with "Some documents require action" and route back to Step 4.

**Edge Cases:**
- Attempt to finish without the checkbox → button disabled; server rejects (`422`) if forced.
- Re-verification catches last-moment rejections and blocks submission.

---

## 4.7 Screen — Step 6: Final Approval

**Purpose:** Show the vendor's live final status and provide admins the approval controls. Renders one of four outcomes.

**UI Layout:** A full-height status hero appropriate to the outcome, supporting details, and outcome-specific actions. For admins viewing a submitted onboarding, an approval action bar (Approve, Reject, Hold) is shown.

### Outcome 1 — Pending
- **Hero:** neutral/amber "Waiting for Admin Approval."
- **Message:** "Your onboarding is under review. You'll be notified once a decision is made."
- **Support Contact.**

### Outcome 2 — Approved
- **Large Success Hero** with **Confetti Animation**.
- **Registration Number:** `TPV-YYYY-NNNNN` (prominent).
- **Approved By** (admin name) and **Approved Date**.
- **Support Contact.**
- **Buttons:** **Go To Dashboard**, **Add Workforce**, **Logout**.

### Outcome 3 — Hold
- **Orange Hero:** "Your onboarding is on hold."
- **Admin Remark** (hold reason).
- **Support Contact.**

### Outcome 4 — Rejected
- **Red Hero:** "Your onboarding was rejected."
- **Admin Remark** (rejection reason).
- **Re-upload Documents** button (returns to Step 4).
- **Support Contact.**

**Components:** Status hero, confetti layer (Approved only), registration-number card, remark card, support-contact card, action buttons; admin action bar (Approve / Reject / Hold with remark modal).

**Buttons:** Vendor — **Go To Dashboard**, **Add Workforce**, **Logout**, **Re-upload Documents** (Rejected). Admin — **Approve**, **Reject**, **Hold**, **Release**.

**Icons:** CheckCircle2, Clock, PauseCircle, XCircle, LayoutDashboard, HardHat, LogOut.

**Badges:** Status pill (Approved/Pending/Hold/Rejected).

**Validation:** Reject and Hold require a remark/reason (`422` without it).

**Business Rules:**
- **Approve** (Admin only): generate a unique **Registration Number** `TPV-YYYY-NNNNN`, set onboarding **Approved**, vendor **Active**, activate the login, and dispatch multi-channel notifications. For a Temporary TPV, the admin-defined access window (`access_start_at` → `access_expires_at`) continues to apply and is unaffected by approval.
- **Reject:** set onboarding **Rejected** with a required remark; the vendor resubmits via Step 4.
- **Hold:** set onboarding **On Hold** with a required reason; **Release** returns it to **Under Review**.
- Only Admin/Super Admin may approve, reject, hold, or release.

**Controller Flow:** `TpvOnboardingController@approve|reject|hold|release`. Approve → generate registration number → status Approved → vendor Active → activate login → notify → audit `Vendor Approved`.

**API Flow:**
- `POST /api/tpv/onboarding/{id}/approve` → `200 { onboarding: { status:"Approved", registration_number } }`
- `POST /api/tpv/onboarding/{id}/reject` (`remarks`) → `200`
- `POST /api/tpv/onboarding/{id}/hold` (`reason`) → `200`
- `POST /api/tpv/onboarding/{id}/release` → `200`

**Database Updates:** `tpv_onboardings`: `status`, `approved_at`, `approved_by`, `registration_number`, `remarks`, `hold_reason`. `vendors.status = Active`, `approved_at`, `approved_by`, `registration_number`. `users.status = active`. (A Temporary TPV's `access_expires_at` remains the admin-defined value and is not changed by approval.)

**Notifications:** Approved → Email + SMS + WhatsApp + Portal welcome (with registration number). Rejected/Hold → Email + Portal with reason.

**Audit Logs:** `Vendor Approved`, `Vendor Rejected`, `Vendor On Hold`, `Onboarding Released`.

**Navigation:** Approved → Dashboard / Workforce; Rejected → Step 4; Hold → stays with support contact.

**Success State (Approved):** Confetti; registration number displayed; action buttons enabled.

**Error State:** Approve/Reject/Hold failure → toast with server message; state unchanged.

**Edge Cases:**
- Re-approval of an already-approved onboarding is blocked.
- Registration number is generated exactly once and is immutable.
- Hold then Release returns to Under Review without losing document decisions.

---

## 4.8 Screen — Dashboard

**Purpose:** The vendor's post-approval home, visible only after approval and activation.

**UI Layout:** Header (company, registration number, status, expiry countdown for temporary), KPI tiles, onboarding-complete card with a 100% progress bar, quick actions, recent activity timeline.

**Components:** KPI tiles, progress card, quick-action buttons, activity feed.

**Cards:** KPI cards; onboarding summary card; activity card.

**Buttons:** **Add Workforce**, **Manage Documents**, **View Profile**, **Logout**.

**Icons:** LayoutDashboard, HardHat, FileText, User, Activity.

**Badges:** Account status (Active); expiry countdown pill (temporary).

**Validation:** Access allowed only when the account is Active and not expired.

**Business Rules:**
- The Dashboard is reachable only after onboarding `Approved` and vendor `Active`.
- Temporary vendors see days-remaining; on expiry the account locks pending admin extension.

**Controller Flow:** `DashboardController@vendor` → aggregate KPIs → return.

**API Flow:** `GET /api/tpv/dashboard` → `200 { kpis, onboarding, activity }`.

**Database Updates:** None (read-only). Activity feed reads from `audit_logs`.

**Notifications:** None on view.

**Audit Logs:** `Dashboard Viewed` (optional).

**Navigation:** → Workforce, Documents, Profile.

**Success State:** KPIs and activity render; quick actions available.

**Error State:** If the account expired, redirect to a locked state with support contact.

**Edge Cases:** Expiry mid-session → next request returns `403`; the UI locks the Dashboard.

---

## 4.9 Screen — Workforce Module

**Purpose:** Onboard individual workers under an approved vendor and issue site-entry QR badges.

**UI Layout:** Worker list with counters and an "Add Worker" action; a five-step worker wizard (Profile → Medical → Induction → PPE → Badge); a worker detail with badge and QR.

**Worker Wizard Steps:**
1. **Profile:** name, DOB, gender, designation, skill category, Aadhaar, mobile, blood group, address, emergency contact, photo.
2. **Medical:** exam details, screening, fitness status (**Fit / Fit with Restrictions / Unfit**).
3. **Induction:** trainer, date, topics, score, pass/fail.
4. **PPE:** mandatory items (helmet, safety shoes) + optional items.
5. **Badge:** issue QR badge and activate → **Worker Ready**.

**Components:** Worker table, wizard steps, medical/induction forms, PPE issuance, badge/QR card.

**Cards:** Worker list card; per-step cards; badge card.

**Buttons:** **Add Worker**, **Save & Continue** (per step), **Activate / Issue Badge**, **Suspend**, **Reinstate**, **Terminate**.

**Icons:** HardHat, Stethoscope, GraduationCap, ShieldCheck, QrCode.

**Badges:** Worker status (Draft/Active/Suspended/Terminated); fitness status; PPE issued.

**Validation:** Aadhaar unique per tenant; age ≥ 18; mandatory PPE required; medical passing; induction passed.

**Business Rules (Activation Gate — all must pass):**
- The employing vendor is **Active**.
- Worker profile complete and **age ≥ 18**.
- Medical recorded and **passing** (Fit or Fit with Restrictions).
- Induction recorded and **passed**.
- All **mandatory PPE** issued.
- Activation issues a unique **badge number** and **QR token** (validity default 1 year) → **Worker Ready**.

**Controller Flow:** `TpvWorkerController@store|saveMedical|saveInduction|issuePpe|activate`. Activation runs the blocker checks; on pass, issues badge + QR and sets Active.

**API Flow:** `POST /api/tpv/workers`, `POST /api/tpv/workers/{id}/medical|induction|ppe`, `POST /api/tpv/workers/{id}/activate`, `GET /api/tpv/workers/{id}/badge`.

**Database Updates:** `tpv_workers`, `tpv_worker_medicals`, `tpv_worker_inductions`, `tpv_worker_ppe_issues`; on activation `badge_number`, `qr_token`, `badge_valid_until`, `status = Active`.

**Notifications:** Worker-ready confirmation to the vendor (Portal + Email).

**Audit Logs:** `Workforce Added`, `Medical Completed`, `Induction Completed`, `QR Badge Generated`.

**Navigation:** Dashboard → Workforce → Worker wizard → Worker detail.

**Success State:** Worker becomes **Active/Ready**; badge + QR shown.

**Error State:** Activation blocked lists the exact unmet conditions.

**Edge Cases:** Unfit medical or missing mandatory PPE blocks activation; termination revokes the QR token and closes open attendance; 3 active or 1 Critical strike terminate access.

---

## 4.10 Global Element — Temporary Access Banner & Countdown

**Purpose:** Give a Temporary TPV a constant, unmistakable indication of remaining access time on every screen they can reach.

**UI Layout:** A full-width sticky banner pinned to the top of the viewport, above the page header, on **every** screen: **Login, Step 1, Step 2, Step 3, Step 4, Step 5, Step 6, Dashboard, and Workforce**. Every page reserves the top region for this banner. It is rendered only when `is_temporary = 1`.

**Components:** Temporary badge ("Temporary Third Party Vendor"), live countdown, colour-coded severity, optional "Request Extension" link.

**Cards:** None (banner strip).

**Buttons:** Optional "Contact Administrator" / "Request Extension" link.

**Icons:** Clock, AlertTriangle (as severity rises).

**Badges:** "Temporary Third Party Vendor" pill.

**Countdown Content (example):**
```
Temporary Third Party Vendor — Access expires in: 2 Days 05 Hours 17 Minutes
```

**Countdown Rules:**
- The countdown starts from `access_start_at` and ends at `access_expires_at`.
- It updates automatically every second (client tick), reconciled against the server on each page load and on each API response header.
- **Colour thresholds:**
  - **Green** — more than 3 days remaining.
  - **Orange** — less than 3 days remaining.
  - **Red** — less than 24 hours remaining.
  - **Expired** — 0 remaining (banner switches to the expired state and the app locks).

**Business Rules:**
- The banner is **always visible** for a Temporary TPV on every listed screen.
- The countdown is derived server-side (authoritative) and mirrored client-side for smooth ticking; the client never extends the window on its own.
- When the countdown reaches zero, the client immediately triggers the expiry lock (see §4.11) and stops all further actions.

**Controller Flow:** Each authenticated response includes the vendor's `access_status`, `access_start_at`, `access_expires_at`, and `seconds_remaining`; middleware recomputes these on every request.

**API Flow:** `GET /api/tpv/access/countdown` → `{ access_status, access_start_at, access_expires_at, seconds_remaining }`.

**Database Updates:** None (read-only projection of the vendor's access fields).

**Notifications:** Reminder thresholds (7d/3d/1d/6h) are dispatched by the scheduler (see §9).

**Audit Logs:** None for display; reminders are logged when sent.

**Navigation:** Persistent across all screens.

**Success State:** Countdown renders in the correct colour band.

**Error State:** If countdown data is unavailable, the banner shows "Verifying access…" and the app re-fetches.

**Edge Cases:** Clock skew is corrected on each server reconcile; crossing a threshold updates the colour instantly; a permanent vendor never sees the banner.

---

## 4.11 Screen — Temporary Access Expired

**Purpose:** Present a hard-stop screen once a Temporary TPV's access has expired, blocking all further activity.

**UI Layout:** A centered full-screen state with a red hero, message, and support contact. No navigation to onboarding, dashboard, or workforce is available.

**Components:** Red hero, expiry message, support-contact card, Logout button.

**Cards:** Expiry hero card; support-contact card.

**Buttons:** **Logout**, **Contact Administrator**.

**Icons:** XCircle, Clock.

**Badges:** "Access Expired" (red).

**Validation:** N/A (all input is blocked).

**Business Rules:**
- Shown whenever `access_status = Expired`.
- Message: **"Your temporary access has expired. Please contact your administrator."**
- All active sessions are terminated; login, APIs, uploads, edits, and workforce are blocked.

**Controller Flow:** Any request from an expired Temporary TPV is short-circuited by middleware → `403` with an `access_expired` code; the SPA renders this screen.

**API Flow:** Every protected endpoint returns `403 { "message":"Your temporary access has expired. Please contact your administrator.", "code":"access_expired" }`.

**Database Updates:** On the transition to expiry the system sets `access_status = Expired` and revokes tokens.

**Notifications:** "Temporary access expired" (Email + SMS + WhatsApp + Portal) to the vendor; admin notified.

**Audit Logs:** `Temporary Access Expired`.

**Navigation:** Only Logout.

**Success State:** N/A.

**Error State:** N/A.

**Edge Cases:** An in-flight upload at the moment of expiry is rejected; a mid-session expiry immediately locks the current screen on the next tick.

---

## 4.12 Dialog — Extend / Renew Temporary Access

**Purpose:** Let an admin (or staff) extend a Temporary TPV's access window, reset the countdown, or change the expiry date, with a mandatory reason.

**UI Layout:** Modal dialog launched from the admin vendor view or the Temporary TPV Dashboard.

**Components:** Current window summary, new expiry picker or validity presets (1/3/7/15/custom days), extension reason textarea, Confirm/Cancel.

**Cards:** Modal body.

**Buttons:** **Extend Access** (primary), **Cancel**.

**Icons:** CalendarPlus, Clock, RefreshCw.

**Badges:** Current status pill.

**Validation:** New expiry must be in the future and after `access_start_at`; extension reason required.

**Business Rules:**
- Admin/Staff may **extend access**, **reset the countdown**, and **change the expiry date**.
- Every extension requires a reason and creates an audit log.
- Extending an expired vendor sets `access_status = Active` with the new window and re-enables access.

**Controller Flow:** `TpvAccessController@extend` → validate → set `access_expires_at`, `access_extended_at`, `access_extended_by`, `extension_reason`, `access_status = Active` → audit → notify.

**API Flow:** `POST /api/tpv/vendors/{vendor}/access/extend`.

**Database Updates:** `access_expires_at`, `access_extended_at`, `access_extended_by`, `extension_reason`, `access_status`.

**Notifications:** "Temporary access extended" (Email + SMS + WhatsApp + Portal) with the new expiry.

**Audit Logs:** `Temporary Access Extended`.

**Navigation:** Returns to the admin vendor view; the vendor's countdown updates on next reconcile.

**Success State:** Toast "Access extended to {date}"; countdown resets.

**Error State:** Past date or missing reason → inline error.

**Edge Cases:** Extending during an active vendor session updates the banner without forcing re-login.

---

## 4.13 Dialog — Convert Temporary to Permanent

**Purpose:** Promote a Temporary TPV to a Permanent TPV in one action, preserving all history.

**UI Layout:** Confirmation modal from the admin vendor view.

**Components:** Summary of the vendor, conversion consequences, optional note, Confirm/Cancel.

**Cards:** Modal body.

**Buttons:** **Convert to Permanent Vendor** (primary), **Cancel**.

**Icons:** ArrowUpCircle, ShieldCheck.

**Badges:** "Temporary" → "Permanent" transition indicator.

**Validation:** Admin/Super Admin only; the vendor must exist and be Temporary.

**Business Rules:** On confirm the system automatically:
- Removes the temporary flag (`is_temporary = 0`).
- Removes expiry (`access_expires_at = null`, `access_status = Converted`).
- Generates a **TPV Registration Number** (`TPV-YYYY-NNNNN`) if not already issued.
- Enables permanent access.
- Keeps all onboarding and audit history intact.
- Notifies the vendor.

**Controller Flow:** `TpvAccessController@convert` → authorize (admin) → clear temporary fields → issue registration number → set vendor Active/permanent → audit → notify.

**API Flow:** `POST /api/tpv/vendors/{vendor}/access/convert`.

**Database Updates:** `is_temporary = 0`, `access_expires_at = null`, `access_status = Converted`, `converted_to_permanent_at`, `converted_by`, `registration_number` (if newly issued).

**Notifications:** "You are now a Permanent Vendor" (Email + SMS + WhatsApp + Portal) with the Registration Number.

**Audit Logs:** `Temporary TPV Converted to Permanent`.

**Navigation:** Returns to the admin vendor view; the vendor's banner disappears on next load.

**Success State:** Toast "Converted to Permanent"; Registration Number shown.

**Error State:** Non-admin attempt → `403`; already permanent → no-op message.

**Edge Cases:** Conversion mid-window immediately removes the countdown; history and documents are unchanged.

---

## 4.14 Popup — Expiry Warning

**Purpose:** Proactively warn a Temporary TPV as the deadline approaches.

**UI Layout:** A non-blocking toast/popup that appears when the countdown crosses a threshold (3 days, 1 day, 6 hours) and on the final hour.

**Components:** Warning message, remaining time, "Contact Administrator" action, dismiss.

**Cards:** Toast card.

**Buttons:** **Contact Administrator**, **Dismiss**.

**Icons:** AlertTriangle, Clock.

**Badges:** Severity colour matching the countdown band.

**Validation:** N/A.

**Business Rules:** Appears once per threshold crossing per session; escalates colour with severity; does not block interaction until actual expiry.

**Controller Flow:** Driven client-side from the authoritative countdown; server sends the matching reminder notification.

**API Flow:** Reads `GET /api/tpv/access/countdown`.

**Database Updates:** None.

**Notifications:** Aligned to the reminder schedule (see §9).

**Audit Logs:** None (the corresponding reminder dispatch is logged).

**Navigation:** Dismiss returns to the current screen.

**Success State:** Popup shown at the correct threshold with correct colour.

**Error State:** N/A.

**Edge Cases:** Multiple tabs show the popup consistently based on the shared countdown.

---

# 5. Controller Flow

Every screen follows the standard request pipeline:

```
View (React screen)
   ↓  user action (submit / upload / decision)
Controller (Api\Tpv\*Controller)
   ↓  authorize (role middleware) + tenant guard
Validation (FormRequest / inline rules)
   ↓  validated payload
Service (business logic) → Model (Eloquent)
   ↓
Database (tenant-scoped write, transaction where multi-row)
   ↓
Activity Log (Auditable::recordAudit → audit_logs)
   ↓
Notification (NotificationService: Email / SMS / WhatsApp / Portal)
   ↓
Response → Next Screen (state advance / redirect)
```

**Onboarding-specific controller sequence:**
```
login()               → issue token → resume step
step1()               → GET kickoff PDF
accept_kickoff()      → acknowledged + metadata → status In Progress → step 2
step2()               → render profile
save_profile()        → validate → mirror GST/PAN → step 3
save_draft()          → persist partial
step3()               → render documents
upload_document()     → status Pending
step4()               → render review (poll)
resubmit_document()   → status Pending, clear remark
review_document()     → (admin) Approved/Rejected
step5()               → render confirmation
complete_onboarding() → declaration + completion metadata → Submitted → vendor Pending Approval → step 6
step6()               → render outcome
approve()/reject()/hold()/release()  → (admin) decision → notifications
dashboard()           → post-approval home
workforce()           → worker onboarding
```

**Temporary TPV access lifecycle (admin-driven):**
```
create_temporary_tpv()  → (admin/staff) set start/expiry/validity → generate credentials
                          → email login → status Active → audit "Temporary TPV Created" → notify
   ↓
every_request()         → middleware recomputes seconds_remaining;
                          if now ≥ access_expires_at → set Expired → revoke tokens → 403 access_expired
   ↓
extend_access()         → (admin/staff) new window + reason → status Active
                          → audit "Temporary Access Extended" → notify
   ↓
expire_access()         → (scheduler/middleware) status Expired → terminate sessions
                          → audit "Temporary Access Expired" → notify
   ↓
convert_to_permanent()  → (admin) is_temporary=0, expiry removed, Registration Number issued,
                          permanent access enabled → audit "Temporary TPV Converted to Permanent" → notify
```

---

# 6. Database Design

All tables carry an indexed `tenant_id` (row-level multi-tenancy). Primary keys are `id` (bigint, auto-increment). Cross-table integrity is enforced in the service layer; `users.tenant_id` uses a constrained foreign key. Timestamps (`created_at`, `updated_at`) and soft-deletes (`deleted_at`) are present on entity tables; ledger tables are append-only.

## 6.1 `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id | bigint FK→tenants | constrained |
| name | string | |
| email | string | unique |
| password | string | hashed |
| role | string | admin / staff / third_party_vendor / vendor / client |
| status | string | pending / active / suspended / rejected |
| vendor_type | string | standard / temporary |
| tpv_type | string | permanent / temporary |
| access_expires_at | timestamp | temporary expiry; mirrors `vendors.access_expires_at` |
| phone, company, designation | string | |
| meta | json | username, etc. |
| timestamps, soft-deletes | | |
Indexes: unique(email). FK: tenant_id.

## 6.2 `vendors`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id | bigint | index |
| user_id | bigint | login link |
| account_manager_id | bigint | |
| vendor_code | string | |
| company_name, legal_name | string | |
| vendor_type | string | standard / temporary |
| engagements | json | ['tpv','purchase'] |
| email, phone, website, category | string | |
| registration_number | string | issued at approval |
| gst_number, pan_number | string | |
| address, city, state, country, pincode | string | |
| status | string | Inactive / Pending Approval / Active / Suspended / Blacklisted |
| approved_at, approved_by | ts/bigint | |
| notes | text | |
| timestamps, soft-deletes | | |
Indexes: tenant_id, user_id, status; unique(tenant_id, vendor_code).

## 6.3 `vendor_contacts`
`id, tenant_id, vendor_id, name, designation, email, phone, is_primary(bool), timestamps, soft-deletes.` Index: tenant_id, vendor_id.

## 6.4 `vendor_bank_accounts`
`id, tenant_id, vendor_id, account_holder, bank_name, account_number, ifsc, branch, account_type, timestamps, soft-deletes.` Index: tenant_id, vendor_id.

## 6.5 `vendor_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id, vendor_id | bigint | index |
| type | string | one of the 12 keys |
| file_path, original_name, mime | string | |
| size | bigint | bytes |
| status | string | Pending / Approved / Rejected / Resubmit / Expired |
| remarks | text | admin remark |
| reviewed_by | bigint | |
| reviewed_at | ts | |
| expires_at | date | validity |
| timestamps, soft-deletes | | |
Indexes: tenant_id, vendor_id, status.

## 6.6 `tpv_onboardings`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id, vendor_id | bigint | index; unique(tenant_id, vendor_id) |
| kickoff_pdf_path | string | |
| acknowledged | boolean | default 0 |
| acknowledged_at | ts | |
| acknowledged_ip | string | |
| acknowledged_browser | string | |
| acknowledged_device | string | |
| current_step | tinyint | 1..6 |
| profile | json | all Step-2 sections |
| status | string | Draft / In Progress / Submitted / Under Review / Approved / Rejected / On Hold |
| declaration_accepted_at | ts | |
| onboarding_complete | boolean | default 0 |
| completed_at, completed_ip, completed_browser, completed_device | ts/string | |
| submitted_at | ts | |
| approved_at, approved_by | ts/bigint | |
| registration_number | string | `TPV-YYYY-NNNNN` |
| remarks | text | reject reason |
| hold_reason | text | hold reason |
| timestamps, soft-deletes | | |
Indexes: tenant_id, vendor_id, status; unique(tenant_id, vendor_id).

## 6.7 `tpv_workers`
`id, tenant_id, vendor_id, created_by, worker_code, name, dob, gender, designation, skill_category, aadhar_number, mobile, blood_group, address, emergency_contact, emergency_phone, photo_path, current_step, status(Draft/Active/Suspended/Terminated), badge_number, qr_token(unique), badge_issued_at, badge_issued_by, badge_valid_until, remarks, timestamps, soft-deletes.` Unique(tenant_id, worker_code), unique(tenant_id, aadhar_number).

## 6.8 Worker Records
- `tpv_worker_medicals` — fitness_status (Fit/Fit_With_Restrictions/Unfit), exam fields, screening; unique(tpv_worker_id).
- `tpv_worker_inductions` — passed(bool), score, topics; unique(tpv_worker_id).
- `tpv_worker_ppe_issues` — item, qty, size, issued_date.
- `tpv_gate_scans` — decision(Admit/Warn/Deny), append-only.
- `tpv_gate_attendances` — per worker per day; unique(tenant_id, tpv_worker_id, work_date).
- `tpv_safety_strikes` — severity(Minor/Major/Critical), voidable ledger.

## 6.9 `audit_logs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| tenant_id | bigint | |
| auditable_type, auditable_id | morph | subject |
| action | string | e.g. "Vendor Approved" |
| actor_id, actor_name, actor_role | bigint/string | snapshot |
| comment | text | |
| metadata | json | ip, browser, device, from, to |
| timestamps | | append-only |
Indexes: (auditable_type, auditable_id, id), (tenant_id).

## 6.10 Status Values (summary)
- **Onboarding:** Draft, In Progress, Submitted, Under Review, Approved, Rejected, On Hold.
- **Document:** Pending, Approved, Rejected, Resubmit, Expired.
- **Vendor:** Inactive, Pending Approval, Active, Suspended, Blacklisted.
- **Worker:** Draft, Active, Suspended, Terminated.

## 6.11 Registration Number Logic
- Format: `TPV-YYYY-NNNNN` where `YYYY` = approval year, `NNNNN` = zero-padded per-tenant, per-year sequential counter.
- Generated **once**, atomically, at approval; stored on `tpv_onboardings.registration_number` and mirrored to `vendors.registration_number`.
- Immutable after issuance; unique per tenant.

## 6.12 Audit Fields
Every entity carries `created_at`, `updated_at`, and (where applicable) `deleted_at`. Every material action writes an `audit_logs` row capturing actor snapshot, action, and metadata (IP, browser, device, from/to status).

## 6.13 Temporary Access Fields (on `vendors`)
Temporary access is modelled on the `vendors` record so the whole account inherits the window; the linked `users.access_expires_at` mirrors it for the login gate.

| Column | Type | Notes |
|--------|------|-------|
| is_temporary | boolean | default 0; 1 = Temporary TPV |
| access_start_at | timestamp | window start (countdown origin) |
| access_expires_at | timestamp | window end (countdown target) |
| access_status | string | `Active` / `Expiring` / `Expired` / `Converted` |
| access_extended_at | timestamp | last extension time |
| access_extended_by | bigint | admin/staff who extended |
| extension_reason | text | mandatory reason for the last extension |
| converted_to_permanent_at | timestamp | conversion time |
| converted_by | bigint | admin who converted |
| temporary_created_by | bigint | admin/staff who created the Temporary TPV |
| validity_days | smallint | 1 / 3 / 7 / 15 / custom |

Indexes: `is_temporary`, `access_status`, `access_expires_at`.
**Derived (not stored):** `seconds_remaining = access_expires_at − now` (computed per request; never trusted from the client).
**Mirror:** `users.access_expires_at = vendors.access_expires_at` for Temporary TPVs; cleared on conversion.

---

# 7. API Specification

Base: `/api`. Auth: `Authorization: Bearer <token>`. Content: `application/json` (uploads: `multipart/form-data`). Error envelope: `{ "message": string, "errors"?: object }`. Codes: `200` OK, `201` Created, `422` Validation, `401` Unauthenticated, `403` Forbidden, `404` Not Found, `409` Conflict.

## 7.1 Authentication
**POST `/auth/login`**
Request: `{ "email": "ops@acme.com", "password": "•••", "role": "third_party_vendor" }`
Validation: email required/email; password required; role required.
Response `200`: `{ "access_token": "…", "token_type": "Bearer", "user": { … } }`
Errors: `401` invalid credentials; `403` pending/suspended/rejected/expired.

**POST `/auth/logout`** → `200`. **GET `/auth/me`** → `200 { user }`.

## 7.2 Kickoff (Step 1)
**GET `/tpv/onboarding/{id}/kickoff`** → PDF stream (`200`).
**POST `/tpv/onboarding/{id}/kickoff/accept`**
Request: `{}` (client metadata captured server-side from request headers).
Response `200`: `{ "onboarding": { "acknowledged": true, "acknowledged_at": "…", "current_step": 2, "status": "In Progress" } }`
Errors: `403` not owner; `409` already acknowledged (idempotent success may also be returned).

## 7.3 Profile (Step 2)
**POST `/tpv/onboarding/{id}/profile`**
Request:
```json
{ "profile": {
  "company": { "company_name":"Acme", "legal_name":"Acme Pvt Ltd", "category":"Construction", "website":"https://acme.com", "company_phone":"+91…" },
  "contact": { "contact_person":"R. Kaur", "designation":"Director", "email":"r@acme.com", "mobile":"+91…", "emergency_contact":"…", "emergency_phone":"…" },
  "authorized_person": { "name":"R. Kaur", "designation":"Director", "email":"…", "mobile":"…", "id_proof_ref":"…" },
  "bank": { "account_holder":"Acme", "bank_name":"HDFC", "account_number":"00123456789", "ifsc":"HDFC0000123", "branch":"Pune", "account_type":"Current" },
  "gst": { "gst_number":"27AAAPL1234C1ZV", "gst_state":"Maharashtra" },
  "pan": { "pan_number":"AAAPL1234C" },
  "address": { "line":"…", "city":"Pune", "state":"MH", "country":"India", "pincode":"411001" } } }
```
Validation: required fields per §4.3; GST/PAN/IFSC/account patterns.
Response `200`: `{ "onboarding": { "current_step": 3 } }`. Errors: `422`.

**POST `/tpv/onboarding/{id}/profile/draft`** → `200 { onboarding }` (no gates).

## 7.4 Documents (Step 3 & 4)
**GET `/tpv/vendors/{vendor}/documents`** → `200`:
```json
{ "vendor_type":"standard",
  "required":[ { "type":"gst","type_label":"GST Certificate","uploaded":true,"status":"Approved","document_id":88 },
               { "type":"pan","type_label":"PAN Card","uploaded":true,"status":"Pending","document_id":89 } ],
  "additional":[],
  "summary":{ "required":11,"uploaded":7,"approved":5,"rejected":1,"pending":1 },
  "progress_percent":45, "complete":false }
```
**POST `/tpv/vendors/{vendor}/documents`** (multipart `type`,`file`) → `201 { document }`. Validation: type required; file `mimes:pdf,png,jpg,jpeg|max:8192`.
**POST `/tpv/documents/{id}/replace`** (multipart `file`) → `200`.
**POST `/tpv/documents/{id}/resubmit`** (multipart `file`) → `200`. Only rejected docs.
**DELETE `/tpv/documents/{id}`** → `200`. Not-approved only.
**GET `/tpv/documents/{id}/download`** → file stream.
**POST `/tpv/documents/{id}/review`** *(admin)* `{ "decision":"approve|reject", "remarks":"…" }` → `200`. Remarks required on reject.

## 7.5 Submission (Step 5)
**POST `/tpv/onboarding/{id}/submit`**
Request: `{ "declaration": true }`
Response `200`: `{ "onboarding": { "status":"Submitted", "onboarding_complete":true, "completed_at":"…", "current_step":6 } }`
Errors: `422` (declaration missing / documents not all approved / profile incomplete).

## 7.6 Approval (Step 6, admin)
**POST `/tpv/onboarding/{id}/approve`** `{ "remarks":"Verified" }` → `200 { onboarding: { status:"Approved", registration_number:"TPV-2026-00042", approved_by:5, approved_at:"…" } }`.
**POST `/tpv/onboarding/{id}/reject`** `{ "remarks":"GST mismatch" }` → `200`.
**POST `/tpv/onboarding/{id}/hold`** `{ "reason":"Pending clarification" }` → `200`.
**POST `/tpv/onboarding/{id}/release`** → `200 { onboarding: { status:"Under Review" } }`.

## 7.7 Dashboard & Workforce
**GET `/tpv/dashboard`** → `200 { kpis, onboarding, activity }`.
**POST `/tpv/workers`**, **GET `/tpv/workers/{id}`**, **POST `/tpv/workers/{id}/medical|induction|ppe`**, **POST `/tpv/workers/{id}/activate`**, **GET `/tpv/workers/{id}/badge`**.

## 7.8 Temporary TPV
**POST `/tpv/vendors/temporary`** *(admin/staff — Create Temporary TPV)*
Request:
```json
{ "company_name":"Acme Temp", "email":"temp@acme.com", "phone":"+91…",
  "access_start_at":"2026-07-22T09:00:00Z", "access_expires_at":"2026-07-29T09:00:00Z",
  "validity_days":7 }
```
Validation: company_name, email required; `access_expires_at` after `access_start_at`; `validity_days ∈ {1,3,7,15,custom}`.
Response `201`: `{ "vendor": { "id":…, "is_temporary":true, "access_status":"Active", "access_expires_at":"…" }, "credentials_sent":true }`

**POST `/tpv/vendors/{vendor}/access/extend`** *(admin/staff — Extend / Renew)*
Request: `{ "access_expires_at":"2026-08-05T09:00:00Z", "validity_days":7, "extension_reason":"Awaiting document verification" }`
Validation: future date after start; reason required.
Response `200`: `{ "vendor": { "access_status":"Active", "access_expires_at":"…", "access_extended_at":"…" } }`

**POST `/tpv/vendors/{vendor}/access/expire`** *(admin — Force Expire)*
Response `200`: `{ "vendor": { "access_status":"Expired" }, "sessions_revoked":true }`

**POST `/tpv/vendors/{vendor}/access/convert`** *(admin — Convert to Permanent)*
Response `200`: `{ "vendor": { "is_temporary":false, "access_status":"Converted", "registration_number":"TPV-2026-00042", "access_expires_at":null } }`

**GET `/tpv/access/countdown`** *(vendor — Countdown)*
Response `200`: `{ "is_temporary":true, "access_status":"Active", "access_start_at":"…", "access_expires_at":"…", "seconds_remaining":190620, "band":"green" }`

**GET `/tpv/vendors/{vendor}/access/status`** *(admin — Status)*
Response `200`: `{ "is_temporary":true, "access_status":"Active", "access_start_at":"…", "access_expires_at":"…", "extended_by":…, "extension_reason":"…", "converted_at":null }`

**Expiry enforcement:** any protected endpoint called by an expired Temporary TPV returns `403 { "code":"access_expired", "message":"Your temporary access has expired. Please contact your administrator." }`.

---

# 8. Business Rules

**Login & Session**
1. Vendors authenticate with `role = third_party_vendor`.
2. Login issues a 30-day Bearer token and revokes prior tokens (single session).
3. Pending/suspended/rejected/expired accounts are denied with a specific message.
4. Each request re-validates role, active status, and expiry.
5. The vendor is resolved from the token, never from the URL.
6. A Temporary TPV's access is bounded by the admin-defined window (`access_start_at` → `access_expires_at`) set at creation; permanent access never expires.

**Wizard**
7. The wizard resumes at the vendor's `current_step`.
8. Each step unlocks only when the prior step's completion rule is met.
9. Steps 1–5 become read-only after submission.

**Step 1 — Kickoff**
10. The PDF supports View, Download, Print, and Zoom.
11. The acknowledgement checkbox is required to continue.
12. Acknowledgement records `acknowledged=1`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`.
13. Continue is disabled until acknowledgement is recorded.
14. Acknowledgement sets status In Progress and advances to Step 2; it is immutable.

**Step 2 — Profile**
15. Required: Company Name, Contact Person, Contact Mobile, Authorized Person Name, Address, City, State, Country, Pincode.
16. GST required for standard vendors; 15-char GSTIN pattern + checksum.
17. PAN required; pattern `AAAAA9999A`; stored uppercased.
18. IFSC pattern validated when bank details are present.
19. Account Number 9–18 digits and confirmed when bank details are present.
20. Email, phone, and 6-digit pincode formats validated.
21. Save Draft persists partial data; Save & Continue validates and advances.
22. GST/PAN/Company Name mirrored to the vendor master.

**Step 3 — Legal Documents**
23. The mandatory set follows the vendor type.
24. Allowed formats: PDF, PNG, JPG, JPEG; max 8 MB.
25. Files stored on a private disk with randomized names.
26. Upload sets status Pending.
27. Replace and Delete are allowed only for non-approved documents.
28. Preview and Download are available for any uploaded document.
29. Step 3 completes when all mandatory documents are uploaded.
30. Additional documents are permitted and tracked separately.

**Step 4 — Under Review**
31. Counters show Total, Approved, Pending, Rejected, and Progress %.
32. The status banner reflects the current review state.
33. Auto Refresh polls at a fixed interval; Refresh Now fetches immediately.
34. Only rejected documents can be re-uploaded.
35. Resubmission returns a document to Pending and clears the prior remark.
36. Continue is disabled until all mandatory documents are Approved.
37. Approved documents are immutable.
38. A late rejection returns the flow to Step 4.

**Step 5 — Final Confirmation**
39. Company, Profile, and Document summaries are shown read-only.
40. The declaration checkbox is required; Finish Onboarding is disabled until ticked.
41. The server re-verifies completeness and all-approved documents before finishing.
42. Finish sets Submitted, vendor Pending Approval, and records `onboarding_complete`, `completed_at`, `completed_ip`, `completed_browser`, `completed_device`.
43. Finish redirects to Step 6.

**Step 6 — Final Approval**
44. Only Admin/Super Admin may approve, reject, hold, or release.
45. Approval issues a unique Registration Number `TPV-YYYY-NNNNN`.
46. Approval sets onboarding Approved, vendor Active, and activates login.
47. Rejection requires a remark and returns the vendor to Step 4.
48. Hold requires a reason and is releasable back to Under Review.
49. The Approved screen shows success hero, confetti, registration number, approver, date, support contact, and the Dashboard/Add Workforce/Logout buttons.

**Dashboard & Workforce**
50. The Dashboard is visible only after approval and activation.
51. The Workforce module unlocks only after the vendor is Active.
52. A worker activates only when the vendor is Active, profile is complete, age ≥ 18, medical passing, induction passed, and mandatory PPE issued.
53. Activation issues a badge number and QR token (default 1-year validity).
54. Aadhaar is unique per tenant.
55. Termination revokes the QR token and closes open attendance.
56. Three active strikes or one Critical strike terminate site access.

**Security, Tenancy & Audit**
57. Every table carries `tenant_id`; all reads are tenant-scoped.
58. Route-model binding is tenant-guarded (cross-tenant → 404).
59. RBAC restricts approval/review to Admin/Super Admin; management to Admin/Staff.
60. Passwords are hashed; tokens hidden from serialization.
61. Uploads validated by type and size; stored privately.
62. The client cannot set role or status (server-controlled).
63. Every state change is audit-logged with actor and metadata.
64. Auth, registration, and upload endpoints are rate-limited.
65. Registration numbers, approved documents, and audit entries are immutable.

**Temporary Third Party Vendor**
66. Only Admin, Super Admin, and Staff may create a Temporary TPV; a vendor cannot self-register as Temporary.
67. Creating a Temporary TPV requires an Access Start Date, an Access Expiry Date, and a Validity (1/3/7/15 days or custom).
68. The system generates temporary credentials and emails the temporary login on creation.
69. Creation, extension, expiry, and conversion each create an audit log.
70. A Temporary TPV carries `is_temporary = 1` and an `access_status` of Active/Expiring/Expired/Converted.
71. The countdown starts at `access_start_at` and ends at `access_expires_at`.
72. The countdown is shown on every screen: Login, Steps 1–6, Dashboard, and Workforce.
73. The countdown updates automatically and is reconciled with the server on each request.
74. Countdown colour: Green (> 3 days), Orange (< 3 days), Red (< 24 hours), Expired (0).
75. `seconds_remaining` is always computed server-side; the client never sets or extends the window.
76. When the countdown reaches zero, the system immediately terminates all active sessions.
77. On expiry the system blocks login, APIs, uploads, edits, and workforce for that vendor.
78. On expiry the vendor sees "Your temporary access has expired. Please contact your administrator."
79. Admin/Staff may extend access, reset the countdown, and change the expiry date.
80. Every extension requires a reason and is audit-logged.
81. Extending an expired Temporary TPV restores access with a new window.
82. Admin/Super Admin may convert a Temporary TPV to Permanent.
83. Conversion removes the temporary flag, removes expiry, issues a Registration Number, and enables permanent access.
84. Conversion preserves all onboarding and audit history.
85. The temporary banner is always visible for a Temporary TPV until expiry, conversion, or logout.
86. Reminder notifications are sent at 7 days, 3 days, 1 day, and 6 hours before expiry, and on expiry, extension, and conversion.
87. A Temporary TPV completes the identical six-step onboarding; temporary status changes access lifetime only, not the steps or validations.
88. Expiry, extension, and conversion notifications are delivered via Email, SMS, WhatsApp, and Portal.

---

# 9. Notification Flow

| Event | Email | SMS | WhatsApp | Portal | Admin |
|-------|:---:|:---:|:---:|:---:|:---:|
| Kickoff acknowledged | — | — | — | ✔ | — |
| Profile saved | — | — | — | ✔ | — |
| Document uploaded | — | — | — | ✔ | ✔ (queue count) |
| Document approved | ✔ | — | — | ✔ | — |
| Document rejected | ✔ | — | — | ✔ | — |
| Onboarding submitted | ✔ | — | — | ✔ | ✔ |
| Vendor approved | ✔ | ✔ | ✔ | ✔ | ✔ |
| Vendor rejected | ✔ | ✔ | ✔ | ✔ | — |
| Vendor on hold | ✔ | — | ✔ | ✔ | — |
| Worker ready | ✔ | — | — | ✔ | — |
| Temporary TPV created (credentials) | ✔ | ✔ | ✔ | ✔ | ✔ |
| Temporary expiry reminder (7d/3d/1d/6h) | ✔ | ✔ | ✔ | ✔ | — |
| Temporary access expired | ✔ | ✔ | ✔ | ✔ | ✔ |
| Temporary access extended | ✔ | ✔ | ✔ | ✔ | — |
| Converted to Permanent | ✔ | ✔ | ✔ | ✔ | ✔ |

**Rules:**
- The approval welcome is dispatched across Email, SMS, and WhatsApp and includes the Registration Number.
- Notification templates are configurable per tenant.
- Admin notifications surface on the admin console and via email digests.
- All notification dispatches are logged.

## 9.1 Temporary TPV Reminder Schedule
The scheduler evaluates active Temporary TPVs and dispatches reminders as the deadline approaches:

| Trigger | Channels | Content |
|---------|----------|---------|
| **7 days** before expiry | Email, SMS, WhatsApp, Portal | "Your temporary access expires in 7 days." |
| **3 days** before expiry | Email, SMS, WhatsApp, Portal | "Your temporary access expires in 3 days." |
| **1 day** before expiry | Email, SMS, WhatsApp, Portal | "Your temporary access expires tomorrow." |
| **6 hours** before expiry | Email, SMS, WhatsApp, Portal | "Your temporary access expires in 6 hours." |
| **Expired** | Email, SMS, WhatsApp, Portal | "Your temporary access has expired. Contact your administrator." |
| **Extended** | Email, SMS, WhatsApp, Portal | "Your temporary access has been extended to {date}." |
| **Converted** | Email, SMS, WhatsApp, Portal | "You are now a Permanent Vendor. Registration Number: {number}." |

Each reminder is sent once per threshold per window and is logged.

---

# 10. Audit Logs

Every important action generates an `audit_logs` entry capturing actor snapshot (id, name, role), action, subject, and metadata (IP, browser, device, from/to status, timestamps).

| Action | Trigger |
|--------|---------|
| `PDF Viewed` | Kickoff PDF opened |
| `PDF Downloaded` | Kickoff PDF downloaded |
| `PDF Printed` | Kickoff PDF printed |
| `Kickoff Accepted` | Acknowledgement recorded |
| `Profile Updated` | Save & Continue on Step 2 |
| `Draft Saved` | Save Draft on Step 2 |
| `Document Uploaded` | Document upload |
| `Document Replaced` | Document replace |
| `Document Deleted` | Document delete |
| `Document Approved` | Admin approves a document |
| `Document Rejected` | Admin rejects a document |
| `Document Resubmitted` | Vendor resubmits a rejected document |
| `Declaration Accepted` | Step 5 declaration ticked |
| `Onboarding Completed` | Finish Onboarding |
| `Vendor Approved` | Admin approves onboarding |
| `Vendor Rejected` | Admin rejects onboarding |
| `Vendor On Hold` | Admin holds onboarding |
| `Onboarding Released` | Admin releases hold |
| `Workforce Added` | Worker created |
| `Medical Completed` | Worker medical recorded |
| `Induction Completed` | Worker induction recorded |
| `QR Badge Generated` | Worker activated / badge issued |
| `Temporary TPV Created` | Admin/Staff creates a Temporary TPV (credentials generated) |
| `Temporary Access Extended` | Admin/Staff extends/renews the access window (with reason) |
| `Temporary Access Expired` | Countdown reaches zero / admin force-expires |
| `Temporary Expiry Reminder Sent` | 7d/3d/1d/6h reminder dispatched |
| `Temporary TPV Converted to Permanent` | Admin converts to Permanent (Registration Number issued) |
| `Login` | Vendor/user authenticates (session started) |
| `Session Timed Out` | Idle session expires |
| `Session Revoked` | A session is revoked (by the user or an admin) |
| `Force Logout` | Admin terminates a user's active sessions |
| `Remember-Me Enabled` | Remember Me selected at login |
| `Document Version Created` | New document version stored (upload / replace / resubmit) |
| `Document Version Restored` | A previous document version is restored |
| `Approval Level Passed` | A multi-level approval level is cleared |
| `Approval Delegated` | Approval authority delegated |
| `Approval Escalated` | A stalled approval level is escalated |
| `Registration Number Overridden` | Super Admin overrides the number before issuance |
| `Setting Updated` | A tenant configuration setting is changed |
| `Report Generated` | A report is generated / exported |

This table is the master audit-action registry; the actions referenced in §§14–22 are the same strings listed here.

Audit entries are immutable and tenant-scoped.

---

# 11. Permissions

| Capability | Super Admin | Admin | Staff | Third Party Vendor |
|------------|:---:|:---:|:---:|:---:|
| Configure document sets / templates / settings | ✔ | — | — | — |
| View onboarding list & stats | ✔ | ✔ | ✔ | own only |
| Manage onboarding steps (on behalf of vendor) | ✔ | ✔ | ✔ | own only |
| Upload / replace / delete documents | ✔ | ✔ | ✔ | own only |
| Review documents (approve/reject) | ✔ | ✔ | — | — |
| Approve / reject / hold / release onboarding | ✔ | ✔ | — | — |
| Issue Registration Number | ✔ (auto) | ✔ (auto) | — | — |
| Access vendor Dashboard | — | — | — | ✔ (after approval) |
| Manage Workforce | ✔ | ✔ | ✔ | ✔ (own, after approval) |
| Activate worker / issue QR badge | ✔ | ✔ | — | ✔ (own) |
| View audit logs | ✔ | ✔ | ✔ (scoped) | own subset |
| Create Temporary TPV | ✔ | ✔ | ✔ | — |
| Extend / renew temporary access | ✔ | ✔ | ✔ | — |
| Force-expire temporary access | ✔ | ✔ | — | — |
| Convert Temporary → Permanent | ✔ | ✔ | — | — |
| View own countdown / access status | — | — | — | ✔ (Temporary) |

**Enforcement:** RBAC via role middleware; approval/review and conversion restricted to Admin/Super Admin; temporary creation and extension available to Staff; all reads and writes tenant-scoped; the vendor sees only its own records.

---

# 12. UI / UX Guidelines

**Color System:** Primary violet `#7C3AED` / `#a78bfa`. Status — info `#0ea5e9`, pending/hold `#f59e0b`, approved/active `#10b981`, rejected `#ef4444`, neutral `#94a3b8`. Light and dark themes via CSS variables.

**Cards:** Glassmorphism panels, radius 16, soft borders, subtle shadow; KPI tiles; hover lift.

**Buttons:** Gradient primary (`linear-gradient(145deg,#a78bfa,#7C3AED)`); outline secondary; icon-only action buttons (30–34px); disabled state at 60% opacity with `not-allowed` cursor.

**Progress Bar:** Horizontal bar in Step 4 and Dashboard; animated fill; percentage label.

**Animations:** Smooth step transitions; inline upload spinners; **confetti** on the Approved final-status screen; hero entrance animation.

**Status Badges:** Rounded-full pills — Approved (green), Pending (amber), Rejected (red), Resubmit (violet), Hold (orange).

**Loading:** Skeleton placeholders for lists/cards; spinners for actions; optimistic UI where safe.

**Empty States:** Icon + heading + hint + primary action.

**Error States:** Inline red banners; field-level messages; retryable upload errors; toast for transient failures.

**Responsive Behaviour:** Fluid grids; wide tables scroll horizontally within their container (the page never scrolls sideways); the login left panel and wizard side content collapse on small screens; touch-friendly targets.

**Accessibility:** Labelled inputs, keyboard-navigable steps, visible focus rings, sufficient contrast, ARIA on the PDF viewer controls.

## 12.1 Temporary TPV UI/UX

**Temporary Banner:** full-width sticky strip at the top of every screen (Login, Steps 1–6, Dashboard, Workforce); "Temporary Third Party Vendor" pill + live countdown; colour band driven by remaining time.

**Countdown Widget:** `D Days HH Hours MM Minutes` format, ticking every second; colour thresholds — **Green** `#10b981` (> 3 days), **Orange** `#f59e0b` (< 3 days), **Red** `#ef4444` (< 24 hours), **Expired** grey/locked (0). Includes a Clock icon; escalates to an AlertTriangle in the red band.

**Expiry Screen:** centered red hero (XCircle), the expiry message, support-contact card, and a single Logout action; no other navigation.

**Convert Dialog:** modal with an ArrowUpCircle icon, a "Temporary → Permanent" indicator, consequence list, and a primary "Convert to Permanent Vendor" button.

**Extend Dialog:** modal with validity presets (1/3/7/15/custom) or a date picker, a required reason field, and a primary "Extend Access" button.

**Expiry Warning Popup:** non-blocking toast at each threshold (3 days, 1 day, 6 hours), colour-matched to the countdown band, with a "Contact Administrator" action and dismiss.

**Dashboard (Temporary):** a Temporary badge, a countdown widget, an expiry-warning strip, remaining-days figure, and an expiry alert as the deadline nears.

**Responsive/Accessibility:** the banner remains visible and legible on mobile (wraps to two lines if needed); the countdown has an ARIA live region announcing threshold changes; colour is paired with text/icon so it is not the sole signal.

---

# 13. Acceptance Criteria

Each screen must satisfy the following checklists.

## 13.1 Login
- **UI:** Split layout; email/password fields; show/hide toggle; error banner.
- **Functional:** Successful login routes to the resumed step; logout invalidates token.
- **Validation:** Email/password required; invalid credentials → 401.
- **API:** `POST /auth/login` returns token + user.
- **Database:** New token row; prior tokens revoked.
- **Test Cases:** Valid login; wrong password; pending account; expired temporary; suspended.
- **Edge Cases:** Concurrent login invalidates prior session.

## 13.2 Step 1 — Kickoff PDF
- **UI:** Viewer with zoom/print/download; acknowledgement checkbox; Continue disabled until ticked.
- **Functional:** View/Download/Print/Zoom work; ticking records acknowledgement and enables Continue.
- **Validation:** Continue blocked without acknowledgement (client + server).
- **API:** `GET …/kickoff`; `POST …/kickoff/accept`.
- **Database:** `acknowledged`, `acknowledged_at`, `acknowledged_ip`, `acknowledged_browser`, `acknowledged_device`, `status=In Progress`, `current_step=2`.
- **Test Cases:** Acknowledge → continue; re-enter shows accepted; forced continue without ack → 422.
- **Edge Cases:** PDF load failure; download blocked; idempotent re-acknowledge.

## 13.3 Step 2 — Company Profile
- **UI:** Seven sections; Save Draft + Save & Continue.
- **Functional:** Draft persists; Continue validates and advances.
- **Validation:** Required fields; GST/PAN/IFSC/account patterns; pincode 6 digits.
- **API:** `POST …/profile`; `POST …/profile/draft`.
- **Database:** `profile` JSON; GST/PAN/company mirrored; optional bank row.
- **Test Cases:** Valid save; invalid GST; invalid PAN; partial bank; draft then continue.
- **Edge Cases:** Duplicate GST/PAN; re-entry after rejection.

## 13.4 Step 3 — Legal Documents
- **UI:** Checklist rows with status badges; per-row actions; progress header.
- **Functional:** Upload/Replace/Preview/Download/Delete; progress updates.
- **Validation:** PDF/PNG/JPG/JPEG ≤ 8 MB; unknown type rejected; approved immutable.
- **API:** checklist; upload; replace; delete; download.
- **Database:** `vendor_documents` rows (Pending on upload).
- **Test Cases:** Upload each mandatory; oversize file; wrong format; replace pending; delete pending.
- **Edge Cases:** Replace approved blocked; slow upload retry.

## 13.5 Step 4 — Under Review
- **UI:** Counters, progress %, status banner, admin remarks, Refresh + Auto Refresh.
- **Functional:** Auto-refresh reflects decisions; resubmit rejected docs; Continue enables only when all mandatory approved.
- **Validation:** Resubmit only rejected; file rules enforced.
- **API:** checklist (poll); resubmit; (admin) review.
- **Database:** status/remarks/reviewed_by/reviewed_at updates; resubmit → Pending.
- **Test Cases:** Approve all → Continue enabled; reject one → banner + resubmit; late rejection returns to Step 4.
- **Edge Cases:** Concurrent decisions; resubmit network failure.

## 13.6 Step 5 — Final Confirmation
- **UI:** Company/Profile/Document summaries; declaration + checkbox; Finish disabled until ticked.
- **Functional:** Finish submits and redirects to Step 6.
- **Validation:** Declaration required; re-verify all-approved + profile complete.
- **API:** `POST …/submit`.
- **Database:** `declaration_accepted_at`, `onboarding_complete`, `completed_*`, `status=Submitted`, vendor `Pending Approval`.
- **Test Cases:** Finish success; forced finish without checkbox → 422; last-moment rejection blocks.
- **Edge Cases:** Re-verification catches rejected doc.

## 13.7 Step 6 — Final Approval
- **UI:** Four outcomes (Pending/Approved/Hold/Rejected); Approved shows confetti, registration number, buttons.
- **Functional:** Admin approve/reject/hold/release; vendor sees correct outcome.
- **Validation:** Reject/Hold require remark/reason.
- **API:** approve/reject/hold/release.
- **Database:** status, approved_by/at, registration_number, remarks/hold_reason; vendor Active; login active; temporary expiry set.
- **Test Cases:** Approve → registration number + Active; reject → back to Step 4; hold → release → Under Review.
- **Edge Cases:** Re-approve blocked; registration number immutable.

## 13.8 Dashboard
- **UI:** Header, KPIs, progress card, quick actions.
- **Functional:** Visible only after approval; expiry countdown for temporary.
- **Validation:** Access requires Active + not expired.
- **API:** `GET /tpv/dashboard`.
- **Database:** read-only; activity from audit logs.
- **Test Cases:** Approved vendor sees Dashboard; pre-approval redirected to wizard.
- **Edge Cases:** Mid-session expiry locks Dashboard.

## 13.9 Workforce
- **UI:** Worker list; five-step wizard; badge/QR card.
- **Functional:** Add worker; medical; induction; PPE; activate → Worker Ready.
- **Validation:** Vendor Active; age ≥ 18; medical passing; induction passed; mandatory PPE; Aadhaar unique.
- **API:** workers CRUD; medical/induction/ppe; activate; badge.
- **Database:** worker + record tables; badge_number, qr_token, badge_valid_until on activation.
- **Test Cases:** Full worker onboarding → Ready; Unfit blocks; missing PPE blocks; underage blocks.
- **Edge Cases:** Termination revokes QR; strike thresholds terminate access.

## 13.10 Temporary Third Party Vendor
- **UI:** Sticky temporary banner + live countdown on Login, Steps 1–6, Dashboard, and Workforce; colour bands (green/orange/red/expired); expiry screen; extend/convert dialogs; expiry-warning popups.
- **Functional:** Admin/Staff create a Temporary TPV with start/expiry/validity; credentials generated and emailed; countdown ticks and reconciles with the server; admin extends/renews/force-expires; admin converts to Permanent.
- **Validation:** Expiry after start; validity ∈ {1,3,7,15,custom}; extension reason required; conversion is admin-only.
- **API:** `POST /tpv/vendors/temporary`, `…/access/extend`, `…/access/expire`, `…/access/convert`, `GET /tpv/access/countdown`, `GET …/access/status`.
- **Database:** `is_temporary`, `access_start_at`, `access_expires_at`, `access_status`, `access_extended_at`, `access_extended_by`, `extension_reason`, `converted_to_permanent_at`, `converted_by`, `temporary_created_by`, `validity_days`.
- **Test Cases:** Create 1/3/7/15-day vendor → countdown correct; login before expiry works; extend resets countdown; convert issues Registration Number and removes banner; reminders fire at 7d/3d/1d/6h.
- **Edge Cases:** Countdown reaches zero → sessions terminated, login/APIs/uploads/edits/workforce blocked, expiry screen shown; extend an expired vendor restores access; conversion preserves all history and audit; multi-tab countdown stays consistent; clock skew corrected on server reconcile.

---

# 14. Session Management

## 14.1 Policy
- **Session Timeout (idle):** an authenticated session expires after **30 minutes of inactivity** (configurable, see §21). A warning popup appears 2 minutes before timeout with **Stay Signed In** / **Logout**. On timeout the session is invalidated and the user is redirected to Login.
- **Absolute Token Lifetime:** the Bearer token expires **30 days** after issue regardless of activity.
- **Force Logout:** an Admin/Super Admin may terminate any user's active sessions from the admin console ("Force Logout"). All that user's tokens are revoked immediately; the next request returns `401`.
- **Multiple-Device Login Policy:** the default policy is **single active session** — a new login revokes prior tokens. The tenant may switch to **multi-device** (up to N concurrent devices, configurable) in System Configuration; when the cap is exceeded, the oldest session is revoked.
- **Remember Me:** an optional login checkbox. When ticked, the refresh window is extended (e.g., 30 days idle instead of 30 minutes); when unticked, the idle timeout applies. Remember-Me tokens are device-bound and revoked on password change or Force Logout.
- **Concurrent Login Handling:** each active session is recorded (device, browser, IP, last-seen). The user sees "Active Sessions" and may revoke any. When single-session policy is active, logging in elsewhere ends the current session and shows "You have been signed out because your account was used on another device."

## 14.2 Database
`user_sessions` — `id, tenant_id, user_id, token_id, device, browser, ip, remember_me(bool), last_activity_at, created_at, revoked_at`. Index: `user_id, revoked_at`.

## 14.3 APIs
- `GET /api/auth/sessions` → list active sessions for the current user.
- `DELETE /api/auth/sessions/{id}` → revoke a session.
- `POST /api/admin/users/{id}/force-logout` *(admin)* → revoke all of a user's sessions.
- `POST /api/auth/heartbeat` → update `last_activity_at` (extends idle window).

## 14.4 Audit Logs
`Login`, `Session Timed Out`, `Session Revoked`, `Force Logout`, `Remember-Me Enabled`. (`Login` is the same audit action recorded by the Login screen in §4.1.)

## 14.5 Business Rules
- Idle timeout, absolute lifetime, and policy values are tenant-configurable.
- Force Logout is Admin/Super Admin only and is always audited.
- A revoked or timed-out session is rejected on the next request (`401`).
- Temporary TPV expiry overrides all session rules — an expired Temporary TPV cannot hold any session.

---

# 15. Document Versioning

## 15.1 Policy
- Every upload, replace, or resubmission of a document **creates a new version**; previous versions are **retained**, never destroyed.
- Each document exposes a **Version History** (version number, uploader, timestamp, status at the time, file reference).
- An Admin/Staff may **Restore a Previous Version**, which promotes that file to current and sets status back to **Pending** for re-review.
- The **current version** is the one shown in the checklist and used for review; superseded versions are read-only.
- Approved documents retain their approved version in history even after a later resubmission.

## 15.2 Database
`vendor_document_versions` — `id, tenant_id, vendor_document_id, version_no, file_path, original_name, mime, size, status_at_upload, uploaded_by, uploaded_at, is_current(bool), restored_from_version_id(null)`. Index: `vendor_document_id, version_no`.
`vendor_documents.current_version_no` tracks the active version.

## 15.3 APIs
- `GET /api/tpv/documents/{id}/versions` → version history.
- `GET /api/tpv/documents/{id}/versions/{versionId}/download` → download a specific version.
- `POST /api/tpv/documents/{id}/versions/{versionId}/restore` *(admin/staff)* → restore a previous version (new current version, status Pending).

## 15.4 Audit Logs
`Document Version Created`, `Document Version Restored`.

## 15.5 Business Rules
- Replace/resubmit never overwrites a prior file; it appends a version and flips `is_current`.
- Restoring a version is audited with `restored_from_version_id`.
- Version files inherit the same private storage and retention rules.
- Version history is visible in the Activity Timeline (§20) and the document row.

## 15.6 UI
Each document row shows a **History** icon opening a version drawer: a chronological list with Download and (admin) Restore per version, and a "Current" badge on the active version.

---

# 16. Admin Approval Workflow (Advanced)

## 16.1 Approval Modes
- **Single Approval (default):** one Admin decision approves the onboarding (§4.7).
- **Multi-Level Approval:** the tenant may require an ordered chain (e.g., **L1 Staff Review → L2 Admin Approve → L3 Super Admin Sign-off**). Each level must approve before the next unlocks; any level may reject or hold, returning the flow appropriately. The number and roles of levels are configurable (§21).
- **Approval Delegation:** an approver may delegate their authority to another eligible user for a date range (with reason). Delegated approvals are recorded against both the delegate and the original approver.
- **Escalation:** if an approval level remains pending beyond an **SLA** (e.g., 48 hours, configurable), the system escalates — notifies the next-higher role and flags the item as **Escalated** on the admin console.

## 16.2 Approval History
Every decision at every level is recorded with approver, level, decision, remark, and timestamp, and is shown as an **Approval History** panel on the onboarding detail and in the Activity Timeline (§20).

## 16.3 Database
`onboarding_approvals` — `id, tenant_id, tpv_onboarding_id, level, approver_role, approver_id, delegated_by, decision(approve/reject/hold), remarks, sla_due_at, escalated_at, decided_at, created_at`. Index: `tpv_onboarding_id, level`.
`approval_delegations` — `id, tenant_id, delegator_id, delegate_id, reason, starts_at, ends_at, created_at`.

## 16.4 APIs
- `GET /api/tpv/onboarding/{id}/approvals` → approval chain + history.
- `POST /api/tpv/onboarding/{id}/approve` — advances the current level (existing endpoint; level-aware).
- `POST /api/admin/approval-delegations` *(admin)* → create a delegation.
- `POST /api/tpv/onboarding/{id}/escalate` *(system/admin)* → escalate a stalled level.

## 16.5 Audit Logs
`Approval Level Passed`, `Approval Delegated`, `Approval Escalated` (in addition to `Vendor Approved` / `Vendor Rejected` / `Vendor On Hold`).

## 16.6 Business Rules
- Final approval (last level) issues the Registration Number and activates the vendor.
- A rejection at any level returns the vendor to Step 4; a hold freezes the chain until released.
- Delegation is time-boxed and audited; an expired delegation reverts authority automatically.
- Escalation never auto-approves — it only notifies and flags.

---

# 17. Registration Number Rules

Extends §6.11.
- **Tenant-wise uniqueness:** the Registration Number is unique per tenant; the sequential counter is scoped per tenant.
- **Financial-Year support:** the year segment supports **calendar year** or **financial year** (e.g., `FY2026-27`), selectable per tenant in System Configuration; the counter resets at the start of the configured year type.
- **Prefix configuration:** the prefix (default `TPV`) is tenant-configurable; format is `{PREFIX}-{YEAR}-{NNNNN}` with a configurable padding width.
- **Manual override restrictions:** the number is auto-generated on final approval. A **Super Admin** may manually override it **only** before it is issued/immutable, must supply a reason, and the override is audited (`Registration Number Overridden`). Once issued, the number is immutable; no role may edit it.
- **Atomicity:** generation is transactional to prevent duplicates under concurrency.

**Configuration keys:** `registration.prefix`, `registration.year_type` (`calendar|financial`), `registration.padding`, `registration.allow_manual_override`.

---

# 18. Dashboard Widgets

The vendor Dashboard (§4.8) presents the following widgets. Each is a card with its own loading, empty, and error state.

| Widget | Content | Source |
|--------|---------|--------|
| **Pending Tasks** | Outstanding onboarding actions (e.g., "Resubmit GST", "Complete profile") with deep links | onboarding + document checklist |
| **Document Status** | Approved / Pending / Rejected counts + mini progress bar | documents checklist |
| **Countdown Widget** | Temporary access countdown with colour band *(Temporary TPV only)* | access fields (§6.13) |
| **Notifications** | Latest unread portal notifications with mark-as-read | notifications |
| **Recent Activity** | Chronological recent events (see §20) | audit logs |
| **Workforce Summary** | Total / Active / Suspended / Ready workers + Add Worker CTA | workers |

**Rules:**
- Widgets render only when the vendor is approved (except the Countdown, which is visible throughout for Temporary TPVs).
- Widgets are responsive (stack on mobile) and lazy-loaded.
- The Notifications and Pending Tasks widgets update on the same interval as portal notifications.

**API:** `GET /api/tpv/dashboard` returns `{ pending_tasks, document_status, countdown, notifications, recent_activity, workforce_summary }`.

---

# 19. Search, Filters & Sorting

## 19.1 Vendor / Onboarding List (admin)
- **Search:** by company name, vendor code, registration number, email, GST, PAN.
- **Filters:** onboarding status; vendor type (standard/temporary); temporary access status (Active/Expiring/Expired/Converted); approval level; date range (submitted/approved).
- **Sort:** by created date, submitted date, approved date, company name, expiry date (asc/desc).

## 19.2 Documents
- **Search:** by document type/label and filename.
- **Filters:** by **status** (Pending/Approved/Rejected/Resubmit/Expired), by **expiry** (expiring within 30/60/90 days, expired), by **document type**.
- **Sort:** by upload date, review date, expiry date, status.

## 19.3 API
- `GET /api/tpv/onboarding?search=&status=&vendor_type=&access_status=&sort=&direction=&from=&to=`
- `GET /api/tpv/vendors/{vendor}/documents?search=&status=&type=&expiry=&sort=&direction=`

## 19.4 Rules
- All list queries are tenant-scoped and paginated.
- Filters combine with AND; search is case-insensitive partial match.
- Sort and filter state persists per user session.

---

# 20. Activity Timeline

## 20.1 Purpose
A single chronological timeline of the complete onboarding journey, shown on the onboarding detail (admin) and, in a vendor-appropriate subset, on the vendor Dashboard.

## 20.2 Events (in order of occurrence)
`Login → Kickoff Viewed → Kickoff Accepted → Profile Draft Saved → Profile Updated → Document Uploaded → Document Replaced → Document Version Created → Document Approved / Rejected → Document Resubmitted → Declaration Accepted → Onboarding Completed → Approval Level Passed → Vendor Approved / Rejected / On Hold → Registration Number Issued → Workforce Added → Medical Completed → Induction Completed → QR Badge Generated`, plus Temporary events (`Temporary TPV Created`, `Temporary Access Extended`, `Temporary Access Expired`, `Converted to Permanent`).

## 20.3 UI
Vertical timeline: each entry shows an icon, action label, actor, timestamp, and optional detail (remark, from→to status). Grouped by day; newest-first toggle; filterable by event category (Auth, Profile, Documents, Approval, Workforce, Temporary).

## 20.4 Source & API
- Driven by `audit_logs` (tenant-scoped, subject = onboarding/vendor).
- `GET /api/tpv/onboarding/{id}/timeline?category=&from=&to=` → ordered events.

## 20.5 Rules
- The timeline is read-only and immutable.
- Vendors see their own subset; admins see all entries including internal review actions.

---

# 21. System Configuration

Admin/Super Admin configurable settings, stored per tenant and read at runtime. Changes are audited (`Setting Updated`).

| Setting | Key | Default | Notes |
|---------|-----|---------|-------|
| Allowed file size | `documents.max_size_mb` | 8 | Enforced on upload/resubmit |
| Allowed file types | `documents.allowed_types` | `pdf,png,jpg,jpeg` | Enforced on upload/resubmit |
| Countdown warning thresholds | `temporary.thresholds` | `7d,3d,1d,6h` | Reminder + colour bands |
| Countdown colour bands | `temporary.bands` | `green>3d, orange<3d, red<24h` | Banner colours |
| Registration prefix | `registration.prefix` | `TPV` | Number prefix |
| Registration year type | `registration.year_type` | `calendar` | `calendar` or `financial` |
| Temporary validity defaults | `temporary.default_validity_days` | `7` (options 1/3/7/15/custom) | Create dialog default |
| Idle session timeout | `session.idle_minutes` | 30 | §14 |
| Multi-device policy | `session.max_devices` | 1 | §14 |
| Approval mode | `approval.mode` | `single` | `single` or `multi_level` |
| Approval levels | `approval.levels` | — | Ordered roles for multi-level |
| Approval SLA | `approval.sla_hours` | 48 | Escalation threshold |
| Notification templates | `notifications.templates` | system defaults | Per-event, per-channel content |

## 21.1 Database
`tenant_settings` — `id, tenant_id, key, value(json), updated_by, updated_at`. Unique `[tenant_id, key]`.

## 21.2 APIs
- `GET /api/admin/settings` → all settings for the tenant.
- `PUT /api/admin/settings/{key}` *(admin/super admin)* → update a setting.

## 21.3 Rules
- Every setting read falls back to the system default when unset.
- File-size/type changes apply to subsequent uploads only.
- Only Super Admin may change approval mode/levels and registration prefix/year type.

---

# 22. Reports

Tenant-scoped, exportable (CSV/PDF), and filterable by date range.

| Report | Contents | Endpoint |
|--------|----------|----------|
| **Pending Vendors** | Vendors with onboarding Submitted/Under Review awaiting decision | `GET /api/tpv/reports/pending` |
| **Approved Vendors** | Approved vendors with Registration Number, approver, date | `GET /api/tpv/reports/approved` |
| **Rejected Vendors** | Rejected vendors with reason and rejecting admin | `GET /api/tpv/reports/rejected` |
| **Temporary Vendors** | Active Temporary TPVs with access window and remaining time | `GET /api/tpv/reports/temporary` |
| **Expired Vendors** | Temporary TPVs whose access has expired | `GET /api/tpv/reports/expired` |
| **Workforce Status** | Workers by status (Draft/Active/Suspended/Terminated), medical/induction/PPE completion, badge validity | `GET /api/tpv/reports/workforce` |

**Rules:**
- Reports are role-gated (Admin/Staff) and tenant-scoped.
- Each report supports filters (date range, vendor type, status) and export.
- Report generation is logged (`Report Generated`).

---

# 23. Non-Functional Requirements

**Performance:** API responses ≤ 300 ms p95 for reads and ≤ 800 ms p95 for writes under nominal load; document upload handles 8 MB files without blocking the UI (async with progress); list endpoints paginated (default 25).

**Scalability:** stateless API behind a load balancer; horizontal scaling; the countdown and reminders scale via a scheduler/queue; storage on object storage; the design supports thousands of vendors and tens of thousands of documents per tenant.

**Security:** Sanctum bearer auth; RBAC; row-level tenant isolation; hashed passwords; private document storage; validated uploads (type/size/content); rate limiting on auth/registration/upload; CSRF-safe token flow; XSS/SQL-injection protections; secrets and tokens never exposed in list payloads; TLS in transit and encryption at rest.

**Backup:** automated daily database backups with point-in-time recovery; document storage versioned and replicated; backups encrypted; restore tested periodically.

**Audit Retention:** `audit_logs` retained **≥ 7 years**, immutable, tenant-scoped; export supported for compliance.

**Browser Support:** latest two versions of Chrome, Edge, Firefox, and Safari.

**Mobile Responsiveness:** all screens (Login, Steps 1–6, Dashboard, Workforce, banners, dialogs) are fully responsive; wide tables scroll horizontally within their container; touch-friendly targets.

**Accessibility:** WCAG 2.1 AA — labelled inputs, keyboard navigation, visible focus, sufficient contrast, ARIA live regions (countdown, status banners), colour never the sole signal.

**Logging:** structured application logs, auth logs, notification-dispatch logs, and the immutable audit trail; correlation IDs across requests; log levels configurable.

**Disaster Recovery:** documented RPO ≤ 24 hours and RTO ≤ 4 hours; multi-AZ deployment; failover runbook; periodic DR drills.

---

*End of document — Third_Party_Vendor_Onboarding_PRD_v1.0.md*
