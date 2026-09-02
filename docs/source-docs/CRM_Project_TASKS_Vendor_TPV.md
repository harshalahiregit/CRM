# ✅ CRM UPDATE DOCUMENT (CHANGES & FIXES ONLY)

## ⚠️ IMPORTANT INSTRUCTION

* This is for the **existing CRM system**
* ❌ **Do NOT change any existing working features**
* ✅ Only **ADD / FIX the below points**
* Maintain current structure, UI, and logic — just extend it

# 🔹 1. VENDOR & TPV IMPROVEMENTS

### ✅ Vendor Code

* Vendor code should be **auto-generated**
* No manual entry required

### ✅ Vendor & TPV Structure

* Vendors and TPVs should have:
  + Their own employees
* Employees must be linked to:
  + Vendor OR TPV (not both)

### ✅ Direct Vendor Rule

* “Direct Vendor” = Vendor who receives **direct payment from Nexfore**
* Only these vendors should be treated as **Direct Vendors**

### ❗ Important Clarification

* **Client (B) is NOT a Vendor of Nexfore (A)**
* Do not mix Client and Vendor logic

# 🔹 2. DASHBOARD FIXES

### Fix Vendor Dashboard:

* Align UI properly (spacing, layout, responsiveness)

### Fix TPV Dashboard:

* Ensure it is fully working

### Add in BOTH Dashboards:

* Projects
* Tasks
* Tickets
* Knowledge Base

# 🔹 3. PROJECT & TASK SYSTEM IMPROVEMENTS

### ✅ Increase Task Creation Area

* Make task input section bigger
* Improve usability for long descriptions

### ✅ Link Behavior (GLOBAL CHANGE)

Anywhere in CRM (important):

* Task description
* Project description
* Notes / text

👉 If user adds a link:

* It should **open in a NEW WINDOW (default)**

# 🔹 4. ASSIGNEE SYSTEM (VERY IMPORTANT FIX)

### ⚠️ This is ASSIGNEE logic (not assignment)

### Current Requirement:

While creating **Project / Task**:

### Step 1:

* User selects:
  + Vendor OR TPV

### Step 2:

* Based on selection:
  + Show **only that Vendor’s employees**
  + OR
  + Show **only that TPV’s employees**

### Step 3:

* User selects employees (assignees)

### ✅ Example:

* Select Vendor → show Vendor employees → select them
* Select TPV → show TPV employees → select them

# 🔹 5. ACCESS CONTROL (VISIBILITY)

### Rule:

Project / Task should be visible ONLY to:

* Selected Vendor / TPV
* Selected Employees under them

### ❌ No Access:

* Other vendors
* Other employees

# 🔹 6. SUBTASK SYSTEM (MAJOR ADDITION)

### Structure:

Project
 → Task
 → Subtask
 → Subtask (nested)
 → Subtask (nested...)

### Requirements:

* Every task should support **subtasks**
* Subtasks can be **nested (multiple levels)**

### Tracking System:

For each task:

* Total subtasks
* Completed subtasks
* Pending subtasks
* Progress %

### Example:

* 5 subtasks total
* 2 completed
  👉 Progress = 40%

# 🔹 7. BACKUP SYSTEM (AUTOMATION)

### Requirement:

* CRM should take **daily backup automatically**

### Storage:

* OneDrive OR pCloud

### Rules:

* Keep only **last 3–4 backups**
* Older backups → automatically delete

### Example:

CRM\_Backups/
 backup\_1
 backup\_2
 backup\_3
 backup\_4

# 🔥 FINAL SUMMARY (FOR DEVELOPER)

### You need to:

✔ Add Vendor code auto-generation
✔ Fix Vendor & TPV dashboards
✔ Add modules in dashboards (Projects, Tasks, Tickets, Knowledge Base)
✔ Implement Assignee logic (Vendor/TPV → Employees)
✔ Apply strict visibility control
✔ Add nested Subtask system with progress tracking
✔ Increase task input area
✔ Make all links open in new window
✔ Implement daily backup with auto deletion (3–4 limit)

# ✅ FINAL NOTE

* This is **NOT a new system**
* This is **enhancement + fixes in existing CRM**
* Keep everything existing as it is
* Only implement above features cleanly without breaking current functionality