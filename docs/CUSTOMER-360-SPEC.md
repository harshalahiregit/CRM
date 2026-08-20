# Customer 360 — Build Spec

Customer stops being a silo of its own records and becomes the relationship layer over Sangoe,
with the intelligence the review says is missing.

| | |
|---|---|
| **Source** | `docs/CUTOMER.docx` |
| **Field baseline** | zignls (old CRM), read-only |
| **Verified against** | working tree, 20 Aug 2026 |
| **Status** | awaiting sign-off — nothing built |

| | |
|---|---|
| Duplicate implementations found | **17** |
| Shared engines built but unused | **3** |
| Changes, per the doc | **5** |
| Health inputs available today | **6 of 10** |
| Open decisions | **6** |

The review's verdict is right and worth restating precisely: **the screens exist; the architecture
and the intelligence do not.** This spec treats its section 6 — "don't duplicate the modules" — as
the governing principle rather than a footnote, because the codebase shows that principle has
already been broken seventeen times.

---

## 1. The diagnosis

Section 6 reads as a warning about something we might do. It is actually a description of what has
already happened, repeatedly, across the whole product.

| Concept | Parallel implementations | Count |
|---|---|---|
| Reminders | `Sales/Reminder`, `Customer/ClientReminder`, `Task/TaskReminder`, `Helpdesk/TicketReminder`, `Sales/PaymentReminder` | **5** |
| Notes | `Shared/Note`, `Customer/ClientNote`, `Project/ProjectNote`, `Helpdesk/TicketNote`, `Sales/LeadNote`, `Hr/HrCandidateNote`, `Hr/HrRecruiterNote` | **7** |
| Attachments | `Shared/Attachment`, `Customer/ClientAttachment`, `Helpdesk/TicketAttachment`, `Sales/LeadAttachment`, `Project/ProjectNoteAttachment` | **5** |
| Contracts | `Customer/ClientContract`, `Sales/SalesContract`, `Purchase/PurchaseContract` | **3** |

The sharpest detail: **Harshal already built the shared engines.** `Shared/Note`,
`Shared/Attachment` and `Shared/KickoffMeeting` are polymorphic, have service layers, and work.
Only Vendor and Purchase ever adopted them. Every other module wrote its own instead.

This is why the review calls Timeline *"the single most useful missing element"*. Over shared
polymorphic stores a timeline is one query. Over seventeen module-specific tables it is a union
that grows every time anyone adds a feature — which is exactly why nobody has built it.

---

## 2. The principle

Customer is the **relationship and context layer**. It owns the relationship; it does not own the
transactions. Everything else is a view into the module that does.

| Interaction | Allowed | How |
|---|---|---|
| Reuse a shared engine | **Yes** | Customer adopts `Shared/Note`, `Shared/Attachment` and the meeting engine as a consumer. Shared infrastructure belongs to the platform, not to a module. |
| Read another module's data | **Yes** | Read-only, never straight from a screen — through a service contract inside Customer, so the query can become a real API call later without touching any UI. |
| Write to a module's own tables | **Never** | — |
| Keep a copy of their data | **Never** | Counts read live. The one exception is the stored health score — Customer's own derived value, not a copy of anyone's rows. |

Built generically so Helpdesk, Projects and Sales can adopt the same pattern later. **No other
developer's module is modified** — the single exception is adding a nullable visibility column to
the two shared tables, which leaves existing behaviour untouched.

---

## 3. The five changes

The review's own five, in its own order. Health sits last because four of its ten inputs are
produced by changes 2 and 3 — built earlier it would score permanently incomplete.

### Change 1 — Profile → Overview

The customer opens onto a 360 dashboard instead of an edit form. Every number is a live count that
links into the module that owns it, never a second copy of that module's list.

- **KPIs:** Active Projects, Open Tasks, Open Tickets, Open Actions, Active Contracts, Outstanding,
  Meetings, Active Shipments. Admin chooses which tiles appear and in what order.
- **Alerts:** overdue invoices, contracts expiring in 30 days, open tickets, overdue actions
- **Recent activity:** last ten events, read live from the owning modules
- **Identity:** Health, Risk and Account Owner sit in the header — added by change 5

### Change 2 — Appointments → Meetings

