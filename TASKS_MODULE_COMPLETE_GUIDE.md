# TASKS MODULE - COMPLETE GUIDE

## TABLE OF CONTENTS
1. [Overview](#overview)
2. [Database Schema](#database-schema)
3. [Core Features](#core-features)
4. [Task Lifecycle](#task-lifecycle)
5. [Integration with Other Modules](#integration-with-other-modules)
6. [Key Functions](#key-functions)

---

## OVERVIEW

### What is the Tasks Module?

The Tasks module is a **work management system** that allows you to:
- Create and assign work items to staff
- Track task progress through statuses
- Log time spent on tasks
- Organize tasks with checklists
- Link tasks to projects, customers, tickets, invoices, etc.
- Bill customers for task time

### Key Characteristics

**PURPOSE**: Work items that need to be completed

**SCOPE**: Can be standalone or part of larger entities (projects, tickets, etc.)

**GOAL**: Complete assigned work and track time/progress

**FLEXIBILITY**: Tasks can exist independently OR be related to:
- Projects
- Customers
- Tickets
- Invoices
- Estimates
- Contracts
- Proposals
- Leads
- Expenses

---

## DATABASE SCHEMA

### Primary Table: `tbltasks`

```sql
CREATE TABLE `tbltasks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  
  -- Basic Information
  `name` longtext DEFAULT NULL,                  -- Task name/title
  `description` text,                            -- Detailed description (HTML)
  `priority` int(11) DEFAULT NULL,               -- Priority level
  `dateadded` datetime NOT NULL,                 -- Creation timestamp
  `startdate` date NOT NULL,                     -- Start date
  `duedate` date DEFAULT NULL,                   -- Due date
  `datefinished` datetime DEFAULT NULL,          -- Completion timestamp
  
  -- Status & Progress
  `status` int(11) NOT NULL DEFAULT 1,           -- Current status (1-5)
  `is_added_from_contact` tinyint(1) NOT NULL DEFAULT 0,  -- Customer created?
  
  -- Assignment
  `addedfrom` int(11) NOT NULL,                  -- Staff who created
  
  -- Relationship (What is this task for?)
  `rel_id` int(11) DEFAULT NULL,                 -- Related entity ID
  `rel_type` varchar(40) DEFAULT NULL,           -- Type: project, invoice, ticket, etc.
  
  -- Project-specific
  `milestone` int(11) DEFAULT 0,                 -- Project milestone ID
  `milestone_order` int(11) NOT NULL DEFAULT 0,  -- Order within milestone
  
  -- Billing
  `billable` tinyint(1) DEFAULT 0,               -- Can be invoiced?
  `billed` tinyint(1) DEFAULT 0,                 -- Already invoiced?
  `invoice_id` int(11) DEFAULT NULL,             -- Which invoice?
  `hourly_rate` decimal(15,2) DEFAULT 0.00,      -- Rate for this task
  
  -- Recurring Tasks
  `recurring` int(11) DEFAULT 0,                 -- Is recurring?
  `recurring_type` varchar(10) DEFAULT NULL,     -- day/week/month/year
  `repeat_every` int(11) DEFAULT 0,              -- Repeat frequency
  `cycles` int(11) NOT NULL DEFAULT 0,           -- Total cycles (0=infinite)
  `total_cycles` int(11) NOT NULL DEFAULT 0,     -- Cycles completed
  `custom_recurring` int(11) NOT NULL DEFAULT 0, -- Custom pattern?
  `last_recurring_date` date DEFAULT NULL,       -- Last auto-creation date
  `is_recurring_from` int(11) DEFAULT NULL,      -- Original recurring task ID
  
  -- Visibility & Notifications
  `is_public` tinyint(1) NOT NULL DEFAULT 0,     -- Visible to all staff?
  `visible_to_client` tinyint(1) NOT NULL DEFAULT 0,  -- Customer can see?
  `deadline_notified` int(11) NOT NULL DEFAULT 0,     -- Reminder sent?
  
  -- Kanban
  `kanban_order` int(11) DEFAULT 0,              -- Position in kanban board
  
  PRIMARY KEY (`id`),
  KEY `rel_id` (`rel_id`),
  KEY `rel_type` (`rel_type`),
  KEY `status` (`status`),
  KEY `milestone` (`milestone`)
) ENGINE=InnoDB;
```

### Related Tables

#### `tbltask_assigned` - Task Assignees
```sql
CREATE TABLE `tbltask_assigned` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `taskid` int(11) NOT NULL,
  `staffid` int(11) NOT NULL,                    -- Internal staff
  `assigned_from` int(11) NOT NULL,              -- Who assigned
  `is_assigned_from_contact` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `staffid` (`staffid`)
) ENGINE=InnoDB;
```

#### `tbltask_tpvendor_assigned` - Third-Party Vendor Assignees
```sql
CREATE TABLE `tbltask_tpvendor_assigned` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `taskid` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,                  -- External vendor
  `assigned_from` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=InnoDB;
```

#### `tbltask_followers` - Task Followers (Watchers)
```sql
CREATE TABLE `tbltask_followers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `taskid` int(11) NOT NULL,
  `staffid` int(11) NOT NULL,                    -- Staff following task
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`),
  KEY `staffid` (`staffid`)
) ENGINE=InnoDB;
```

#### `tbltask_checklist_items` - Task Checklists
```sql
CREATE TABLE `tbltask_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `taskid` int(11) NOT NULL,
  `description` text NOT NULL,                   -- Checklist item text
  `finished` int(11) DEFAULT 0,                  -- Completed?
  `finished_from` int(11) DEFAULT NULL,          -- Who completed
  `dateadded` datetime NOT NULL,
  `addedfrom` int(11) NOT NULL,
  `list_order` int(11) NOT NULL DEFAULT 0,       -- Display order
  `assigned` int(11) DEFAULT NULL,               -- Assigned to staff
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`)
) ENGINE=InnoDB;
```

#### `tbltasks_checklist_templates` - Reusable Checklists
```sql
CREATE TABLE `tbltasks_checklist_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` mediumtext DEFAULT NULL,         -- Template text
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;
```

#### `tbltaskstimers` - Time Tracking
```sql
CREATE TABLE `tbltaskstimers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,                   -- Who is timing
  `start_time` datetime NOT NULL,                -- Timer start
  `end_time` datetime DEFAULT NULL,              -- Timer stop (NULL = running)
  `hourly_rate` decimal(15,2) NOT NULL DEFAULT 0.00,  -- Rate for this time
  `note` text,                                   -- Time entry note
  PRIMARY KEY (`id`),
  KEY `task_id` (`task_id`),
  KEY `staff_id` (`staff_id`)
) ENGINE=InnoDB;
```

