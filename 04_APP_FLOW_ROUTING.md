# Application Flow & Routing Document
## Multi-Tenant SaaS CRM Platform

---

## 1. ROUTING STRUCTURE

### Frontend Routes (React Router v6)

```
/                                          (Landing / Public)
├── / → Dashboard (if authenticated) or Login
├── /auth
│   ├── /login                            (Login page)
│   ├── /register                         (Tenant registration)
│   ├── /register/setup                   (Subdomain + plan selection)
│   ├── /register/payment                 (Payment for paid plans)
│   ├── /verify-email                     (Email verification)
│   └── /forgot-password                  (Password recovery)
│
├── /app                                  (Protected, requires auth + tenant)
│   ├── /dashboard                        (Main dashboard)
│   │   └── (KPI cards, charts, activity feed)
│   │
│   ├── /contacts                         (Contact management)
│   │   ├── /                             (Contact list)
│   │   ├── /new                          (Create contact)
│   │   ├── /:id                          (Contact detail)
│   │   ├── /:id/edit                     (Edit contact)
│   │   ├── /:id/deals                    (Related deals)
│   │   ├── /:id/tasks                    (Related tasks)
│   │   └── /import                       (Bulk import)
│   │
│   ├── /leads (or /deals)                (Deal management)
│   │   ├── /                             (Deals list / pipeline board)
│   │   ├── /new                          (Create deal)
│   │   ├── /:id                          (Deal detail)
│   │   ├── /:id/edit                     (Edit deal)
│   │   ├── /:id/tasks                    (Related tasks)
│   │   ├── /:id/invoices                 (Related invoices)
│   │   └── /board                        (Kanban board view)
│   │
│   ├── /tasks                            (Task management)
│   │   ├── /                             (Task list)
│   │   ├── /new                          (Create task)
│   │   ├── /:id                          (Task detail)
│   │   ├── /:id/edit                     (Edit task)
│   │   └── /board                        (Kanban board)
│   │
│   ├── /projects                         (Project management)
│   │   ├── /                             (Project list)
│   │   ├── /new                          (Create project)
│   │   ├── /:id                          (Project detail)
│   │   ├── /:id/edit                     (Edit project)
│   │   ├── /:id/tasks                    (Project tasks)
│   │   └── /:id/team                     (Team assignments)
│   │
│   ├── /invoices                         (Invoice management)
│   │   ├── /                             (Invoice list)
│   │   ├── /new                          (Create invoice)
│   │   ├── /:id                          (Invoice detail / view)
│   │   ├── /:id/edit                     (Edit invoice)
│   │   ├── /:id/payment                  (Record payment)
│   │   └── /:id/send                     (Send invoice)
│   │
│   ├── /payments                         (Payment tracking)
│   │   ├── /                             (Payment list)
│   │   └── /:id                          (Payment detail)
│   │
│   ├── /purchase-orders (or /purchase)   (PO management)
│   │   ├── /                             (PO list)
│   │   ├── /new                          (Create PO)
│   │   ├── /:id                          (PO detail)
│   │   └── /:id/edit                     (Edit PO)
│   │
│   ├── /vendors                          (Vendor management)
│   │   ├── /                             (Vendor list)
│   │   ├── /new                          (Add vendor)
│   │   ├── /:id                          (Vendor detail)
│   │   ├── /:id/edit                     (Edit vendor)
│   │   ├── /:id/pos                      (Vendor's POs)
│   │   └── /:id/performance              (Vendor metrics)
│   │
│   ├── /tickets                          (Support tickets)
│   │   ├── /                             (Ticket list)
│   │   ├── /new                          (Create ticket)
│   │   ├── /:id                          (Ticket detail)
│   │   └── /:id/edit                     (Edit ticket)
│   │
│   ├── /reports                          (Reporting & Analytics)
│   │   ├── /sales                        (Sales report)
│   │   ├── /revenue                      (Revenue report)
│   │   ├── /activity                     (Activity report)
│   │   ├── /invoice                      (Invoice report)
│   │   └── /custom                       (Custom report builder)
│   │
│   └── /settings                         (Admin & Settings)
│       ├── /profile                      (User profile)
│       ├── /account                      (Account settings)
│       ├── /billing                      (Billing & subscription)
│       ├── /team                         (Team members)
│       ├── /roles                        (Roles & permissions)
│       ├── /integrations                 (API, webhooks)
│       ├── /customfields                 (Custom field builder)
│       ├── /workflows                    (Automation workflows, Phase 2)
│       └── /security                     (2FA, sessions)
│
└── /vendor-portal                        (Vendor login)
    ├── /login                            (Vendor login)
    ├── /app
    │   ├── /dashboard                    (Vendor dashboard)
    │   ├── /orders                       (POs assigned to vendor)
    │   ├── /:id                          (PO detail)
    │   └── /profile                      (Edit vendor profile)
```