The old CRM's `appointly` is a **booking** system: services, providers, working hours, reschedule
requests. The review asks for a **governance** system: agenda, minutes, decisions, actions. They
are different things, which is why it suggests "Appointment as one meeting type". Meetings is the
engine; Appointment is a type within it.

- **Lifecycle:** agenda → participants → attendance → minutes → decisions → action items → issues →
  follow-up, with previous minutes and carry-forward actions
- **Types:** the doc's 13 — Client, Project Review, Commercial, Technical, Service Review,
  Escalation, Monthly, QBR, Renewal, Complaint Review, Management, Kickoff, Closure — plus
  Appointment. Tenant-editable.
- **Engine:** *mostly already built.* As of 13 Oct Harshal has extended the kickoff engine into a
  general meeting engine — configurable `meeting_type` (defaulting to `kickoff`), structured
  `meeting_agenda_items` with owner and duration, decisions, issues, an action engine,
  carry-forward links and a MoM approval workflow. `kickoff_meeting_subjects` is polymorphic with
  an `is_primary` flag, so a meeting can already have several subjects of different types.
- **What Customer needs:** register `client` as a subject type and build the customer-facing
  screens. Meeting templates are the only part of the doc's list not yet present.
- **Produces:** meeting engagement and open actions — two Health inputs

### Change 3 — Add Timeline

One chronological answer to "what happened with this customer?". Activities come first — the
follow-up engine is already polymorphic and already accepts a customer subject, so the log itself
needs no new backend.

- **Activities:** Call, Email, WhatsApp, Visit, Meeting, Follow-up, Note, Escalation. SMS retired,
  its rows mapped to Note.
- **Consolidation:** `client_reminders` merges into the one engine, its rows migrated, nothing lost.
  One system, not two.
- **Timeline:** live union across invoices, payments, tickets, projects, shipments, notes, files,
  meetings and activities. No aggregation table, so nothing drifts.
- **Care:** the migration is the only irreversible step in this plan

### Change 4 — Group the functions

Already half-done — the nav is grouped into four labelled sections, not the flat list the review
describes. The work is regrouping into its nine, and marking which items are Customer's own and
which are windows into another module.

- **Groups:** Overview, Relationship, Commercial, Finance, Operations, Service, Documents, Admin
- **Keepers:** Tax joins Finance; TPV & Leads joins Relationship
- **Config:** admin enables or disables tabs per workspace — the old CRM had this and Sangoe lost it
- **Top tabs:** Overview, Customer Details, Contacts, Billing & Shipping, Customer Admins, per the doc

### Change 5 — Health, Risk and Account Owner

What turns the screen from a database record into a management view. Scored live on the customer,
stored as a column for the list so it stays fast at any size.

- **Health:** 0–100 from ten weighted parameters. 85+ Healthy, 70–84 Watch, 50–69 At Risk, below 50
  Critical. Weights and thresholds tenant-editable.
- **Risk:** Payment, Contract, Service and Project derived; Relationship and Compliance set by hand
- **Ownership:** account owner, secondary owner, customer success owner, business unit, region
- **Alerts:** fire on a band change, never on the state — or an unhealthy customer emails someone
  every night

---

## 4. Fields

Every field traced to where it comes from, so you can strike anything of mine without hunting.

- **[OLD]** carried across from zignls
- **[DOC]** asked for by the review
- **[NEW]** mine, with a reason

### Customer

| Field | Source | Why |
|---|---|---|
| `customer_type` | **OLD** | Existed as `client_type` and was lost in the rebuild. Corporate, SME, Government, PSU, MNC, Startup, Individual, Partner |
| `customer_tier` | **DOC** | Strategic, Key Account, Standard, Small |
| `industry` | **DOC** | Tenant-editable list |
| `account_owner_id` | **DOC** | Accountability. The old CRM had `tblcustomer_admins` — a set of staff with no single owner. |
| `secondary_owner_id`, `customer_success_owner_id` | **DOC** | Optional, per the review |
| `business_unit`, `region` | **DOC** | Neither concept exists in Sangoe yet |
| `payment_terms` | **NEW** | **Health cannot be fair without it.** Paying on day 40 is excellent on Net 45 and delinquent on Net 15 — scoring "payment behaviour" without the agreed terms produces a confidently wrong number. |
| `relationship_started_at` | **NEW** | "Revenue trend" needs a baseline, and a two-month-old customer should be excluded from trend scoring rather than scored badly. Also gives tenure. |
| `lifecycle_status` | **NEW** | Prospect, Active, Dormant, Churned. Sangoe has only a boolean `active`. Without this, a churned customer scores 100 on Health for having no open tickets. |
| `health_score`, `health_status`, `health_calculated_at` | **DOC** | Stored so the customer list stays fast; recomputed live on the detail page |
| `risk_*` (6 indicators) | **DOC** | Payment, Contract, Service, Project, Relationship, Compliance |

