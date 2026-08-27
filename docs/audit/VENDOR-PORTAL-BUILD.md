# Vendor Portal — Full Build Tracker

One vendor-portal product, **two databases** (TPV + Purchase). Same features on
both; parity is the rule. Everything a vendor does is mirrored to the admin
**Vendor section**. Multi-vendor throughout.

**Tick `- [x]` only when built AND verified (test and/or build green).** Nothing
here may be dropped — this file is the cross-session memory.

**Legend:** `[ ]` open · `[x]` done · `[~]` deferred · `🟢` backend ready, portal UI missing · `🟡` partial/read-only · `🔴` greenfield · `⚪` N/A

Last updated: 2026-08-27.

## Decisions (locked with user)
- **Architecture:** ONE shared portal shell + section registry, driven by an `api`
  prop (TPV client vs Purchase client). Build each feature once → renders for both DBs.
- **Start:** Foundation skeleton first (shared shell + full nav tree + ComingSoon + gating).
- **Vendor write-access:** vendors CAN — raise/reply Tickets · submit Quotations/RFQ
  responses · log Expenses & update Task status · report Incidents & request PTW.
  Admin-controlled items (PO, Invoices, Risk Score, approvals) stay read-only to vendors.
- **EXTRA (Apps/Widgets/Ecommerce):** lowest priority; scope to be defined with user later.

---

## Phase 0 — Foundation skeleton ✅
- [x] Shared section registry (`portalSections.js`) — full nav tree (7 groups, all sections), per-section key/label/icon/group + `resolveNav()` + `SECTION_INDEX`.
- [x] Shared `PortalShell` (`PortalShell.jsx`) — grouped nav from registry, gating, descriptor props (base, brandTitle, loadVendor, onLogout, builtRoutes, extraGroups, renderBanner).
- [x] Shared `ComingSoon` portal page (`PortalComingSoon.jsx`, routed at `s/:key`).
- [x] Refactor `VendorPortalShell` (TPV) → thin descriptor wrapper.
- [x] Refactor `PurchasePortalShell` → thin descriptor wrapper (same shared shell).
- [x] `s/:key` ComingSoon route added to both portal groups in routes.jsx; `.portal-nav-soon` dimmed style. Build green.

**Built-route map today** — TPV: dashboard, onboarding, profile(registration), comply(compliance), documents + extras {workforce, governance, support}. Purchase: dashboard, onboarding, profile, comply, documents, meeting(kickoff) + extras {workforce, ppe, governance, approval, support}. Everything else in the tree → ComingSoon until its phase ships.

## Phase 1 — General
- [x] **Contact** — shared `PortalContacts.jsx` (api-prop driven), on BOTH portals. TPV
  uses `name`; Purchase uses first/last + more (each passes a `fields` descriptor). Add/
  edit/primary/status. Backends already existed.
- [x] Overview — `MyOverview.jsx`: account status + work counts (projects/tasks/open/tickets) + company header, from `me` + my-work `summary`.
- [x] Customer — `MyCustomers.jsx` (read-only, own linked clients) + `GET /portal/customers`. Linking stays an admin action.
- [~] Medical / Training — already exist under Workforce; revisit surfacing as General items.

## Phase 2 — Execution (surface existing backends)
- [x] **KB** — shared `PortalKb.jsx` browse (grouped by category) + search + article reader,
  on BOTH portals. Added `PurchasePortalController::kbArticles/kbArticle` + routes for parity
  (TPV KB was under the role-gated my-work group). Guarded by `PurchasePortalKbTest` (2).
- [x] Project — TPV portal read list (`MyWork.jsx` view=projects).
- [x] Tasks — TPV portal list + **vendor advances status** (tenant status set via `/portal/my-work/task-statuses`).
- [x] Ticket — TPV portal list + **vendor raises & replies** (`/portal/my-work/tickets` create + `/{id}` detail + `/{id}/reply`; HelpdeskService, sender_type client).
- [x] Expenses — **vendor logs** an expense on its own project + list (`/portal/my-work/expenses`). Visible to admin in project expenses.
- Execution writes guarded by `PortalExecutionWritesTest` (4).
- [ ] Meeting — portal (exists via Governance; give it its own section)
- [ ] Notes — ownership-scoped `portal/*` endpoint + page
- [ ] Attachments — ownership-scoped `portal/*` endpoint + page
- [ ] Reminders — ownership-scoped `portal/*` endpoint + page
- [ ] Expenses — **vendor can log** (pending approval) + admin mirror
- [ ] Vault — ownership-scoped portal surface
- [ ] Documents/Files — ✅ already vendor-facing (verify)
- [~] Appointment — Sales concept, not vendor-linked (revisit)
- [⚪] ToDo — folded into Tasks by design