#### `tbltask_comments` - Task Comments
```sql
CREATE TABLE `tbltask_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content` text NOT NULL,                       -- Comment text
  `taskid` int(11) NOT NULL,
  `staffid` int(11) NOT NULL,                    -- Staff who commented
  `contact_id` int(11) DEFAULT 0,                -- Customer who commented
  `file_id` int(11) DEFAULT 0,                   -- Attached file
  `dateadded` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `taskid` (`taskid`)
) ENGINE=InnoDB;
```

---

## CORE FEATURES

### 1. **Task Statuses**

```
STATUS 1: Not Started
  - Task created but work hasn't begun
  - Color: Gray (#64748b)
  - Default for new tasks (or based on start date)

STATUS 2: Awaiting Feedback
  - Waiting for customer/team response
  - Color: Lime (#84cc16)
  - Use when blocked by external input

STATUS 3: Testing
  - Work complete, in testing phase
  - Color: Sky Blue (#0284c7)
  - QA/review stage

STATUS 4: In Progress
  - Active work happening
  - Color: Blue (#3b82f6)
  - Default when task start date arrives

STATUS 5: Complete
  - Task finished
  - Color: Green (#22c55e)
  - datefinished timestamp recorded
```

**Auto Status Assignment** (on creation):
```
IF today >= startdate:
  status = 4 (In Progress)
ELSE:
  status = 1 (Not Started)

OR use global default from settings
```

### 2. **Task Assignment**

#### Assignees (Who does the work?)
- Can have multiple assignees
- Staff members OR vendor contacts
- Email notifications sent to assignees
- Tasks appear in assignee's task list

#### Followers (Who watches progress?)
- Staff who want updates but aren't doing work
- Receive notifications on task changes
- Can comment and collaborate
- Not required to complete task

**Example**:
```
TASK: "Design new logo"

Assignees:
  - Sarah (Designer) ← Does the work

Followers:
  - John (Project Manager) ← Monitors progress
  - Tom (Marketing) ← Interested in result
```

### 3. **Task Relationships (rel_type)**

Tasks can belong to:

#### Project Tasks
```
rel_type = 'project'
rel_id = project_id

All tasks for a project
Appears in project Tasks tab
Can be organized by milestones
```

#### Customer Tasks
```
rel_type = 'customer'
rel_id = customer_id

Tasks related to customer (not project-specific)
Appears in customer profile
```

#### Ticket Tasks
```
rel_type = 'ticket'
rel_id = ticket_id

Task created from support ticket
Links support issue to work item
```

#### Invoice Tasks
```
rel_type = 'invoice'
rel_id = invoice_id

Task related to invoicing work
```

#### Other Relations
- Estimate (`rel_type = 'estimate'`)
- Contract (`rel_type = 'contract'`)
- Lead (`rel_type = 'lead'`)
- Proposal (`rel_type = 'proposal'`)
- Expense (`rel_type = 'expense'`)

#### Standalone Tasks
```
rel_type = NULL
rel_id = NULL

Independent tasks not tied to anything
Internal work, admin tasks, etc.
```

### 4. **Time Tracking**

#### Start Timer
```
Developer clicks "Start Timer"
  ↓
Record created in tbltaskstimers:
  - task_id: 123
  - staff_id: 5
  - start_time: 2024-01-15 09:00:00
  - end_time: NULL (still running)
  - hourly_rate: $100
  ↓
Timer runs in background
```

#### Stop Timer
```
Developer clicks "Stop Timer"
  ↓
Update record:
  - end_time: 2024-01-15 11:30:00
  ↓
Time logged: 2 hours 30 minutes (2.5 hours)
Cost: 2.5 × $100 = $250
```

#### Multiple Timers
- Staff can run multiple timers simultaneously
- One timer per task
- View all running timers in header dropdown

#### Billable vs Non-Billable
- Mark task as billable
- All time logged becomes billable
- Generate invoice from billable time
- Mark task as "billed" to prevent double-billing

### 5. **Task Checklist**

**Purpose**: Break down task into subtasks

**Example**:
```
TASK: "Build User Authentication"

Checklist:
  ☑ Create database schema
  ☑ Build login page
  ☐ Implement password reset
  ☐ Add 2FA support
  ☐ Write tests
  ☐ Deploy to staging

Progress: 2/6 items (33%)
```

**Features**:
- Drag & drop reordering
- Assign checklist items to staff
- Mark as complete
- Track who completed each item
- Hide completed items (per user preference)
- Save as template for reuse

**Checklist Templates**:
```
TEMPLATE: "Code Review Checklist"
  - Check code style
  - Verify tests pass
  - Review security issues
  - Check performance
  - Update documentation

Apply to any task needing code review
```

### 6. **Recurring Tasks**

**Use Case**: Tasks that repeat regularly

**Examples**:
- Monthly server maintenance
- Weekly team meeting prep
- Daily backup verification
- Quarterly reports

**Configuration**:
```
Repeat Every: 1 week
Repeat Type: Week
Cycles: 12 (or 0 for infinite)

Result:
  - Original task created: 2024-01-01
  - Auto-creates copy: 2024-01-08
  - Auto-creates copy: 2024-01-15
  - ... 12 total

Each copy is independent task
```

**Custom Recurring**:
```
Every 2 months, 5 times:
  repeat_every = 2
  recurring_type = 'month'
  cycles = 5

Every 3 days, infinite:
  repeat_every = 3
  recurring_type = 'day'
  cycles = 0
```

### 7. **Task Priority**

**Priority Levels**:
- Low
- Medium
- High
- Urgent

**Visual Indicators**:
- Color-coded in task lists
- Sort tasks by priority
- Filter by priority

### 8. **Task Visibility**

#### Public Tasks
```
is_public = 1

All staff can see task
Useful for company-wide announcements
Team collaboration tasks
```

#### Private Tasks
```
is_public = 0

Only visible to:
  - Task creator
  - Assignees
  - Followers
  - Staff with "view_tasks" permission
```

#### Customer Visibility
```
visible_to_client = 1

Customer can see task in their portal
Used for project tasks
Customer can comment (if permission enabled)
```

### 9. **Task Comments & Collaboration**

**Comments**:
- Staff add updates
- Customers add feedback (if enabled)
- Support @mentions (notify specific people)
- Attach files to comments
- Edit/delete own comments

**Comment Notifications**:
```
New comment added
  ↓
Notification sent to:
  - Assignees
  - Followers
  - @mentioned staff
  - Project members (if project task)
```