Already present and unchanged: company, GST number, phone, website, groups, currency, language,
full address, billing and shipping blocks, lat/long, parent company, custom fields.

### Contact

| Field | Source | Why |
|---|---|---|
| `role` | **OLD** | Existed as `contact_role_id` and was lost. CEO, Procurement, Finance, Accounts Payable, Operations, HSE, Technical, Project Manager, Billing, Commercial — tenant-editable. |
| `department` | **DOC** | Distinct from role — one is where they sit, the other is what they do for us |
| `whatsapp` | **DOC** | Separate field: it is frequently not the landline |
| `is_decision_maker`, `influence` | **DOC** | Who actually signs, and who shapes the decision |
| `is_secondary` | **DOC** | The old CRM had only `is_primary` |
| `reports_to` | **NEW** | Decision Maker and Influence are close to meaningless without the reporting line. Turns a contact list into an org map. |
| `last_contacted_at` | **NEW** | Derived, never typed. "Relationship risk" is one of the doc's six indicators and has no signal anywhere — time since last logged activity is the honest proxy. |
| per-document email opt-ins | **OLD** | The old CRM had eight separate toggles — invoice, estimate, credit note, contract, task, project, ticket, delivery note. Sangoe collapsed them into one on/off, which is a downgrade. |

---

## 5. Customization and visibility

Three layers. Only the first partly exists — Sangoe has no permission system and no user
preferences of any kind.

| Layer | Who sets it | What |
|---|---|---|
| **Tenant config** | Admin | Every option list — customer type, tier, industry, contact role, activity type, note type, meeting type. Health weights and thresholds. Which KPI tiles show. Which tabs are enabled. Custom fields. |
| **Record visibility** | Whoever creates the record | A toggle on every note, file, credential, activity and meeting: **All staff / Admins only / Only me**, plus a separate **Visible to customer** checkbox for the portal. Already the pattern in `ClientNote` and `ClientVaultEntry`; extended everywhere and added to the shared engines. |
| **User preferences** | Each person | Default landing tab, chosen list columns, saved filters, tab order within what admin allows |

No role framework is built. Visibility is per-record toggles reusing the existing pattern, which is
what makes it deliverable alongside the rest rather than a project of its own.

---

## 6. What Health can actually measure

| Parameter | Availability | Source |
|---|---|---|
| Payment behaviour | **Ready** | invoices + payments, against `payment_terms` |
| Ticket volume | **Ready** | `tickets.customer_id` |
| Project performance | **Ready** | `projects.customer_id` |
| Contract status | **Ready** | `client_contracts` |
| Service performance | **Ready** | ticket SLA timings |
| Revenue trend | **Ready** | invoices since `relationship_started_at` |
| Meeting engagement | Change 2 | customer meetings |
| Open actions | Change 2 | meeting action items |
| Complaint frequency | Needs decision 4 | complaint classification |
| Customer feedback | Needs decision 3 | CSAT capture |

---

## 7. Where the review and the code disagree

### The Vault holds credentials, not documents

Item 15 proposes making the Vault a confidential document store, or merging it with Files. It is
neither — `title`, `username`, `password`, `url`, password encrypted at rest, revealed only through
a dedicated endpoint. A password manager for the customer's systems, inherited directly from the
old CRM's vault tab.

Merging it with Files would put encrypted credentials into a general file list. **Proposal:** rename
it **Credentials**, and meet the confidential-document need with a visibility toggle on Files.

> `app/Models/Customer/ClientVaultEntry.php`

### There is no duplicate Purchase Order tab

Item 14 reports the top tabs ending in "Purchase Order | Purchase Order". The code reads
**Customer Details, Billing & Shipping, Customer Admins, Custom Fields**, with no Purchase Orders
tab in the customer module at all. The old CRM *did* inject one — so the screenshot may have been
zignls rather than Sangoe.