---

## 2. AUTHENTICATION FLOW

### 1. Public Landing Page
```
User visits https://app.com
↓
Sees CTA: "Sign Up" / "Login"
```

### 2. Login Flow
```
User clicks "Login"
↓
[Login Screen]
  - Email input
  - Password input
  - "Remember me" checkbox
  - "Forgot password?" link
  - "Sign up" link
↓
API: POST /api/auth/login {email, password}
  ← Returns: {access_token, user, tenant}
↓
Token stored in localStorage
↓
Redirects to /app/dashboard (if OWNER/ADMIN/USER)
Redirects to /vendor-portal/dashboard (if VENDOR)
```

### 3. Register Flow (New Tenant)
```
User clicks "Sign Up"
↓
[Register Screen]
  - Name input
  - Email input
  - Password input (with strength meter)
  - Company name
  - Terms acceptance checkbox
  - "Sign in" link
↓
API: POST /api/auth/register {name, email, password, company}
  ← Returns: {tenant_id, access_token}
↓
[Subdomain Selection]
  - Suggest: company-name.app.com
  - Allow user to edit
  - Check availability (real-time validation)
  - Proceed button
↓
API: POST /api/tenants/{tenant_id}/setup {subdomain}
↓
[Plan Selection]
  - Display: Starter, Professional, Enterprise
  - Show features per plan
  - Default: Starter (free trial)
  - Select & Continue
↓
If paid plan:
  [Payment Screen]
    - Stripe card input
    - Billing address
    - Submit payment
↓
API: POST /api/payments/setup {plan_id, stripe_token}
↓
[Email Verification]
  - "Check your email to verify"
  - Resend link button
↓
User clicks link in email
  API: POST /api/auth/verify-email {token}
↓
Redirects to /app/dashboard
```

### 4. Forgot Password Flow
```
User clicks "Forgot Password?"
↓
[Email Input]
  - Enter registered email
  - Send button
↓
API: POST /api/auth/forgot-password {email}
↓
"Check your email for password reset link"
↓
User clicks link in email
  → /auth/reset-password?token=xxx
↓
[Password Reset Screen]
  - New password input
  - Confirm password
  - Reset button
↓
API: POST /api/auth/reset-password {token, password}
↓
Redirects to /auth/login
```

---

## 3. MAIN APP FLOW (POST-LOGIN)

### A. Dashboard (Landing Page)
```
User logs in → /app/dashboard
↓
[Header]
  - Logo + Tenant name
  - Search bar (global search)
  - Notifications (bell icon, unread count)
  - User menu (profile, settings, logout)
↓
[Sidebar]
  - Dashboard (home icon)
  - Contacts (people icon)
  - Deals (briefcase icon)
  - Tasks (checkbox icon)
  - Projects (folder icon)
  - Invoices (receipt icon)
  - Vendors (truck icon)
  - Tickets (life-buoy icon)
  - Reports (chart-bar icon)
  - Settings (gear icon)
↓
[Main Content - KPI Cards]
  - Total contacts
  - Open deals (by value)
  - Tasks due today
  - Overdue invoices
  - New leads this month
↓
[Charts]
  - Revenue by month (line chart)
  - Pipeline by stage (bar chart)
  - Deal win rate (pie chart)
↓
[Activity Feed]
  - Recent activities (created deal, logged call, sent invoice)
  - Timestamp + user
  - "View all" link
```