### 10. **Task Attachments**

**File Upload**:
- Attach files directly to task
- Or attach to comments
- Supported types: documents, images, archives

**Storage**: `uploads/tasks/{task_id}/`

### 11. **Kanban Board**

**Visual Task Management**:
```
┌─────────────┬──────────────┬──────────────┬──────────────┬──────────────┐
│ NOT STARTED │ IN PROGRESS  │   TESTING    │   AWAITING   │   COMPLETE   │
│             │              │              │   FEEDBACK   │              │
├─────────────┼──────────────┼──────────────┼──────────────┼──────────────┤
│ Task A      │ Task D       │ Task G       │ Task J       │ Task M       │
│ Task B      │ Task E       │ Task H       │              │ Task N       │
│ Task C      │ Task F       │              │              │ Task O       │
│             │              │              │              │              │
└─────────────┴──────────────┴──────────────┴──────────────┴──────────────┘

Drag & drop between columns
kanban_order maintains position within column
```

**Kanban Features**:
- Filter by project
- Search tasks
- Sort by various fields
- Load more (pagination)
- Quick edit task

### 12. **Task Milestones** (Project Tasks Only)

**When**: Task is part of project

**Purpose**: Group tasks into project phases

```
PROJECT: Website Redesign

Milestone 1: Design Phase
  ├─ Task: Create wireframes
  ├─ Task: Design homepage
  └─ Task: Design subpages

Milestone 2: Development
  ├─ Task: Set up backend
  ├─ Task: Build homepage
  └─ Task: Integrate API

Uncategorized Tasks:
  └─ Task: Project kickoff meeting
```

### 13. **Task Billing**

#### Billable Task Setup
```
1. Create task
2. Check "Billable" checkbox
3. Set hourly_rate (or use project rate)
4. Assign to staff
5. Staff logs time
6. Time tracked in taskstimers
```

#### Generate Invoice from Tasks
```
1. Go to Invoices → New Invoice
2. Select customer
3. Click "Add tasks"
4. System shows billable, unbilled tasks
5. Select tasks to invoice
6. System calculates:
   - Total hours per task
   - Cost = hours × rate
   - Adds as invoice line items
7. Mark tasks as "billed"
8. Send invoice
```

**Example**:
```
TASK: "Build Admin Dashboard"
  Billable: Yes
  Rate: $120/hour
  
Time Logged:
  - Mon: 3.5 hours
  - Tue: 4.0 hours
  - Wed: 2.5 hours
  Total: 10 hours
  
Invoice Line Item:
  "Build Admin Dashboard"
  10 hours × $120 = $1,200
```

### 14. **Task Reminders**

**Set Reminders**:
- Remind specific staff members
- Set date/time for reminder
- Add description
- Email + in-app notification

**Reminder Types**:
- Task deadline approaching
- Custom reminders for follow-ups
- Recurring task reminders

### 15. **Task Copy**

**Clone Task**:
```
Copy task with options:
  ☑ Copy assignees
  ☑ Copy followers
  ☑ Copy checklist items
  ☑ Copy attachments
  ☐ Copy comments (not available)
  
Select target status for new task

Result: New independent task created
```

### 16. **Task Notes (Private)**

**Staff-Only Notes**:
- Hidden from customers
- Internal investigation notes
- Private discussion among team

**Use Case**:
```
TASK: "Fix login bug" (visible to customer)

Private Note:
  "Issue is in auth.php line 142
   Need to update OAuth library
   Will fix in next sprint"
   
Customer sees task but not the note
```

---

## TASK LIFECYCLE

### Lifecycle 1: Standalone Task

```
STEP 1: Task Creation
├─ Staff creates task: "Update company website"
├─ No rel_type (standalone)
├─ Set: Priority, Start Date, Due Date
├─ Assign to: Web Developer
└─ Add follower: Project Manager

STEP 2: Work Begins
├─ Developer receives notification
├─ Opens task
├─ Clicks "Start Timer"
└─ Status auto-changes to "In Progress"

STEP 3: Work in Progress
├─ Developer makes updates
├─ Adds comment: "Homepage updated"
├─ Uploads screenshot
├─ Completes checklist items
└─ Logs time: 3.5 hours

STEP 4: Completion
├─ Developer finishes work
├─ Clicks "Stop Timer"
├─ Changes status to "Complete"
├─ datefinished recorded
└─ Assignees and followers notified

RESULT:
✓ Task completed
✓ Time logged: 3.5 hours
✓ Work documented in comments
```