> `CustomerDetail.jsx:300–303` · `zignls modules/purchase/purchase.php:795`

### The navigation is already grouped

Item 7 asks for the flat list to be grouped. It already is — four labelled, colour-coded groups
across 24 items, with a comment recording that it was done because 24 flat buttons were unscannable.
The work is regrouping into nine, not building grouping.

> `CustomerDetail.jsx:39–56, 249–259`

### Notes are already structured

Item 16 says notes are "only a text box". They already carry `priority`, `deadline`, `reminder_at`
and `visibility` — more than the old CRM's notes ever did. Only the **type** taxonomy is missing.

> `app/Models/Customer/ClientNote.php:13–16`

---

## 8. Open decisions

Six left. None block changes 1 to 4. Each carries a recommendation, so the default is to accept
them all and correct what you disagree with.

### 1. Customer Purchase Orders

The old CRM had these — `tblpur_orders` with line items, tax, discounts, shipping and approvals,
linked to customers through a comma-separated `clients` column. Sangoe's `PurchaseOrder` is the
opposite direction entirely: procurement orders we place with suppliers.

> **Recommendation:** show the tab, defer the entity. A customer PO is a full document type —
> numbering, line items, tax, approval — and rebuilding it properly is its own project. If the real
> need is "record the PO number they sent us", a PO reference plus file upload on estimates and
> invoices covers it for a fraction of the work.

### 2. Domain Manager

Real, and small: `domain_name`, `registrar`, `purchase_date`, `expiry_date`, `status`,
`dns_hosting`, `registration_status`, client, project, description. Ten fields and an expiry date.
I previously advised dropping it; having seen the schema, that was wrong.

> **Recommendation:** build it — it is a day's work and it was a feature you had. Wire the expiry
> date into the Overview alerts, which is the thing the old CRM never did.

### 3. How is satisfaction captured?

The old CRM's feedback module is **project-scoped CSAT, not NPS** — four 1–5 ratings (coding,
communication, services, recommendation) plus a comment, requested by an admin per project. Its
comment column is `varchar(50)`, which truncates almost every real answer.

> **Recommendation:** rebuild it customer-scoped rather than project-scoped, with tenant-editable
> questions and a proper text column. Send it as a tokenised link on ticket closure — the same
> pattern the meeting acknowledgement flow already uses — and keep manual entry for feedback given
> offline. Add a true NPS question separately; "recommendation" on a 1–5 scale is not NPS.

### 4. Are Complaints a module or a ticket type?

Complaints and Escalations appear as first-class items. Neither exists, and both would live in
Helpdesk rather than Customer.

> **Recommendation:** a `type` field on tickets — complaint, escalation, request. They are already
> tickets in practice; a parallel module splits one conversation across two inboxes. Needs Shivam's
> agreement.

### 5. Does Account Owner control access?

Ownership can be a label or a permission. There is no permission framework to hang the second on.

> **Recommendation:** display and filter only. Record-level visibility toggles cover the actual
> confidentiality need; a half-built ownership gate would be the only enforcement in the product.

### 6. Should missing data score full marks?

The current decision is that a customer with no tickets and no invoices scores 100. That reads well
on day one, when every customer is new and every score is perfect.

> **Recommendation:** ship it as decided, but make it a setting. Excluding unmeasurable parameters
> from the weighted average is more honest — a customer with no tickets is not scoring well on
> ticket volume, they are simply not measurable yet. `lifecycle_status` covers the worst case
> either way.

---

## 9. Impact on other developers

| Change | Owner | What happens | Risk |
|---|---|---|---|
| 1, 3 | Shivam, Harshal | Customer reads their tables for counts and timeline rows — read-only, behind a service contract that can become their own endpoint later. No schema change, no copied data. | **None** |
| 2, 3 | Harshal | Customer becomes a second consumer of the shared Note, Attachment and meeting engines. A nullable visibility column is added to the two shared tables; existing behaviour is unchanged and no rows move. | Tell him |
| Decision 4 | Shivam | Complaint classification and the closure survey hook both sit inside Helpdesk. | Needs agreement |

---

Every claim about current behaviour was verified against the working tree on 20 August 2026 and
cites its file. Old-CRM fields were read from the zignls backup — read-only, nothing executed.
Nothing has been built.