---

## 4. CONTACTS MODULE FLOW

### List View
```
User clicks "Contacts" in sidebar → /app/contacts
↓
[Header]
  - Page title: "Contacts"
  - "New Contact" button (blue CTA)
  - Search box
↓
[Filters Sidebar]
  - Search by name/email/phone
  - Filter by: Company, Source, Date range
  - Sort by: Name, Date added
↓
[Table]
  - Columns: Name, Company, Email, Phone, Last Activity, Actions
  - Sortable columns (click header)
  - Pagination: "Show 10/25/50 per page"
  - Bulk actions: Select all → (Delete, Export, Assign)
↓
[Row Actions]
  - Each row has: View, Edit, Delete (in menu)
  - Click row → Navigate to detail
```

### Detail View
```
User clicks on contact → /app/contacts/:id
↓
[Header]
  - Breadcrumb: Home > Contacts > John Doe
  - Back button
  - Edit button
  - Delete button (in menu)
↓
[Main Panel - Contact Info]
  - Name, Email, Phone (editable inline)
  - Company, Job Title, Address (editable)
  - Custom fields (as configured)
  - Status (Active/Inactive)
  - Save Changes button
↓
[Tabs / Sections]
  1. Overview
     - Contact info (as above)
     - Tags
     - Notes
  2. Deals
     - Linked deals table (Name, Value, Stage, Close date)
     - "New Deal" button
  3. Tasks
     - Related tasks (Title, Assigned to, Due date)
     - "New Task" button
  4. Activity Timeline
     - All actions: Created, Called, Emailed, Deal linked
     - Timeline view with dates
  5. Files
     - Uploaded documents, contracts
     - Upload button
```

### Create/Edit Flow
```
User clicks "New Contact" → /app/contacts/new
OR
User clicks "Edit" on detail → /app/contacts/:id/edit
↓
[Form]
  - First Name *
  - Last Name *
  - Email *
  - Phone (optional)
  - Company
  - Job Title
  - Address, City, State, ZIP, Country
  - Source dropdown (Website, Referral, Cold Call, Import, etc.)
  - Custom fields (if configured)
↓
[Validation]
  - Required fields marked *
  - Real-time email validation
  - Phone number formatting
↓
[Buttons]
  - Save & Close
  - Save & New (create another)
  - Cancel
↓
On Save:
  API: POST /api/contacts (create) or PUT /api/contacts/:id (update)
  ← Success toast
  Redirect to detail or list
```

---

## 5. DEALS / LEADS MODULE FLOW

### Pipeline Board View
```
User clicks "Deals" → /app/deals (default: board view)
↓
[Header]
  - "Deals" title
  - View toggle: Table / Board (default board)
  - "New Deal" button
  - Filter: by assigned user, date range
↓
[Kanban Board]
  Columns: New → Qualified → Proposal → Won / Lost
  ↓
  Cards per column:
    - Deal name
    - Contact name
    - Deal value
    - Close date (red if overdue)
    - Owner avatar
    - Click → Opens modal or detail page
↓
[Drag & Drop]
  - Drag card to new stage
  - API: PUT /api/deals/:id {stage: "qualified"}
  - Confirmation: Toast "Deal moved to Qualified"
```

### List View
```
User toggles to Table → /app/deals?view=table
↓
[Table Columns]
  - Deal Name
  - Contact
  - Value
  - Stage
  - Owner
  - Expected Close Date
  - Probability (%)
  - Actions menu
↓
[Sorting & Filtering]
  - Sort by: Stage, Value, Close date
  - Filter by: Owner, Stage, Value range, Date range
↓
[Row Actions]
  - View detail
  - Edit
  - Convert to invoice
  - Delete
```