### Lifecycle 2: Project Task with Milestone

```
STEP 1: Project Task Creation
├─ Project: "Mobile App Development"
├─ Milestone: "Backend Development"
├─ Task: "Build API endpoints"
├─ rel_type = 'project'
├─ rel_id = 5 (project ID)
├─ milestone = 2
├─ Assign to: Backend Developer
└─ Mark as billable: Yes, $150/hour

STEP 2: Development Work
├─ Developer starts timer
├─ Works on task over 3 days
├─ Total time: 12.5 hours
├─ Adds comments with progress updates
└─ Completes checklist items

STEP 3: Testing Phase
├─ Changes status to "Testing"
├─ QA Tester assigned as follower
├─ QA runs tests
└─ Comments: "Found 2 bugs"

STEP 4: Bug Fixes
├─ Status back to "In Progress"
├─ Developer fixes issues
├─ Additional time: 2 hours
└─ Total time: 14.5 hours

STEP 5: Approval & Billing
├─ Status → "Complete"
├─ Milestone updated (1 more task done)
├─ Generate invoice:
│   14.5 hours × $150 = $2,175
├─ Mark task as "billed"
└─ Customer invoiced

RESULT:
✓ Task completed
✓ Milestone progress updated
✓ Customer invoiced
✓ Time tracked: 14.5 hours
```

### Lifecycle 3: Recurring Task

```
STEP 1: Create Recurring Task Template
├─ Task: "Monthly Server Maintenance"
├─ Start Date: 2024-01-01
├─ Repeat: Every 1 month
├─ Cycles: 12
├─ Assign to: System Admin
└─ Checklist:
    - Update OS packages
    - Check disk space
    - Review logs
    - Backup database

STEP 2: Auto-Creation (Cron Job)
├─ Cron runs daily
├─ Checks for recurring tasks
├─ Creates copy on due date:
│   - Original task (Jan) → Copy (Feb)
│   - Copy (Feb) → Copy (Mar)
│   ... and so on
├─ Each copy is independent
└─ is_recurring_from links to template

STEP 3: Monthly Completion
├─ Month 1: Admin completes Jan task
├─ Month 2: Admin completes Feb task (auto-created)
├─ Month 3: Admin completes Mar task
└─ ... continues for 12 months

STEP 4: Series Completion
├─ All 12 cycles completed
├─ total_cycles = 12
├─ No more auto-creation
└─ Recurring series ends

RESULT:
✓ 12 months of maintenance tasks
✓ Each task tracked independently
✓ Automated task creation
```

---

## INTEGRATION WITH OTHER MODULES

### 1. **Integration with Projects**

**Link**: `tbltasks.rel_type = 'project'` AND `tbltasks.rel_id = project_id`

**Features**:
- Tasks appear in project Tasks tab
- Can organize by milestones
- Project billing (task hours)
- Project progress auto-calculated from tasks

### 2. **Integration with Tickets**

**Link**: `tbltasks.rel_type = 'ticket'` AND `tbltasks.rel_id = ticket_id`

**Use Case**:
```
Customer submits ticket: "Feature request"
  ↓
Staff determines it requires development
  ↓
Creates task from ticket
  ↓
Task assigned to developer
  ↓
Developer completes task
  ↓
Staff updates ticket: "Feature implemented"
  ↓
Ticket closed
```

### 3. **Integration with Invoices**

**Links**:
- `tbltasks.billable = 1` - Can be invoiced
- `tbltasks.billed = 1` - Already invoiced
- `tbltasks.invoice_id` - Which invoice

**Workflow**: Create invoice → Select billable tasks → Generate line items

### 4. **Integration with Customers**

**Link**: `tbltasks.rel_type = 'customer'` AND `tbltasks.rel_id = customer_id`

**Use Case**: Customer-specific tasks not tied to projects

**Example**:
```
TASK: "Onboard new customer - ACME Corp"
rel_type = 'customer'
rel_id = 5 (ACME Corp ID)

Appears in ACME Corp profile
Not part of any project
```

### 5. **Integration with Third-Party Vendors**

**Link**: `tbltask_tpvendor_assigned.taskid` → `tbltasks.id`

**Use Case**: Outsource work to external vendors

```
TASK: "Quality Assurance Testing"

Assigned to:
  - Internal QA Lead (staff)
  - External QA Vendor (TPV)

Both receive notifications
Both can log time (separate tracking)
```

