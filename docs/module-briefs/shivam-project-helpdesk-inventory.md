# Module Brief — Shivam (Project & Task / Helpdesk / Inventory)

Rebuilding our legacy CRM ("zignls", a customized Perfex CRM/CodeIgniter fork) into Sangoe CRM (Laravel 12 + React 18/Vite), same feature set, cleaner UI, some enhancements — not a 1:1 port.

**You don't have the legacy codebase locally — that's intentional.** The legacy backup lives only on Zafar's machine (it's a compromised install and shouldn't be copied around). This brief IS the extracted spec: the feature lists, workflows, and table schemas below were pulled from a full read of the legacy code. Legacy file/module names (e.g. `Kickoff.php`, `modules/warehouse`) are cited as provenance only — you don't need to read them, and you should never port legacy PHP code directly anyway. If a workflow below is ambiguous and you need deeper legacy detail, ask Zafar to extract it rather than guessing.

This brief covers only your three owned modules: **Project & Task, Helpdesk, Inventory**. Harshal owns HR/TPV/Purchase, Zafar owns Sales/Customer/Accounts — see "Shared foundations" at the bottom for what you depend on / what depends on you.

**Before starting, read the two shared docs in this same folder**: `ARCHITECTURE-PRIMER.md` (how roles/tenancy/module wiring actually work — verified against the code) and `TEAM-CONVENTIONS.md` (code structure standards, git/parallel-work rules, shared-entity contracts, table-naming, definition of done). Those two are binding for everyone; this brief is your module-specific scope on top of them. It covers how roles (admin/staff/vendor/third_party_vendor/client), multi-tenancy, and module wiring actually work in this codebase today — all verified against the real code, not assumed. Short version relevant to you specifically:
- All users (staff, client, vendor) share one `User` model/table/login — there's no separate "customer" auth system. A ticket's `customer_id` and a project's client relation both point at that same shared `User` table (filtered by `role='client'`), once Zafar's Customer module gives you a real client to attach to.
- **Helpdesk's existing routes (`routes/api_helpdesk.php`) and any new Project&Task routes you add have no role-restriction middleware by default** — you need to add `role:` middleware yourself (pattern in `routes/admin.php`) wherever an action should be staff-only vs visible to clients (e.g. a client should see their own tickets, not internal notes or other clients' tickets — that's currently not enforced anywhere and you'd be the one building it).
- **Tenant scoping is opt-in, not automatic** — `BelongsToTenant` only stamps `tenant_id` on create; every query needs an explicit `->forTenant($tenantId)` chain or it silently returns cross-tenant data. Double-check this on Helpdesk especially, since it already has public/unauthenticated routes (the widget) that resolve tenant differently (via a public widget key, not the logged-in user) — any new Helpdesk endpoint you add should follow the *existing* pattern precisely rather than improvising.
- The "Modules marketplace" UI (`ModulesPage.jsx`/`registry.js`) is cosmetic — installing/uninstalling a module there doesn't actually gate routes. Don't build against it expecting real access control.

---

## 1. Project & Task

### Already built in Sangoe
Nothing — no models, routes, or frontend pages exist. Green-field build.

### Legacy feature reference

