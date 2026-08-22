# Legacy CRM: what actually works

Classified from the legacy source on 2026-08-22 — 108 units (52 admin
controllers plus every directory under `modules/`). Read-only: nothing in that
tree was executed, because it contains a webshell.

## Why this exists

The rebuild is being compared against the legacy CRM, and **a feature that
exists in the legacy source did not necessarily ever work**. Reporting "Sangoe
is missing X" when X is broken, disabled or unreachable sends someone off to
reproduce a bug faithfully.

So this is the gate. Before any parity finding is written up, check the unit
here. If it is not `working`, the finding is not a gap.

| status | count | meaning |
|---|---:|---|
| working  | 95 | reachable, view present, menu registered, tables exist |
| orphaned | 6 | code exists, nothing links to it |
| disabled | 4 | directory renamed `_OFF`/`_disabled`, or no bootstrap — never loads |
| broken   | 3 | fatals, or references a view/model that does not exist |

---

## The headline: legacy TPV vendor login and onboarding never worked

Three of the five legacy TPV units are not working, and they are the three that
make up the vendor's entire entry path:

**`Thirdparty_vendor` — BROKEN**

48 lines, mtime 2026-05-06 — the newest file in admin/. Three independent defects: (1) login()
calls $this->vendor_model->get_vendor_by_email() but no model is ever loaded in this class and
there is no constructor — fatal 'Call to a member function on null' on any POST; (2) line 8
unconditionally echoes "Login function is called!" before any redirect(), so headers are already
sent; (3) it is superseded — $route['thirdparty_vendor/login'] = 'admin/authentication/vendor'
sends the real vendor login elsewhere, and nothing links to admin/thirdparty_vendor. A stale
duplicate of application/controllers/Thirdparty_vendor.php (72 lines).

**`Vendor_dashboard` — ORPHANED**

26 lines, extends VendorController (application/core/VendorController.php exists). Views
vendor/dashboard.php and vendor/step1.php exist, so it would render — but nothing reaches it: no
menu entry, and the only route for a vendor dashboard is $route['vendor/dashboard'] =
'Vendor/Dashboard' (routes.php:19), which resolves to
application/controllers/Vendor/Dashboard.php, not this file. The line that once pointed here is
commented out at routes.php:69. Superseded stub; the live portal dashboard is
Vendor/Dashboard.php.

**`Vendor_onboarding` — BROKEN**

