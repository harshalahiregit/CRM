# Sangoe CRM vs the legacy CRM — feature-parity audit prompt

Paste this to an agent. Every path and name below was read from the two
codebases, not assumed.

---

## The task

The legacy CRM (a Perfex/CodeIgniter fork, "zignls") is being rebuilt as Sangoe
CRM (Laravel 12 + React). Establish, **feature by feature and button by
button**, what the legacy system does that the new one does not — and where the
new one behaves differently in ways nobody decided on purpose.

**Scope: only the modules that exist in the new CRM.** Legacy features with no
counterpart being built (affiliate management, loyalty, newsfeed, OKR, fleet,
IndiaMART leads, WhatsApp chat, and similar) are **out of scope** — note them
once in an appendix and move on. Do not report "Sangoe is missing X" for
something nobody intends to build.

## The two systems

```
LEGACY   /home/zafar-farooque/Desktop/CRM/zignls_backup
         CodeIgniter. application/controllers/admin (52 controllers),
         application/models (60), application/views, modules/ (60+ addons),
         48 distinct tbl* tables referenced from models.

NEW      /home/zafar-farooque/Desktop/sangoe_crm/CRM
         backend/  Laravel 12 — 2,267 API endpoints, 329 controllers, 409 services
         frontend/ React 18 + Vite — 344 routes, 14 modules, 5 portals
```

### ⚠️ The legacy tree is read-only and hostile

`zignls_backup` contains a **webshell** and other live PHP. You may **read**
files. You must **never execute** anything from it — no `php <file>`, no
serving it, no including it, no running its SQL against a live database. Read
the source and the `.sql` dumps as text only. If a task seems to require
running it, it does not; read the code instead.

## Module correspondence — the map to work through

| Legacy (controller / module) | New (module) | Owner |
|---|---|---|
| `Clients`, `Contacts`, `contact_role`, `domain_manager` | customer | Zafar |
| `Invoices`, `Estimates`, `Proposals`, `Credit_notes`, `Payments`, `Subscriptions`, `Expenses`, `Contracts`, `Invoice_items`, `Taxes`, `Currencies`, `Paymentmodes`, `delivery_notes`, `commission`, `Leads`, `multi_page_wtl` | sales | Zafar |
| `accounting`, `einvoice`, `Reports` | accounts | Zafar |
| `Thirdparty_vendor`, `Thirdparty_vendor2`, `Vendor_onboarding`, `Vendor_dashboard`, `compliance_assurance` | tpv | Harshal |
| `purchase`, `warehouse` | purchase | Harshal |
| `hr_profile`, `hr_payroll`, `recruitment`, `timesheets`, `goals`, `surveys` | hr | Harshal |
| `Tickets`, `Ai_tickets`, `Knowledge_base`, `Departments`, `Spam_filters`, `Email_schedule_*` | helpdesk | Shivam |
| `Projects`, `Tasks`, `Todo`, `advanced_task_status_manager` | projects, tasks | Shivam |
| `assets`, `fixed_equipment`, `logistic` | inventory | Shivam |
| `Kickoff`, `Kickoff_ack`, `Kickoff_public` | shared (meeting engine) | Shivam |
| `Settings`, `Custom_fields`, `Roles`, `Staff`, `Templates`, `Emails`, `menu_setup`, `exports` | settings | mixed |
| `Clients` (client area), `Vendor`, `Contract`, `Estimate`, `Invoice`, `Proposal`, `Subscription` portals | the five portals | mixed |

Legacy tables worth diffing against the new schema: `tblclients`,
`tblcontacts`, `tblinvoices`, `tblestimates`, `tblproposals`, `tblcontracts`,
`tblcreditnotes`, `tblsubscriptions`, `tblexpenses`, `tblprojects`, `tbltasks`,
`tbltickets`, `tblreminders`, `tblstaff`, `tbldeliverynotes`, `tblvendor_*`,
`tbl_kickoff_*`, `tbl_workforce*`.

## The four logins

Both systems have distinct login surfaces. Compare each **end to end**: the
form, validation, error wording, "remember me", forgot-password and reset,
email verification, session lifetime, concurrent-session behaviour, what the
user lands on, and what the navigation exposes.

| Role | Legacy | New |
|---|---|---|
| **Staff / Admin** | `admin/Authentication` | `/login`, role `admin` \| `staff` |
| **Customer (client contact)** | client-area `Authentication`, `Clients` | `/portal/login` (own Sanctum guard, `client_portal_token`) |
| **Third-party vendor** | `Thirdparty_vendor`, `Vendor_dashboard` | `/vendor-portal`, role `third_party_vendor` |
| **Purchase vendor** | `purchase` module's vendor area | `/purchase-portal/login` (register, verify-email, reset) |

Also present in the new system and worth checking for a legacy counterpart:
`/company-portal` and the workforce portal.

For each role, verify **what that role can actually reach**, not what the menu
shows: hidden-but-reachable endpoints, permissions enforced server-side vs
merely hidden in the UI, and any legacy permission the new system has dropped.
The legacy system has a real roles/permissions engine (`Roles`,
`advanced_permissions_management`); establish whether the new one enforces an
equivalent, and say plainly if it does not.

## ⚠️ The legacy system is not a working reference

**Parts of the old CRM are broken, half-built or switched off.** A feature
existing in legacy source does NOT mean it worked. Reporting "Sangoe is missing
X" when X never functioned wastes everyone's time and, worse, could send a
developer off to reproduce a bug.

So **every** legacy feature must first be classified:

| Status | How to tell (from source only — never execute) |
|---|---|
| **WORKING** | Controller method reachable, view exists, menu item registered and not commented out, referenced models/tables exist |
| **DISABLED** | Module directory suffixed `_OFF` / `_disabled`, or has no `<module>.php` bootstrap so it never loads. Already confirmed: `advanced_permissions_management_OFF`, `file_sharing_OFF`, `prchat_disabled`, `workflow_automation` |
| **BROKEN** | References a model, table, column, library or view that does not exist; obvious fatal (undefined variable/method); a route with no view; a form posting to a method that is absent |
| **ORPHANED** | Code exists but nothing links to it — no menu entry, no route, no button anywhere in the views |
| **UNKNOWN** | Cannot be determined without running it. Say so; do not guess either way |

Then the parity status for that feature becomes one of:

- **Gap** — worked in legacy, missing or worse in Sangoe. *This is what matters.*
- **Improved** — Sangoe does it and legacy did not, or did it badly.
- **Not a gap** — absent in Sangoe, but legacy's version was DISABLED, BROKEN
  or ORPHANED. Record it, mark it clearly, and **do not** list it among the
  things to fix. If the business still wants the capability, that is a new
  feature to design, not a regression to restore.
- **Out of scope** — a module nobody is rebuilding (appendix only).

Two practical consequences:

1. Never write "missing in Sangoe" without stating the legacy status beside it.
2. When legacy behaviour is plainly wrong (bad tax rounding, a status machine
   that lets you skip states, an email that never sends), say so and recommend
   Sangoe **not** copy it. Parity with a bug is not the goal — the goal is
   knowing exactly what changed and choosing deliberately.

## What "compare" means — do all of these

For every module pair in the table above:

**1. Feature inventory.** List every screen and every action the legacy module
offers (read its controller methods and its views). Mark each: present in
Sangoe / missing / different. "Different" needs a description of how.

**2. Every button.** Walk the legacy views for buttons, links, row actions,
bulk actions, dropdown menu items, tab strips and toolbar icons. For each, say
whether Sangoe has it, where it lives, and whether it does the same thing.
This is the level of detail wanted — not "Invoices exist in both".

**3. Every filter, search and sort.** Legacy list views are driven by
DataTables with server-side filters (see `Filters` controller and each view's
table setup). Enumerate the filter set per list, then compare with Sangoe's.
Missing filters are a common regression and users notice immediately.

**4. Every form field.** Field for field, including: which are required,
defaults, dropdown sources, validation rules and messages, conditional fields,
and field ORDER. A field silently absent in the new system is data the business
used to capture and no longer does.

**5. Business logic.** The rules behind the screens: numbering schemes,
status/state machines and legal transitions, tax and discount computation,
recurring invoices and subscription renewal, credit-note application, payment
allocation, currency and exchange-rate handling, proposal-to-estimate-to-invoice
conversion, contract renewal, reminder scheduling, SLA timers, approval chains,
commission calculation, and every emailed notification and its trigger. Compare
the actual arithmetic and the actual triggers, not the labels.

**6. Data model.** Legacy `tbl*` columns vs the new schema. Flag columns the
legacy system stores that the new one has nowhere to put, and relationships
that were flattened or dropped (e.g. polymorphic `rel_type`/`rel_id` links).

**7. Permissions per role.** Which of the four roles can see and do what, in
each system, per module.

**8. Custom fields, templates and settings.** Legacy has a general custom-field
engine, email templates and PDF templates. Establish parity, including whether
custom fields reach the same places (lists, forms, PDFs, exports).

## How to verify

- **Legacy: read only.** Controllers, models, views, and the `.sql` dumps as
  text. Never execute.
- **New: drive it.** The app is running — backend `http://127.0.0.1:8000`,
  frontend `http://127.0.0.1:5173`. A staff bearer token is in
  `/tmp/audit-token.txt`. Authenticate a browser by seeding `localStorage`
  (`crm_token`, `crm_user`, `crm_tenant`, `crm_remember`) after reading
  `/api/auth/me`. puppeteer-core is installed; Chrome at
  `/usr/bin/google-chrome`; run scripts with
  `NODE_PATH=/home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/node_modules`.
- **Click things.** A `curl` 200 proves nothing about a SPA route.
- Before reporting something as missing, **search the new codebase properly** —
  it may exist under a different name (legacy "Vault" is Sangoe "Credentials";
  legacy "Appointments" is Sangoe "Meetings"; legacy "Files" is
  "Attachments"). A false "missing" wastes a developer's day.

## Output

1. **A parity matrix** — one row per legacy feature, columns:
   legacy location · **legacy status (working/disabled/broken/orphaned/unknown)**
   · Sangoe location · parity (gap / improved / not-a-gap / out-of-scope) ·
   owner · impact. Sortable by owner so each developer gets their list.
   A row may only be a **gap** if the legacy status is WORKING.

2. **Gaps that matter**, ranked by business impact — things the business could
   do before and cannot now. Separate *missing feature* from *present but
   behaves differently*, because the second is more dangerous: it looks done.

3. **Behavioural differences** where both systems have the feature but produce
   different results — different numbering, different tax rounding, a status
   transition the legacy system forbade and this one allows, an email that used
   to fire and no longer does.

4. **Per-role login comparison** — one section per role, with what each can
   reach and any permission enforced in legacy but not here.

5. **An appendix** of legacy features deliberately out of scope, so the list is
   visibly complete rather than silently truncated.

Be concrete: legacy `file:line` and Sangoe `file:line` for every row. No
speculation — if you cannot determine something without executing legacy code,
say so and leave it open rather than guessing.