### Detail View
```
User clicks deal → /app/deals/:id
↓
[Header]
  - Deal name + Status badge (color-coded stage)
  - Back, Edit, Delete (menu)
↓
[Main Panel]
  - Deal Title (editable)
  - Contact: Dropdown (searchable)
  - Value: $X (editable)
  - Stage: Dropdown (new/qualified/proposal/won/lost)
  - Probability: % (auto-calculated or manual)
  - Expected Close Date: Date picker
  - Owner: Assigned user (dropdown)
  - Notes: Rich text area
  - Save button
↓
[Tabs]
  1. Overview (as above)
  2. Contacts
     - Primary contact
     - Add secondary contacts
  3. Tasks
     - Related tasks
     - "New Task" button
  4. Invoices
     - Invoices generated from deal
     - "Create Invoice" button
  5. Timeline / Activity
     - Deal history (created, stage changed, etc.)
  6. Files
     - Documents, contracts
```

### Create Deal Flow
```
User clicks "New Deal" → /app/deals/new
↓
[Form]
  - Deal Name * (e.g., "Acme Corp Website Redesign")
  - Contact * (searchable dropdown)
    - Can create new contact inline?
  - Value * (decimal)
  - Currency (default: USD)
  - Stage (default: New)
  - Probability (default: 0%)
  - Expected Close Date * (date picker)
  - Owner (default: current user)
  - Notes
  - Custom fields
↓
[Buttons]
  - Create Deal
  - Cancel
↓
On Create:
  API: POST /api/deals {name, contact_id, value, stage, ...}
  → Redirect to detail page
```

---

## 6. TASKS MODULE FLOW

### Board View
```
User clicks "Tasks" → /app/tasks/board
↓
[Columns]
  To Do → In Progress → Done
↓
[Cards]
  - Title
  - Assigned to: Avatar
  - Due date (with due indicator: red/yellow/gray)
  - Priority: Icon (1-5 stars or H/M/L)
  - Linked to: Deal/Contact icon with label
↓
[Drag & Drop]
  - Move between statuses
  - API: PUT /api/tasks/:id {status: "done"}
```

### List View
```
User clicks list icon → /app/tasks
↓
[Table]
  - Task, Assigned to, Due date, Priority, Status, Project
  - Sort by: Due date (ascending), Priority
  - Filter by: Owner, Status, Priority, Date range
```

### Create/Edit Task
```
User clicks "New Task"
↓
[Form]
  - Title *
  - Description (optional, rich text)
  - Assigned to * (dropdown, can assign to self)
  - Due Date * (date picker)
  - Priority (1-5 or High/Medium/Low)
  - Status (To Do / In Progress / Done)
  - Link to: Deal (optional, dropdown)
  - Link to: Project (optional, dropdown)
  - Link to: Contact (optional, dropdown)
↓
[Buttons]
  - Create Task
  - Cancel
```

---

## 7. INVOICES MODULE FLOW

### List View
```
User clicks "Invoices" → /app/invoices
↓
[Header]
  - "Invoices" title
  - "New Invoice" button
  - Filter: Status (Draft, Sent, Paid, Overdue)
  - Export button (CSV/PDF)
↓
[Table]
  - Invoice Number
  - Customer (Contact name)
  - Amount
  - Status (color badge)
  - Issue Date
  - Due Date
  - Actions (View, Edit, Send, Delete)
↓
[Pagination]
  - Show 25 per page
```

### Detail / View
```
User clicks invoice → /app/invoices/:id
↓
[Header]
  - "Invoice #INV-2024-001"
  - Status badge (Draft, Sent, Paid, Overdue)
  - Buttons: Edit, Send, Download PDF, Print, Delete
↓
[Invoice Content]
  - Company header (logo, address)
  - Customer info: Name, Address, Email
  - Invoice details:
    - Issue Date
    - Due Date
    - Invoice Number
↓
[Line Items Table]
  - Description, Quantity, Unit Price, Tax, Total
  - Subtotal, Tax amount, Discount, Total
↓
[Notes/Terms]
  - Terms & conditions
  - Additional notes
↓
[Payment Section]
  - Payment status: Unpaid / Partially Paid / Paid
  - If unpaid: "Pay Now" button (links to Stripe checkout)
  - Payment history: List of recorded payments
```

