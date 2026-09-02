**VENDOR ONBOARDING UI WIREFRAME**

**(CRM – Multi-Step Wizard)**

**Core rule:**
UI is only a *presentation layer*.
**Status logic controls everything.**

**1️⃣ ENTRY POINT (LEFT NAVIGATION)**

TPV MANAGEMENT

└── Vendors

├── Vendor List

├── + New Vendor (Onboard)

└── Onboarding Tracker

Click **+ New Vendor (Onboard)** → Opens Wizard

**2️⃣ OVERALL WIZARD STRUCTURE**

**Layout**

* **Top Progress Bar** (Status-driven)
* **Left Step Indicator**
* **Right Form Area**
* **Bottom Action Buttons**

[ Step 1 ]──[ Step 2 ]──[ Step 3 ]──[ Step 4 ]──[ Step 5 ]──[ Step 6 ]

Progress is **read-only** (system-driven).

**3️⃣ STEP-BY-STEP WIREFRAME**

**🔹 STEP 1: Vendor Basic Details**

**(tbl\_tpv\_vendors)**

**Fields**

* Vendor Legal Name \*
* Trade / Brand Name
* Vendor Category (dropdown)
* Client (dropdown)
* Project (dropdown)
* Registered Address
* Authorized Signatory
* Mobile / Email

**Buttons**

* **Save as Draft**
* **Next**

**Status Impact**

* Draft → Draft

❌ Cannot proceed if mandatory fields missing

**🔹 STEP 2: Legal & Commercial Documents**

**(Doc 2 tables)**

**Sections (Accordion style)**

**A. Statutory**

* PAN (upload)
* GST (upload)

**B. Insurance**

* WC Policy (upload + expiry)
* CAR / TPL (if applicable)

**C. Contract**

* Work Order / Agreement upload
* Scope of Work (text)

**Visual Indicators**

* 🟢 Uploaded & Valid
* 🟠 Expiring Soon
* 🔴 Missing / Expired

**Buttons**

* **Save**
* **Next**

**Status Impact**

* Submitted → Docs\_Pending / Workforce\_Pending

**🔹 STEP 3: Workforce Readiness**

**(Doc 3 tables)**

**Panel 1: Workforce List**

Table:

* Name
* Skill
* Medical Status
* Induction Status
* Access Card Status

Button: **+ Add Worker**

**Panel 2: Readiness Summary**

* Medical Completed: **75%**
* Induction Completed: **60%**

**Buttons**

* **Save**
* **Next**

**Status Impact**

* Workforce\_Pending → Safety\_Pending (only if ready)

**🔹 STEP 4: Safety Readiness Declaration**

**(Bridge to Doc 4)**

**Checkboxes (Mandatory)**

* ☐ Safety rules acknowledged
* ☐ PPE responsibility accepted
* ☐ PTW compliance accepted
* ☐ Zero alcohol & substance policy

**Optional Upload**

* Vendor Safety Policy (PDF)

**Buttons**

* **Accept & Continue**

**Status Impact**

* Safety\_Pending → Under\_Review

**🔹 STEP 5: Review & Submit**

**(Read-only summary)**

**Sections**

* Vendor Details
* Documents Status
* Workforce Readiness
* Safety Declaration

**Buttons**

* **Edit Previous**
* **Submit for Approval**

**Status Impact**

* Under\_Review (approval workflow starts)

**🔹 STEP 6: Approval Tracker**

**(Doc 1 – Authority & Workflow)**

**Approval Timeline (Vertical)**

✔ PMC Approval (Approved)

⏳ Safety Approval (Pending)

⏳ Accounts Approval (Pending)

⏳ Admin Approval (Pending)

**Read-only for vendor**

**Action buttons visible only to approvers**

**Final Status**

* Approved → Active
* Or → Rejected (with remarks)

**4️⃣ POST-APPROVAL SCREEN (AUTO)**

When status = **Active**:

**Auto Actions**

* Enable PTW eligibility
* Enable workforce access cards
* Vendor visible in:
  + PTW
  + Observations
  + Invoices

**5️⃣ ONBOARDING TRACKER (IMPORTANT UI)**

**Vendor List View Columns**

* Vendor Name
* Category
* Current Status
* % Completion
* Blocking Reason
* Action

Example:

ABC Infra | Civil | Workforce\_Pending | 68% | Medical Expiry | View

**6️⃣ UI RULES (NON-NEGOTIABLE)**

Tell your developer:

* ❌ No manual status change buttons
* ❌ No skipping steps
* ❌ No “force approve”
* ✔ Status driven by backend logic only
* ✔ UI reads status, never sets it

**7️⃣ PERFEXCRM / CI IMPLEMENTATION HINTS**

* One controller: Vendor\_onboarding.php
* Step-wise views:
  + step\_1\_basic.php
  + step\_2\_docs.php
  + …
* Status check middleware before rendering each step
* Use Perfex hooks for approval notifications

**8️⃣ WHAT THIS WIREFRAME ACHIEVES**

* Zero loopholes
* Clear responsibility
* Audit-friendly
* Easy for vendors
* Easy for internal teams
* No future rework

**✅ ONBOARDING UI IS NOW COMPLETE**

You now have:

* Status logic ✅
* Gate conditions ✅
* UI wireframe ✅

Your developer has **everything needed** to implement Vendor Onboarding correctly.

**NEXT (OPTIONAL BUT POWERFUL)**

I can next:

1. Review your developer’s **actual UI screens**
2. Provide **exact DB enums for status**
3. Create a **simple flow diagram image** for internal training

Just tell me what you want next.