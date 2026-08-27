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
- [~] Overview, Customer — mostly done (T1 + earlier plan); verify in portal + admin.
- [ ] Overview dashboard counts surfaced in portal.

## Phase 2 — Execution (surface existing backends)
- [ ] Project — portal detail page (read-only)
- [ ] Tasks — portal detail + **vendor can update status**
- [ ] Ticket — portal list + **vendor can raise & reply**
- [ ] KB — portal browse/search + reader
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
- [ ] **3b — Vendor submits Quotation / RFQ response** (write path; approved by user).

## Phase 4 — Compliance & HSSE
- [x] Comply — vendor read-only register (exists; verify)
- [x] Documents — vendor upload/version (exists; verify)
- [ ] PTW — surface to vendor + **vendor request** (admin approves)
- [ ] Incidents — surface to vendor + **vendor report**
- [ ] Pre Alert — 🔴 greenfield (model on customer-side analog)
- [ ] Packages — 🔴 greenfield (customer-side analog)
- [ ] Shipping — 🔴 greenfield (customer-side analog)

## Phase 5 — Performance
- [ ] Risk Score — surface vendor's own score (read-only)
- [ ] Penalty — 🟡 wrap strikes/violations or new construct
- [ ] Award / Reward — 🔴 greenfield
- [ ] Feedback — 🔴 greenfield
- [ ] Referral — 🔴 greenfield

## Phase 6 — EXTRA (define with user, build last)
- [ ] Apps
- [ ] Widgets
- [ ] Ecommerce

---

### Progress
- **Done:** Phase 0 (Foundation skeleton) — shared shell + registry + ComingSoon, both portals wrapped, build green.
- **Next:** Phase 2 Execution (surface existing backends) or Phase 3 Commercial (build UI on done backend) — user to direct.