### Create Invoice Flow
```
User clicks "New Invoice" → /app/invoices/new
↓
[Form]
  - Contact * (searchable dropdown)
    - Auto-populate: Company address, email
  - Issue Date * (default: today)
  - Due Date * (default: +30 days)
  - Currency (default: USD)
  - Line Items:
    * Add line item:
      - Description
      - Quantity
      - Unit Price
      - Tax %
    * Add button to add more rows
    * Trash icon to remove row
  - Subtotal (auto-calculated)
  - Tax (auto-calculated)
  - Discount amount / %
  - Total (auto-calculated)
  - Notes (optional, for internal use)
  - Terms (optional, displayed to customer)
↓
[Buttons]
  - Save as Draft
  - Save & Send (opens send modal)
  - Cancel
↓
[Send Modal]
  - Recipient email (from contact)
  - Email subject (template)
  - Email body (template)
  - Send button
↓
On Send:
  API: POST /api/invoices/:id/send {email}
  → Queue job to send email
  → Change status to "Sent"
  → Notification: "Invoice sent to customer"
```

---

## 8. VENDOR PORTAL FLOW

### Vendor Login
```
Vendor visits https://app.com/vendor-portal
↓
[Login]
  - Email input
  - Password input
  - "Login" button
↓
API: POST /api/vendor/auth/login {email, password}
  ← Returns: {access_token, vendor}
↓
Redirects to /vendor-portal/app/dashboard
```

### Vendor Dashboard
```
/vendor-portal/app/dashboard
↓
[Header]
  - Vendor name
  - Logout link
  - Profile (edit contact info)
↓
[Main Content]
  - Welcome message
  - Stats:
    - Open POs
    - Completed orders
    - Upcoming deliverables
↓
[Quick Links]
  - View Orders button
  - Contact admin
```

### Vendor Orders
```
Vendor clicks "Orders" → /vendor-portal/app/orders
↓
[Table]
  - PO Number
  - Status (Draft, Sent, Received)
  - Order Date
  - Expected Delivery
  - Actions: View
↓
Vendor clicks PO → Detail page
  - Shows: Items, quantities, dates
  - Comments section (vendor can add updates)
  - "Mark as Received" button (if status = Sent)
```

---

## 9. SETTINGS & ADMIN FLOW

### Account Settings
```
User clicks profile → Menu → Settings → /app/settings/account
↓
[Sections]
  1. Profile
     - Name, Email, Phone
     - Avatar upload
     - Save button
  2. Password
     - Current password
     - New password
     - Confirm password
     - Change button
  3. Preferences
     - Timezone
     - Notification settings (email, in-app)
     - Dark mode toggle
```

### Team Management (Admin Only)
```
Admin → Settings → Team → /app/settings/team
↓
[Team Members List]
  - Name, Email, Role, Status
  - Invite button
↓
[Invite Modal]
  - Email *
  - Role (Admin, User, Vendor) *
  - Invite button
  ↓
  API: POST /api/team/invite {email, role}
  → Email sent with registration link
  ← "Invitation sent to email@example.com"
↓
[Member Actions]
  - Edit role
  - Remove (revoke access)
```

### Billing (Admin Only)
```
Admin → Settings → Billing → /app/settings/billing
↓
[Current Plan]
  - Plan name (Starter, Professional, Enterprise)
  - Billing cycle (Monthly, Annual)
  - Renewal date
  - Upgrade / Downgrade buttons
↓
[Billing History]
  - Invoice list with download links
↓
[Payment Method]
  - Saved card (last 4 digits)
  - Update payment method button
```

### Custom Fields
```
Admin → Settings → Custom Fields → /app/settings/customfields
↓
[Custom Fields per Module]
  - Contacts: List of custom fields
  - Deals: List
  - Tasks: List
↓
[Add Custom Field]
  - Field name
  - Field type (Text, Number, Dropdown, Date, etc.)
  - Required? (checkbox)
  - Add button
↓
[Edit Field]
  - Edit name, type, required
  - Delete field (confirmation)
```

