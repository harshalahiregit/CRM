# Module Brief — Zafar (Sales / Customer / Accounts)

Rebuilding our legacy CRM ("zignls", a customized Perfex CRM/CodeIgniter fork) into Sangoe CRM (Laravel 12 + React 18/Vite), same feature set, cleaner UI, some enhancements — not a 1:1 port. Legacy reference lives at `zignls_backup` on my machine only (a compromised install — **never run/deploy it, never copy it to teammates**, read-only reference). I'm the team's access point for legacy detail — Harshal/Shivam ask me when their briefs need deeper extraction.

This brief covers my three owned modules: **Sales, Customer, Accounts**. Harshal owns HR/TPV/Purchase, Shivam owns Project&Task/Helpdesk/Inventory — see "Shared foundations" at the bottom for what depends on me / what I depend on.

**Shared docs in this folder are binding**: `ARCHITECTURE-PRIMER.md` and `TEAM-CONVENTIONS.md` (code structure, git/parallel-work rules, shared-entity contracts, table naming, definition of done). Verified against the actual code, not assumed. Key facts that directly shape the Customer module specifically:
- There is **already one shared `User` model/table/login guard** for admin, staff, vendor, third_party_vendor, and client — confirmed via `role` column values and a single Sanctum guard. **The Customer module I'm building is NOT a new auth system** — `role='client'` users already exist and can already register/log in (`POST /api/auth/register/client`, `ClientRegisterRequest`). My job is the actual `Client`/business-entity data model (company info, contacts, groups, portal features) that a `role='client'` user's dashboard is built around — not new authentication.
- **No role-restriction middleware exists on `routes/sales.php` today** — I need to add `role:` middleware myself wherever an action should be internal-only vs client-visible, and build the client-portal-facing endpoints as clearly separate/scoped routes (don't let a `role='client'` user hit internal Sales endpoints meant for staff).
- **Tenant scoping is opt-in** — every Customer/Accounts query needs an explicit `->forTenant($tenantId)` chain; `BelongsToTenant` doesn't auto-filter reads.
- Since Customer is the foundation both Shivam and I need `client_id` from, the Client model's tenant-scoping and role-boundary decisions I make here become the pattern everyone else copies — get this right first.

---

## 1. Sales

### Already built (Sales Master Plan V2, shipped, PR #2 merged)
- Backend models: `Lead`, `LeadStatus`, `LeadSource`, `LeadGoal`, `LeadNote`, `LeadActivity`, `LeadQuestionnaire(+Field/Response)`, `Proposal`, `ProposalTemplate`, `SalesInvoice`, `RetainerInvoice`, `SalesPayment`, `SalesItem`, `SalesLineItem`
- Controllers/services/repositories for Leads, Proposals, Estimates, Invoices, Retainer Invoices, Credit Notes, Delivery Notes, Payment Links, Items, HSN/SAC, Sales Dashboard
- Frontend pages: SalesDashboard, Leads, LeadDetail, LeadGoals, Proposals, ProposalDetail, ProposalTemplates, Estimates, EstimateDetail, Invoices, InvoiceDetail, CreditNotes, DeliveryNotes, Payments, PaymentLinks, RetainerInvoices, Items
- Routes centralized in `backend/routes/sales.php`

### Confirmed gaps vs legacy (not yet built)
- **Commission module** — legacy has a full commission-policy/staff-hierarchy/receipt-generation engine (`modules/commission`)
- **Affiliate Management** — referral tracking, payouts, affiliate portal
- **Loyalty/membership program** — points ledger, vouchers, client-facing loyalty portal
- **Sales Agent portal** — separate agent auth/dashboard/commission structure
- **Omni Sales** — multi-channel order aggregation (WooCommerce connector, POS)
- **Flexible Lead Finder / IndiaMART lead webhook** — external lead source ingestion
- **Scheduled email send** for estimates/invoices (send-later, not just send-now)
- **IMAP lead auto-import** — leads created automatically from a monitored inbox

Note: our GST/TDS/HSN invoice work is **net-new**, not a legacy port — zignls core has no India-specific tax fields at all. We already exceed legacy there.

---

## 2. Customer

### Already built
Nothing. Only a `ClientRegisterRequest` (auth validation) and an unrelated Helpdesk mock customer-service contract exist. **This is the single biggest gap across all nine modules on the team**, and the most blocking — Sales/Accounts pages only informally reference "client" right now with no real relation to attach to.

### Legacy feature reference
- **Admin-side client management**: CRUD with billing/shipping address blocks, multi-contact per client with primary-contact flag and per-type notification toggles (invoice/estimate/credit-note/task/project/ticket emails), contact roles/permissions (finer-grained than "primary contact"), customer-admin assignment (account manager per client), client groups, duplicate-name checks, CSV import, "login as client" impersonation, GDPR consents
- **Client Vault** — encrypted credential storage per client (a mini password manager scoped to a client record)
- **Client portal** (self-service, client-facing): dashboard, projects (with file upload/download, task comments), tickets, proposals (view/accept), contracts, invoices + statement + statement PDF, estimates, profile editing, card management, subscriptions (cancel/resume)
- **Domain Manager** — tracks client-owned domains
- **Document Management** (client-scoped) — folder/versioned library with approval workflow, e-sign
- **Surveys** — full builder with mail lists, CSV import of recipients, results viewer
- **Feedback** — client-facing project feedback submission

### Priority
Build this first, before further Sales/Accounts enhancements — Sales invoices/proposals need a real `client_id` relation, and this blocks Helpdesk (Shivam) and Projects (Shivam) from having a proper client link too.

---

## 3. Accounts

### Already built
- `SalesInvoice` + GST/TDS alter migration, InvoiceController/Service/Repository, send/recordPayment/publicLink/reminder/feedback-request routes
- Credit Notes: model, apply-to-invoice + refund routes
- Payments: `SalesPayment` model, recording embedded in Invoice/Estimate flows, Payment Links (beyond legacy — legacy has no payment-link concept, only Stripe subscription cards)
- Retainer Invoices — **net-new concept, no legacy equivalent**
- Delivery Notes — legacy has a separate lightweight module for this, not originally in "Accounts" scope but built here
- HSN/SAC codes — net-new, no legacy equivalent
- Items — maps to legacy `tblitems`/`Invoice_items.php`

### Confirmed gaps vs legacy
- **Expenses module** — entirely absent. Legacy: CRUD, tax/tax2, currency, category, client/project linkage, billable flag, convert-to-invoice, recurring expenses (same recurrence shape as invoices), import, attachments
- **Currencies / multi-currency** — no model/controller exists; need to confirm how Sales invoices currently store currency before scoping
- **Taxes management screen** — no generic configurable tax-rate list comparable to legacy (`tbltaxes` + CRUD UI); our GST/TDS are likely fixed rate types rather than a configurable list — confirm intent
- **Payment Modes management** — enable/disable modes, "show to client" toggle
- **Subscriptions / recurring billing** — legacy has Stripe-integrated subscriptions with client self-service cancel/resume; Retainer Invoices are a simpler recurring-invoice mechanism, not the same thing
- **Generic e-invoice template/output engine** — legacy has a customizable multi-format e-invoice export system (not GST/IRN-specific), separate from our India-compliance work
- **Full bookkeeping/accounting layer** — legacy's `modules/accounting` (chart of accounts, journal entries, bank feeds/reconciliation, bills/vendors/checks, budgeting, P&L/balance-sheet/trial-balance/AR-AP-aging reports) is the largest single gap by code volume across the whole project. **Flagging as likely out of scope for v1** — call this out explicitly rather than letting it get silently missed.

---

## Shared foundations — dependencies to coordinate on

- **Client entity (mine)** is a foundation other modules FK into: Sales/Accounts (mine), Helpdesk tickets and Projects (Shivam's). Stabilize the model/API shape early and treat it as a contract others code against — don't churn it after other modules start building on it.
- **Vendor entity** (Harshal, TPV/Purchase) — not a direct dependency of mine, but if Accounts' billable-expense/Purchase-invoice flows ever need vendor data, check with Harshal on the shape.
- **Item/catalog overlap** — my `SalesItem` (billing line items) may conceptually overlap with Shivam's Inventory `commodity`/warehouse items — cross-check with him before Inventory builds its own item catalog.
- **Staff/user identity** stays the single existing Sangoe auth/user model.

## Working conventions (apply to all three)
- Backend: thin Controller → Service (business logic + logging) → Model/Repository, FormRequests for validation.
- Frontend: `useToast()` hook + `ConfirmDialog` component for destructive actions — no fake stubs.
- Verify every backend endpoint via real HTTP calls against the dev server, not just `php artisan test`/tinker. Force-delete test data by specific ID, never blanket-delete.
- Audit pre-existing untouched Sales pages (Payments.jsx, CreditNotes.jsx) for the known field-mismatch pattern (frontend reading `amount`/`items` when backend returns `total`/`line_items`) before assuming they work — six other pages had this bug.
- Clean, efficient code — no premature abstractions, no unused scaffolding for features not yet needed.

## Sequencing plan
1. **Customer module first** — unblocks everything downstream (Sales/Accounts relations, Helpdesk, Projects)
2. **Accounts gaps** — Expenses, Currencies, Taxes, Payment Modes (smaller, well-scoped, high value)
3. **Sales enhancements** — Commission, Loyalty, Sales Agent portal, Omni Sales (larger, more "nice to have" than blocking — reassess priority after 1 & 2)
