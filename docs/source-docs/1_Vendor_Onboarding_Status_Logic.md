**WHAT IS MOST IMPORTANT FOR DEVELOPER (PRIORITY ORDER)**

**🥇 1. Vendor Onboarding STATUS LOGIC (MOST IMPORTANT)**

**🥈 2. Vendor Onboarding UI WIREFRAME (SECOND)**

**🥉 3. Review of implementation (comes after coding starts)**

So I will **first deliver the STATUS LOGIC** (this controls database, workflow, permissions), and then **UI wireframe guidance**.

**PART 1: VENDOR ONBOARDING STATUS LOGIC**

**(THIS IS THE FOUNDATION – DEVELOPER MUST FOLLOW)**

**Core Principle**

**Vendor onboarding is a state machine**, not a single form.

**🔹 VENDOR MASTER STATUS (tbl\_tpv\_vendors.status)**

| **Status** | **Meaning** | **Who Can Change** |
| --- | --- | --- |
| Draft | Vendor details saved, not submitted | Admin / PMC |
| Submitted | Vendor applied for onboarding | Admin |
| Docs\_Pending | Mandatory docs missing | System |
| Workforce\_Pending | Manpower not ready | System |
| Safety\_Pending | Safety readiness not approved | Safety |
| Under\_Review | Internal approvals running | System |
| Approved | Vendor fully onboarded | Admin |
| Active | Vendor allowed to work | System |
| Suspended | Temporary block | PMC / Safety |
| Rejected | Onboarding failed | Admin |
| Offboarded | Vendor exited | Admin |

**🔹 SYSTEM GATE CONDITIONS (CRITICAL)**

**Gate 1: Commercial & Legal Gate (Doc 2)**

Status moves from **Submitted → Workforce\_Pending** only if:

* Mandatory documents uploaded
* Contract / WO uploaded
* Insurance valid

❌ Fail → Docs\_Pending

**Gate 2: Workforce Readiness Gate (Doc 3)**

Status moves from **Workforce\_Pending → Safety\_Pending** only if:

* At least 1 worker added
* Medical valid
* Induction completed

❌ Fail → Workforce\_Pending

**Gate 3: Safety Gate (Doc 4)**

Status moves from **Safety\_Pending → Under\_Review** only if:

* Safety declaration accepted
* No critical safety rejection
* PTW eligibility verified

❌ Fail → Safety\_Pending

**Gate 4: Internal Approval Gate (Doc 1)**

Status moves from **Under\_Review → Approved** only if:

* PMC approval
* Safety approval
* Accounts approval
* Admin final approval

**Gate 5: Activation Gate**

Status moves from **Approved → Active** when:

* Access cards issued
* Site assigned

**🔹 AUTO-SUSPENSION LOGIC (POST ONBOARDING)**

Vendor auto-moves to **Suspended** if:

* Insurance expired
* Fatal incident
* Stop Work Authority active
* Critical VRS breach

**🔹 DEVELOPER NOTE (VERY IMPORTANT)**

Tell your developer:

“Do NOT use free-text status.
Status must follow this exact flow.
No skipping gates.”

**PART 2: VENDOR ONBOARDING UI WIREFRAME (DEVELOPER GUIDANCE)**

This is **secondary**, but still important.

**🔹 UI TYPE**

**Multi-step Wizard (Left → Right or Top → Bottom)**

**STEP 1: Vendor Basic Info**

(Write → tbl\_tpv\_vendors)

* Vendor Name
* Category
* Client
* Project
* Contact details

Button: **Save as Draft**

**STEP 2: Legal & Commercial Docs**

(Write → Doc 2 tables)

* PAN, GST
* Insurance uploads
* Work Order upload

System shows:
✔ Uploaded
❌ Missing
⏳ Expiring soon

**STEP 3: Workforce Readiness**

(Write → Doc 3 tables)

* Add workers
* Upload medical
* Show induction status

Progress bar:

* Medical % complete
* Induction % complete

**STEP 4: Safety Readiness**

(Bridge Doc 2 → Doc 4)

* Safety declaration checkbox
* PPE responsibility
* PTW acceptance

**STEP 5: Review & Submit**

(Read-only summary)

* Vendor details
* Docs status
* Workforce readiness
* Safety readiness

Button: **Submit for Approval**

**STEP 6: Approval Tracker**

(Read-only)

Shows:

* PMC: Pending / Approved
* Safety: Pending / Approved
* Accounts: Pending / Approved
* Admin: Pending / Approved

**🔹 VISUAL CUE (IMPORTANT)**

Use:

* 🟢 Green = Complete
* 🟠 Amber = Pending
* 🔴 Red = Blocked

**WHAT YOU SHOULD TELL YOUR DEVELOPER (COPY–PASTE)**

“First implement **Vendor Onboarding Status Logic** exactly as defined.
Then build UI wizard on top of it.
UI cannot override status rules.”

**FINAL CONFIDENCE STATEMENT**

If your developer implements:

* ✅ Status logic first
* ✅ Gates strictly
* ✅ UI as a wizard

Then:

* No onboarding loopholes
* No compliance gaps
* No future rework

**NEXT STEP (WHEN READY)**

If you want, I can next:

* Give **exact status enum values for DB**
* Review your developer’s onboarding code
* Create a **simple flow diagram image** you can share

Just tell me what you want next.