### 6. **Integration with Staff**

**Links**:
- `tbltask_assigned` - Who does work
- `tbltask_followers` - Who watches
- `tbltaskstimers.staff_id` - Who logs time

**Staff Dashboard Shows**:
- My assigned tasks
- My followed tasks
- My running timers
- Tasks I created

---

## KEY FUNCTIONS

### Controller: `Tasks.php`

**`index()` / `list_tasks()`** - Task list view
- Shows all tasks (with filters)
- Can switch between list and kanban views
- Filter by status, assignee, project, etc.

**`task($id)`** - Create/Edit task
- Form for task creation/editing
- Handle assignees, followers
- Process checklists
- Save attachments
- Customer vs staff view (different forms)

**`get_task_data($taskid)`** - View task modal
- Load complete task details
- Show in popup modal
- All task info, comments, timesheets

**`add_task_comment()`** - Add comment
- Create comment record
- Handle @mentions
- Attach files to comment
- Send notifications

**`add_task_assignees()`** - Assign staff
- Add staff to task
- Send notifications
- Update task

**`add_tpvendor_assignees()`** - Assign vendors
- Add external vendors
- Separate tracking

**`add_checklist_item()`** - Add checklist item
- Create checklist item
- Can assign to staff
- Set order

**`checkbox_action()`** - Toggle checklist
- Mark item complete/incomplete
- Record who completed

**`timer_tracking()`** - Start/stop timer
- Create timer record
- Update end_time
- Calculate duration

### Model: `Tasks_model.php`

**`get($id, $where)`** - Retrieve task(s)
- Get single task with full data
- Get list with filters
- Includes assignees, followers, comments, timesheets

**`add($data)`** - Create task
- Insert task record
- Add assignees
- Add followers
- Handle custom fields
- Send notifications
- Log activity

**`update($data, $id)`** - Update task
- Update task fields
- Update assignees/followers
- Handle status changes
- Trigger recurring task creation

**`get_task_assignees($id)`** - Get assignees
- Returns all assigned staff
- Used for permissions and notifications

**`get_task_followers($id)`** - Get followers
- Returns all followers
- Used for notifications

**`add_task_assignees($data)`** - Assign staff
- Add to tbltask_assigned
- Send notification

**`add_task_tpvendors($taskid, $vendors)`** - Assign vendors
- Add to tbltask_tpvendor_assigned

**`get_checklist_items($taskid)`** - Get checklist
- Returns all checklist items
- Includes completion status

**`add_checklist_item($data)`** - Create checklist item
- Insert item
- Optionally assign to staff

**`calc_task_total_time($taskid)`** - Calculate logged time
- Sum all timer entries
- Returns total seconds

**`get_billable_tasks($customer_id, $project_id)`** - Get tasks for invoicing
- Filter billable, unbilled tasks
- By customer or project

**`copy($data)`** - Clone task
- Create duplicate task
- Optionally copy assignees, followers, checklist
- Reset dates and status

---

## FILE LOCATIONS

**Controller**: `application/controllers/admin/Tasks.php`
**Model**: `application/models/Tasks_model.php`
**Views**: `application/views/admin/tasks/`
**Uploads**: `uploads/tasks/{task_id}/`

---

## TASK CONFIGURATION

**Global Settings** (Setup → Settings → Tasks):
- Default task status
- Auto-assign task to creator
- Auto-follow task when creating
- Default view (List/Kanban)
- Show all tasks to project members
- Allow customers to create tasks

**Per-Task Settings**:
- Billable: Yes/No
- Public: Yes/No
- Visible to customer: Yes/No
- Recurring: Yes/No

**Task Permissions** (per staff role):
- View tasks
- Create tasks
- Edit tasks
- Delete tasks

---

## SUMMARY

The Tasks module is the core work management system that:

✓ **Flexible**: Works standalone or linked to any entity
✓ **Time Tracking**: Built-in timer for accurate billing
✓ **Collaboration**: Comments, assignees, followers
✓ **Organized**: Checklists, milestones, statuses
✓ **Billable**: Convert time to invoices
✓ **Automated**: Recurring tasks, auto-status
✓ **Integrated**: Connects with all modules
✓ **Vendor Support**: Assign to internal or external teams

Tasks bridge the gap between planning (projects) and support (tickets), making them the workhorse of the entire CRM system.

---

This completes the Tasks module documentation.