**Projects** (legacy: `Projects.php`, ~1400 lines):
- Record: name, description, status, client, billing type, dates, cost, hourly rate
- Views: list, gantt, kanban-ish milestone board, detail page with tabs
- Milestones: CRUD, ordering, color, kanban, task-linking
- Discussions (threaded comments), file management (upload/download-all/bulk actions, visibility toggle), notes
- Team members + **vendors on projects** (cross-module touch — ties to Harshal's TPV `Vendor` entity)
- Copy project, export, generate invoice from project (cross-module touch with Zafar's Accounts), client "view as" mode, @mentions
- Expenses per project, mass-stop timers, billable timesheets per project

**Tasks** (legacy: `Tasks.php`, ~1370 lines):
- Views: list, table, kanban (drag reorder, per-user toggle), gantt
- Relation-based: a task can attach to a project, lead, or customer — not project-only
- Status/priority/milestone/tags, inline edit, bulk actions
- Checklists with templates, ordering
- Assignees + followers, plus **third-party vendor assignees** (tasks can be assigned to external vendors, not just staff — another TPV touchpoint)
- Comments with internal + external-link attachments
- Time tracking: timer, manual log, per-user unfinished-timer cleanup
- Reminders per task, public task links, copy/duplicate

**Personal To-Do** (legacy: `Todo.php`, small — 92 lines): private per-staff to-do list, separate from Tasks. Low-scope, quick to build.

**Configurable status workflow** (legacy: `modules/advanced_task_status_manager`) — optional but worth considering: a finite-state-machine layer on **project** status (not task status) — admin defines statuses + which transitions between them are legal (e.g. block "New → Completed" unless explicitly allowed). Currently legacy task statuses are hardcoded; this module only governs project-level transitions.

**"Kickoff" — vendor meeting/MOM sign-off** (legacy: `Kickoff.php`, ~2200 lines — the single most fleshed-out feature in this whole area): this is **not** a generic project-kickoff — it's a standalone vendor-meeting-and-sign-off workflow:
1. Create meeting — pick vendor(s), pick participants, date/time, location, onsite/online mode
2. MOM items — multiple rows of description/responsible-staff/target-date/remarks
3. PDF generation + email to vendor contacts
4. **Public token-based acknowledgement** — vendor gets an unauthenticated link, can Accept or Request Change with a remark; timestamped, admin gets notified; supports an ack deadline/expiry
5. Attendance tracking — separate from ack, including auto-mark-absent for online no-shows via join tokens
6. Reminders for pending/unresponded items

**Coordinate with Harshal**: this feature depends on a `Vendor` entity existing (Harshal owns TPV). Confirm the `Vendor` model shape with him before building Kickoff, or agree on a lightweight stub in the meantime. Model Kickoff as its own domain (e.g. `KickoffMeeting`, `KickoffMom`, `KickoffAttendance`), not bolted onto Tasks.

**Optional/tangential — confirm if in scope**:
- `modules/approvify` — a generic, reusable multi-step approval-request engine (not project-specific in legacy). Could be the shared pattern for any approval workflow across modules if you want to build it as reusable infrastructure.
- `modules/idea_hub` — client-facing idea/challenge submission + voting + kanban, closer to an innovation-intake pipeline than core project execution.
- `modules/appointly` (client booking/scheduler) and the lead-form-builder module were also found tagged near this area in the legacy code but look mistagged/unrelated to Project&Task — flag if anyone thinks otherwise.

---

## 2. Helpdesk

### Already built in Sangoe (substantial — no rebuild needed for this list)
- Models: `Ticket`, `TicketReply`, `TicketAttachment`, `TicketFeedback`, `KbCategory`, `KbSubcategory`, `KbArticle`, `HelpdeskWidgetSetting`
- Controllers: `TicketController`, `TicketReplyController`, `HelpdeskDashboardController`, `HelpdeskFeedbackController`, `HelpdeskWidgetController`, `PublicHelpdeskController`
- `TicketAssignmentService`, assignment routes (`PATCH /tickets/{ticket}/assign`, `/my-tasks`)
- Event-driven architecture already in place (Events/Listeners/Mail for Helpdesk)
- Frontend module with pages, components, and a public widget already built
- Ticket fields: subject, description, status, priority, assigned_to, customer_id, due_date, source

### Real remaining gaps (confirmed absent, this is your actual scope here)

1. **AI ticket assistance** — legacy `Ai_tickets.php` has two agent-facing actions: "Summarize thread" and "Suggest reply", each gated by an admin feature-flag. Built on a pluggable `AiProviderRegistry` pattern (swap LLM backend via admin setting, `AiProviderInterface::chat()`). Prompts are filterable via hooks. Nothing like this exists in Sangoe yet — this is a genuinely new capability to design, not a straight port (the concrete provider implementation wasn't in the legacy snapshot either, so you're designing this mostly fresh).

2. **Shared mailbox / email-to-ticket ingestion** (legacy: `modules/mailbox`) — a full IMAP/SMTP shared team inbox inside the CRM: compose/inbox/outbox, account config. Key flows: convert an inbound email into a **new** ticket, attach an email to an **existing** ticket, plus bonus conversions to Task and Lead (cross-module touches). No equivalent exists in Sangoe.

3. **Canned/predefined replies** — quick-insert reply templates for agents. Small feature, not seen in current Helpdesk models — confirm before building, might already be planned.

4. **Admin-configurable ticket statuses/priorities/services(departments)** as CRUD entities (with color/order), not hardcoded enums — verify how Sangoe's `status`/`priority` fields are currently modeled before deciding whether this needs work.

5. **Sender blocklist** (spam control) — no equivalent found.

6. **"Staff is replying" presence indicator** on ticket thread — minor UX nicety, legacy has a simple typing-indicator via polling, not full chat.

7. **KB nuances to double check against what's already built**: legacy has a "convert a client Q&A submission into a KB article" intake flow, and a kanban-style drag-reorder-with-color for KB groups — verify these specific flows aren't already covered by your existing KB hierarchy/publishing before treating as new work.

**Not worth chasing**: legacy's live-chat module (`prchat_disabled`) was already deactivated/abandoned in the source install — skip it, no need to reverse-engineer.

---

## 3. Inventory

### Already built in Sangoe
Nothing — no models/controllers/routes/pages anywhere. Green-field.

### Important: legacy has no single "Inventory" module — it's five separate, overlapping systems
You'll need to decide which combination is actually in scope for v1. Recommendation below, but it's your call.

1. **Warehouse** (`modules/warehouse`, ~5900 lines) — classic sellable/purchasable goods stock, tightly tied to Sales/Purchase (Zafar's and Harshal's areas respectively): commodity taxonomy, items with variations/barcodes, goods receipt (stock-in, PO-linked, approval workflow), goods delivery (stock-out), internal transfer between warehouses, stock take, loss/adjustment, pricing engine, stock valuation/out-of-stock/expiry reports. Supports batch/lot/expiry tracking.

2. **Fixed Equipment** (`modules/fixed_equipment`, ~12,700 lines — the largest single controller in the entire legacy codebase) — Snipe-IT-style non-sellable asset tracking: Assets (serialized, QR/barcode), Licenses (with seats), Accessories, Consumables, Components, Predefined Kits. Check-in/check-out to staff with full history, maintenance scheduling, depreciation, staff-request-an-asset approval flow, scheduled audits. Has its own duplicate goods-receipt/warehouse machinery — overlaps with #1.

3. **Fleet** (`modules/fleet`, ~6400 lines) — company vehicle/driver management: bookings, maintenance, parts, garages, fuel logging, configurable inspection forms, insurance records, work orders, extensive reporting.

4. **Logistics** (`modules/logistic`, ~4100 lines) — outbound freight/shipping: packages, shipments, consolidation, tracking, e-signature on delivery, pre-alerts/recipients/address book.

5. **Delivery Notes** (`modules/delivery_notes`, ~800 lines — lightweight) — a sales document type (convertible to/from PO/Estimate/Invoice with e-sign acceptance), closer to a sales-ops paperwork feature than actual stock control — arguably shouldn't count as "Inventory" scope at all.

**Recommendation**: descope Fleet + Logistics for v1 — they're large, mostly self-contained side-systems (closer to Fleetio/a freight ERP than CRM inventory) and don't block anything else. Focus v1 on **Warehouse stock + Fixed Equipment asset tracking** as the CRM-relevant core. Final call is yours — flag back if you disagree.

---

## Shared foundations — dependencies to coordinate on

- **Client/Customer entity** (Zafar) — Projects and Helpdesk tickets both conceptually need `client_id`. Confirm the Client API shape with Zafar before wiring FK relations.
- **Vendor entity** (Harshal, TPV) — your Kickoff feature and "vendors on projects"/"TPV task assignees" depend on it. Don't build a parallel vendor concept — reference Harshal's.
- **Staff/user identity** — stays the single existing Sangoe auth/user model, don't re-own it inside Project/Task or Helpdesk.
- **Item/catalog overlap** — Zafar's Sales `SalesItem` (billing line items) and your Inventory `commodity`/warehouse item may conceptually overlap. Quick cross-check with Zafar before building Inventory's item catalog so we don't end up with two divergent "item" models.
- **PPE stock check** — Harshal's TPV workforce-registration flow (PPE issuance step) wants a minimal stock-check API from your Inventory module — coordinate on a simple interface, or stub initially if Inventory isn't built yet when he needs it.

## Working conventions (apply to all your modules)
- Backend: thin Controller → Service (business logic + logging) → Model/Repository, FormRequests for validation — matches the pattern already established in Sales/HR.
- Frontend: `useToast()` hook + `ConfirmDialog` component for destructive actions — no fake `showToast('Deleted!')` stubs.
- Verify every backend endpoint via real HTTP calls against the dev server, not just `php artisan test`/tinker.
- Clean, efficient code — no premature abstractions, no unused scaffolding for features not yet needed.

## Your space — add here
- [ ] Your own sequencing/priority across Project&Task / Helpdesk / Inventory
- [ ] Which Inventory sub-modules you actually want in v1 (see recommendation above)
- [ ] Anything you want to add beyond legacy scope
- [ ] Anything above you think should be cut/simplified for v1