## Phase 3 — Commercial (build portal UI on done backend)
> Commercial docs are **Purchase-DB-native** (TPV has no PO/Invoice tables — it reads
> them only via the purchase-vendor link). So Commercial is built on the **Purchase
> portal**; TPV portal shows ComingSoon for these. One component `PurchasePortalCommercial.jsx`
> drives all views via a `view` prop; detail modal shows line items (+ invoice payments).
> Guarded by `PurchasePortalCommerceTest` (4).
- [x] Quotation — portal list + detail (read). **Vendor RFQ-response submit → 3b (next).**
- [x] Contracts — portal list + detail (read).
- [x] Purchase Order — portal list + detail (read).
- [x] Purchase Invoice — portal list + detail (read, with payments).
- [x] Debit Notes — portal list + detail (read).
- [x] Purchase Statement — **new** portal endpoint (`GET /portal/purchase/statement`) + ledger page.
- [x] Payments — portal list (read).
- [x] **3b — Vendor submits Quotation / RFQ response** (write). Portal shows RFQs the
  vendor was invited to; "Submit Quote" form (rate per line, tax, valid-until, notes) →
  `POST /portal/purchase/rfqs/{id}/quotation`. `PurchaseQuotationService::submitByVendor`
  (created_by null, audited by vendor label; guards open-RFQ + invited + one-submission;
  advances RFQ Sent→Under_Review, marks recipient Responded). +3 tests (7 total).

## Phase 4 — Compliance & HSSE
- [x] Comply — vendor read-only register (exists)
- [x] Documents — vendor upload/version (exists)
- [x] **PTW** — TPV portal: list own permits + **request** (WorkPermit/PermitService; lands 'Requested'
  for admin approval). `GET/POST /portal/permits`.
- [x] **Incidents** — TPV portal: list own + **report** (HsseIncident/IncidentService; 'Reported'). `stop_work`
  kept admin-only; Serious/Fatal self-report still trips the safety hold (fail-safe). `GET/POST /portal/incidents`.
  PTW+Incidents guarded by `PortalHsseTest` (3).
- [x] **Pre Alert / Packages / Shipping** — DECISION: vendor dispatch notices. New `vendor_shipments`
  + `vendor_shipment_packages` tables + models. Pre-Alert = create shipment (courier/tracking/expected +
  packages repeater); Packages = flat package list; Shipping = status tracking + vendor advances status
  (auto-stamps dispatched/delivered). Portal `GET/POST /portal/shipments`, `PATCH .../{id}/status`,
  `GET /portal/shipment-packages`. Admin mirror `GET /tpv/vendors/{v}/shipments`. Guarded by `PortalShipmentsTest` (3).
  (Compliance→Documents nav mapped to the statutory docs page.)
- [ ] Purchase-portal parity for PTW/Incidents/Shipments (Purchase has its own HSSE stack).

## Phase 5 — Performance
- [x] **Risk Score** — TPV portal page: own score gauge + tier + monitoring + factor
  breakdown (read-only; assessment stays admin). `GET /portal/risk` → VendorRiskService::snapshot (subset).
- [x] **Penalty** — TPV portal page: own violations table + running penalty points + open
  count (read-only). `GET /portal/violations`. Guarded by `PortalPerformanceViewTest` (3).
- [x] **Feedback** — TPV portal page: vendor's own performance rating (VRS scorecard —
  overall score gauge + band + dimension bars), read-only. `GET /portal/feedback`.
- [x] **Award / Reward** — DECISION: admin grants, vendor views. New `vendor_awards` table +
  `VendorAward` model. Admin: `GET/POST/DELETE /tpv/vendors/{v}/awards`. Portal: `GET /portal/awards` + card page.
- [x] **Referral** — DECISION: vendor submits companies, admin works them. New `vendor_referrals`
  table + `VendorReferral` model. Portal: `GET/POST /portal/referrals` + submit form. Admin:
  `GET /tpv/vendors/{v}/referrals` + `PATCH .../{r}/status`.
- Phase 5 guarded by `PortalPerformanceViewTest` (6): risk, unassessed, violations, award grant→view, referral submit/isolation, feedback scorecard.
- [x] **Admin UI panels** (TpvVendorDetail): Award/Reward (grant form + delete), Referrals (list +
  status select), Feedback (VRS scorecard), Penalty (this vendor's violations + points). New
  `VendorPerformancePanels.jsx`; wired into the Performance nav group. Guarded by `VendorAdminMirrorTest` (3).
- [ ] Purchase-portal parity for the Performance section (Purchase has its own perf/violation stack).

## Phase 6 — EXTRA (define with user, build last)
- [ ] Apps
- [ ] Widgets
- [ ] Ecommerce

---

### Progress
- **Done:** Phase 0 (Foundation) · Phase 3 Commercial (read + RFQ submit) · Phase 1 General→Contact · Phase 2 Execution→KB + Projects/Tasks/Tickets read lists (TPV).
- **Execution follow-ups (tracked, not dropped):** vendor writes (ticket raise/reply, task
  status, log expenses) · Purchase parity for Projects/Tickets · Notes/Attachments/Reminders/
  Vault portal endpoints · Meeting as its own section.
- **General follow-ups:** Overview, Customer, Medical/Training surfacing.
- **Phase 5 Performance DONE** (TPV portal): Risk Score · Feedback · Penalty · Award · Referral.
- **Phase 4 Compliance & HSSE DONE** (TPV portal): Comply · Documents · PTW · Incidents · Pre-Alert · Packages · Shipping.
- **Admin panels DONE:** Performance (Award grant/delete, Referral status, Feedback scorecard, Penalty
  violations) + Compliance Shipments (Pre Alert/Package → VendorShipmentsAdminPanel). `VendorAdminMirrorTest` (3).
- **Next queued:** Phase 6 Extra (Apps/Widgets/Ecommerce — needs scope). Follow-ups: Purchase-portal parity
  (Execution/Performance/HSSE) · Execution vendor-writes (ticket raise/reply, task status, expenses) · General Overview/Customer.