---

## 10. GLOBAL INTERACTIONS

### Search (Cmd+K / Ctrl+K)
```
User presses Cmd+K
↓
[Search Modal]
  - Input: "Search contacts, deals, tasks..."
  - Real-time results:
    - Contacts: John Doe, jane@email.com
    - Deals: Acme Corp Website
    - Tasks: Review proposal
  - Keyboard navigation: Arrow keys, Enter to select
  - Click result → Navigate to detail
```

### Notifications
```
[Bell icon in header]
  - Shows unread count
  - Click → Dropdown with recent notifications:
    - "Deal Won: Acme Corp ($50K)" — 2 hours ago
    - "Task assigned to you: Review docs" — 5 hours ago
    - "Invoice paid" — 1 day ago
  - "View all" link → /app/notifications
  - Notifications auto-dismiss after reading
```

### Modals
```
Common Confirmation Modal:
  - Title: "Are you sure?"
  - Message: "This action cannot be undone."
  - Buttons: Cancel (white), Delete (red)

Form Modal:
  - Title: "Create new [entity]"
  - Form fields
  - Buttons: Cancel, Create/Save
```

---

## 11. ERROR & EMPTY STATES

### Empty State Example (No Contacts)
```
[Illustration: Empty briefcase]
"No contacts yet"
↓
[Description]
"Add your first contact to get started."
↓
[CTA Button]
"Create Contact" → /app/contacts/new
```

### Error State (API Failure)
```
[Error Icon]
"Oops, something went wrong"
↓
[Message]
"Unable to load contacts. Please try again."
↓
[Button]
"Retry" → Refetch API
```

### Loading State
```
[Skeleton Loaders]
  - Table rows shown as gray placeholders
  - Shimmer animation
  - "Loading..." text (optional)
```

---

## 12. LOGOUT FLOW

```
User clicks profile icon → Logout
↓
API: POST /api/auth/logout {token}
↓
Clear localStorage (access_token, user, tenant)
↓
Redirect to /auth/login
```

---

## 13. PROTECTED ROUTES & PERMISSIONS

### Route Protection
```
Route /app/* requires:
  1. Valid access_token in localStorage
  2. Token not expired
  3. Tenant context set (from token or subdomain)

If missing:
  → Redirect to /auth/login
```

### Role-Based Access
```
ADMIN_ONLY:
  - Settings > Team
  - Settings > Billing
  - Reports (full access)

USER / ADMIN:
  - Create, edit own records
  - View all contacts, deals, tasks

VENDOR:
  - View only assigned POs
  - Comment on orders
  - Edit own profile
```

---

## 14. RESPONSIVE MOBILE FLOW

### Sidebar → Hamburger Menu (on <640px)
```
[Hamburger icon top-left]
Click → Sidebar slides in from left
Click backdrop → Sidebar closes
```

### Modals → Full-screen (on mobile)
```
Modals appear full-screen on mobile
Buttons at bottom (sticky)
```

### Table → Card View (on mobile)
```
Tables render as stacked cards
Each card shows key columns
Swipe for more actions
```

---

## 15. OFFLINE & SYNC BEHAVIOR

### Future Considerations (Phase 2)
- Service Worker for offline PWA mode
- IndexedDB for local data caching
- Auto-sync when online
- Conflict resolution strategy

---

## 16. KEYBOARD SHORTCUTS REFERENCE

| Shortcut | Action |
|----------|--------|
| `Cmd/Ctrl + K` | Global search |
| `Cmd/Ctrl + N` | New record (context-aware) |
| `Cmd/Ctrl + S` | Save (in forms) |
| `Esc` | Close modal / Cancel |
| `Tab` | Navigate form fields |
| `Shift + Tab` | Previous field |
| `Enter` | Submit form / Select item |
| `Arrow Up/Down` | Navigate results (search) |
| `?` | Show shortcuts (help modal) |