115 lines. step1() loads 'admin/vendor_onboarding/step1' and step2() loads
'admin/vendor_onboarding/step2' — there is no admin/vendor_onboarding view directory at all (the
onboarding views live at application/views/vendor/vendor_onboarding/ and
application/views/admin/vendordashboard/onboarding/step1.php). Both actions fatal on 'Unable to
load the requested file'. step2() also redirects to 'admin/vendordashboard/onboarding/step2', a
path with no controller. It is also superseded: routes.php:24-25 and :33-34 send
vendor/onboarding/* to application/controllers/Vendor/Vendor_onboarding.php (95KB, the real
implementation).

A vendor in the legacy system could not log in, could not complete onboarding,
and could not reach a dashboard. Any TPV parity finding phrased as "the old CRM
did this and Sangoe does not" needs checking against this section first — for
the login, onboarding and dashboard flows, the old CRM did not do it either.

---

## Everything not working, by the module rebuilding it

### tpv (Harshal) — 5 units, 3 not working

- **Thirdparty_vendor** (controller) — `broken`
  48 lines, mtime 2026-05-06 — the newest file in admin/. Three independent defects: (1) login()
  calls $this->vendor_model->get_vendor_by_email() but no model is ever loaded in this class and
  there is no constructor — fatal 'Call to a member function on null' on any POST; (2) line 8
  unconditionally echoes "Login function is called!" before any redirect(), so headers are
  already sent; (3) it is superseded — $route['thirdparty_vendor/login'] =
  'admin/authentication/vendor' sends the real vendor login elsewhere, and nothing links to
  admin/thirdparty_vendor. A stale duplicate of application/controllers/Thirdparty_vendor.php
  (72 lines).
- **Vendor_dashboard** (controller) — `orphaned`
  26 lines, extends VendorController (application/core/VendorController.php exists). Views
  vendor/dashboard.php and vendor/step1.php exist, so it would render — but nothing reaches it:
  no menu entry, and the only route for a vendor dashboard is $route['vendor/dashboard'] =
  'Vendor/Dashboard' (routes.php:19), which resolves to
  application/controllers/Vendor/Dashboard.php, not this file. The line that once pointed here
  is commented out at routes.php:69. Superseded stub; the live portal dashboard is
  Vendor/Dashboard.php.
- **Vendor_onboarding** (controller) — `broken`
  115 lines. step1() loads 'admin/vendor_onboarding/step1' and step2() loads
  'admin/vendor_onboarding/step2' — there is no admin/vendor_onboarding view directory at all
  (the onboarding views live at application/views/vendor/vendor_onboarding/ and
  application/views/admin/vendordashboard/onboarding/step1.php). Both actions fatal on 'Unable
  to load the requested file'. step2() also redirects to
  'admin/vendordashboard/onboarding/step2', a path with no controller. It is also superseded:
  routes.php:24-25 and :33-34 send vendor/onboarding/* to
  application/controllers/Vendor/Vendor_onboarding.php (95KB, the real implementation).

### settings (mixed) — 15 units, 1 not working

- **advanced_permissions_management_OFF** (module) — `disabled`
  Directory suffixed _OFF, so the loader looks for
  advanced_permissions_management_OFF/advanced_permissions_management_OFF.php and finds only
  advanced_permissions_management.php — no bootstrap match, module never loads. tblmodules still
  carries the row (advanced_permissions_management v1.0.2 active=1) in both dumps, so the DB
  flag lies; the directory rename is what actually took it out.

### accounts (Zafar) — 3 units, all working

### customer (Zafar) — 3 units, all working

### helpdesk (Shivam) — 5 units, all working

### hr (Harshal) — 6 units, all working

### inventory (Shivam) — 2 units, all working

### projects (Shivam) — 1 units, all working

### purchase (Harshal) — 2 units, all working

### sales (Zafar) — 20 units, all working

### shared meeting engine (Shivam) — 1 units, all working

### tasks (Shivam) — 3 units, all working

### out of scope — 42 units, 9 not working

- **Dashboard2** (controller) — `orphaned`
  application/controllers/admin/Dashboard2.php + view admin/dashboard/dashboard2.php both exist
  and would render if the URL were typed, but a repo-wide grep for 'dashboard2' across all
  .php/.js (excluding node_modules) returns exactly one hit: the controller's own load->view
  call. No menu item, no route, no link from any view. Dead alternate dashboard.
- **assets** (module) — `orphaned`
  Not a module: modules/assets/ holds only css/ and js/ and has no bootstrap, so app_modules
  never loads it. `diff -rq modules/assets modules/perfex_saas/assets` returns no differences —
  it is a byte-identical stray copy of the perfex_saas asset folder one directory too high. No
  code references the modules/assets/... path.
- **config** (module) — `orphaned`
  Not a module: no bootstrap (modules/config/config.php does not exist), so it never loads.
  `diff -rq modules/config modules/perfex_saas/config` returns no differences — an identical
  stray copy of the perfex_saas config folder. Nothing in application/ or index.php references
  the modules/config path.
- **file_sharing_OFF** (module) — `disabled`
  Directory suffixed _OFF; the bootstrap inside is named file_sharing.php, which no longer
  matches the directory name, so the module never loads. tblmodules still lists file_sharing
  v1.0.6 active=1 in both dumps and the tblfs_* tables still exist — the data is there but the
  code path is off.
- **hooks** (module) — `orphaned`
  Not a module: no bootstrap (modules/hooks/hooks.php does not exist). `diff -rq modules/hooks
  modules/perfex_saas/hooks` returns no differences — an identical stray copy of the perfex_saas
  hooks folder (client_tenant_bridge.php, tenant_custom_modules_page.php, stripe.php...).
  Because it is not inside an installed module, none of these hook files is ever included; the
  live copies are the ones under perfex_saas/.
- **libraries** (module) — `orphaned`
  Not a module: no bootstrap (modules/libraries/libraries.php does not exist). `diff -rq
  modules/libraries modules/perfex_saas/libraries` returns no differences — an identical stray
  copy (Modules_health_checker.php, Saas_app_modules.php, Timeouter.php, SqlScriptParser.php).
  Never loaded from this path.
- **mailbox** (module) — `broken`
  Bootstrap present and tblmodules id 19 v2.0.4 active=1, and the admin sidebar item and
  tblmail_inbox/outbox/queue/conversation/attachment tables all exist — but the IMAP fetch is
  fatally broken. mailbox.php:136 does include_once __DIR__.'/third_party/simple_html_dom.php'
  while application/helpers/widgets_helper.php:84 (and Cron_model.php:1353) already included
  APPPATH.'third_party/simple_html_dom.php'; both declare file_get_html(). 'Cannot redeclare
  file_get_html()' is the single most frequent error in application/logs — 262 occurrences,
  still firing on log-2026-04-18, the last logged day. Both files are still present in the tree,
  so scan_email_server() dies every cron run.
- **prchat_disabled** (module) — `disabled`
  Directory suffixed _disabled; the bootstrap inside is prchat.php, which no longer matches the
  directory name, so the loader never picks it up (there is an index.php but no
  prchat_disabled.php). tblmodules still lists prchat v1.5.0 active=1 in both dumps and the
  tblchat* tables survive, but the code is off.
- **workflow_automation** (module) — `disabled`
  The directory contains exactly one file — flexiblewa.zip — and no workflow_automation.php
  bootstrap, so nothing loads. It is also absent from tblmodules in both the January dump and
  the April backup: never installed, an unextracted archive left in place.

---

## Full classification

| unit | kind | status | rebuilt as |
|---|---|---|---|
| `accounting` | module | working | accounts (Zafar) |
| `advanced_task_status_manager` | module | working | tasks (Shivam) |
| `affiliate_management` | module | working | out of scope |
| `Ai` | controller | working | out of scope |
| `Ai_tickets` | controller | working | helpdesk (Shivam) |
| `Announcements` | controller | working | out of scope |
| `api` | module | working | out of scope |
| `appointly` | module | working | out of scope |
| `approvify` | module | working | out of scope |
| `Authentication` | controller | working | out of scope |
| `Auto_update` | controller | working | out of scope |
| `backup` | module | working | out of scope |
| `Clients` | controller | working | customer (Zafar) |
| `commission` | module | working | sales (Zafar) |
| `compliance_assurance` | module | working | tpv (Harshal) |
| `contact_role` | module | working | customer (Zafar) |
| `Contracts` | controller | working | sales (Zafar) |
| `Credit_notes` | controller | working | sales (Zafar) |
| `Currencies` | controller | working | sales (Zafar) |
| `Custom_fields` | controller | working | settings (mixed) |
| `Dashboard` | controller | working | out of scope |
| `delivery_notes` | module | working | sales (Zafar) |
| `Departments` | controller | working | helpdesk (Shivam) |
| `document_management` | module | working | out of scope |
| `domain_manager` | module | working | customer (Zafar) |
| `einvoice` | module | working | accounts (Zafar) |
| `Email_schedule_estimate` | controller | working | sales (Zafar) |
| `Email_schedule_invoice` | controller | working | sales (Zafar) |
| `Emails` | controller | working | settings (mixed) |
| `Estimate_request` | controller | working | sales (Zafar) |
| `Estimates` | controller | working | sales (Zafar) |
| `Expenses` | controller | working | sales (Zafar) |
| `exports` | module | working | out of scope |
| `extra_setting` | module | working | settings (mixed) |
| `feedback` | module | working | out of scope |
| `Filters` | controller | working | out of scope |
| `fixed_equipment` | module | working | inventory (Shivam) |
| `fleet` | module | working | out of scope |
| `flexibleleadfinder` | module | working | out of scope |
| `flexiblewa` | module | working | out of scope |
| `flutex_admin_api` | module | working | out of scope |
| `Gdpr` | controller | working | settings (mixed) |
| `goals` | module | working | hr (Harshal) |
| `google_analytics` | module | working | out of scope |
| `hr_payroll` | module | working | hr (Harshal) |
| `hr_profile` | module | working | hr (Harshal) |
| `idea_hub` | module | working | out of scope |
| `ideal` | module | working | sales (Zafar) |
| `Invoice_items` | controller | working | sales (Zafar) |
| `Invoices` | controller | working | sales (Zafar) |
| `Kickoff` | controller | working | shared meeting engine (Shivam) |
| `Knowledge_base` | controller | working | helpdesk (Shivam) |
| `Leads` | controller | working | sales (Zafar) |
| `logistic` | module | working | inventory (Shivam) |
| `loyalty` | module | working | out of scope |
| `ma` | module | working | out of scope |
| `menu_setup` | module | working | settings (mixed) |
| `Misc` | controller | working | out of scope |
| `Mods` | controller | working | settings (mixed) |
| `multi_page_wtl` | module | working | sales (Zafar) |
| `Newsfeed` | controller | working | out of scope |
| `okr` | module | working | out of scope |
| `omni_sales` | module | working | out of scope |
| `openai` | module | working | out of scope |
| `Paymentmodes` | controller | working | sales (Zafar) |
| `Payments` | controller | working | sales (Zafar) |
| `perfex_saas` | module | working | out of scope |
| `perfex_search` | module | working | settings (mixed) |
| `Projects` | controller | working | projects (Shivam) |
| `Proposals` | controller | working | sales (Zafar) |
| `purchase` | module | working | purchase (Harshal) |
| `recruitment` | module | working | hr (Harshal) |
| `report_builder` | module | working | out of scope |
| `Reports` | controller | working | accounts (Zafar) |
| `Roles` | controller | working | settings (mixed) |
| `sales_agent` | module | working | out of scope |
| `Settings` | controller | working | settings (mixed) |
| `si_indiamart_leads` | module | working | out of scope |
| `Smtp_oauth_google` | controller | working | settings (mixed) |
| `Smtp_oauth_microsoft` | controller | working | settings (mixed) |
| `Spam_filters` | controller | working | helpdesk (Shivam) |
| `Staff` | controller | working | settings (mixed) |
| `Subscriptions` | controller | working | sales (Zafar) |
| `surveys` | module | working | hr (Harshal) |
| `Tasks` | controller | working | tasks (Shivam) |
| `Taxes` | controller | working | sales (Zafar) |
| `Templates` | controller | working | settings (mixed) |
| `theme_style` | module | working | settings (mixed) |
| `Thirdparty_vendor2` | controller | working | tpv (Harshal) |
| `Tickets` | controller | working | helpdesk (Shivam) |
| `timesheets` | module | working | hr (Harshal) |
| `Todo` | controller | working | tasks (Shivam) |
| `Utilities` | controller | working | out of scope |
| `warehouse` | module | working | purchase (Harshal) |
| `whatsapp_chat` | module | working | out of scope |
| `advanced_permissions_management_OFF` | module | disabled ⚠ | settings (mixed) |
| `assets` | module | orphaned ⚠ | out of scope |
| `config` | module | orphaned ⚠ | out of scope |
| `Dashboard2` | controller | orphaned ⚠ | out of scope |
| `file_sharing_OFF` | module | disabled ⚠ | out of scope |
| `hooks` | module | orphaned ⚠ | out of scope |
| `libraries` | module | orphaned ⚠ | out of scope |
| `mailbox` | module | broken ⚠ | out of scope |
| `prchat_disabled` | module | disabled ⚠ | out of scope |
| `Thirdparty_vendor` | controller | broken ⚠ | tpv (Harshal) |
| `Vendor_dashboard` | controller | orphaned ⚠ | tpv (Harshal) |
| `Vendor_onboarding` | controller | broken ⚠ | tpv (Harshal) |
| `workflow_automation` | module | disabled ⚠ | out of scope |

