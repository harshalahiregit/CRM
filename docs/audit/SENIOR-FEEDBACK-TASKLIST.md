# Senior Feedback — Master Task List

The full list of senior-review points, tracked to completion. **Tick `- [x]` only
when the item is genuinely built AND verified (test and/or build green).** Nothing
here may be dropped — this file is the memory across sessions.

**Legend:** `[ ]` open · `[x]` done · `[~]` deferred (with reason) · ⚠ needs a decision from the user before building.

Last updated: 2026-08-27.

---

## TPV
- [x] **T1. Add customer — search & link existing registered customers** (not create-only). Modal defaults to search; one-click Link; "Create new" kept. Endpoints `GET /tpv/vendors/{v}/customers/search`, `POST /tpv/vendors/{v}/customers/link`. Guarded by `TpvVendorCustomerLinkTest` (6). — commit 41f5d65d.
- [x] **T2. Shed requirement form** (the senior's 9 fields: site location, size L×W, height, purpose, side wall, flooring, gate/shutter size, footing, office/toilet) on the TPV-local vendor↔project engagement. New "Shed Projects" tab + create/edit. Guarded by `TpvVendorShedProjectTest` (5). — commit 41f5d65d.

## Vendor
- [x] **V1. State → dropdown** wired into the public vendor reg form (`VendorRegisterPage`), the admin vendor form (`TpvVendors`), and the add-customer form — all from `INDIAN_STATES`.
- [x] **V2. Resend activation email** — already exists (button on the vendor detail page; `POST /vendors/{v}/resend-activation`).
- [x] **V3. First-login dashboard guide** — dismissible `GettingStartedGuide` on the portal dashboard (shown while not Active; remembered per-browser).
- [x] **V4. Reg-status card → "Start submitting your documents" button** — optional CTA added to `RegistrationStatusCard`, wired on the TPV portal dashboard → documents.
- [ ] ⚠ **V5. Attach Agreement / NDA / Policy / SOP** for the vendor to acknowledge/sign. NEEDS DECISION: e-sign vs tick-acknowledge; admin-uploaded templates?; shown during registration or in the portal?

## Call CRM — DEFERRED (per user, 2026-08-27)
- [~] **C1. Detect restricted words during a call** — no telephony exists in the product; greenfield. Skipped for now.
- [~] **C2. Detect/flag if …** — original point was blank + depends on C1. Skipped.

## Proforma Invoice (Sales module)
- [ ] **PI1. Item/Services — add & search** on the line items (catalogue exists); also manageable from settings.
- [ ] **PI2. HSN/SAC — search by product/service + link the govt list** (import the official HSN/SAC dataset). Search endpoint already exists.
- [ ] **PI3. GST auto-detect** by the customer's billing address (state → CGST/SGST vs IGST + rate).
- [x] **PI4. Back button → the correct list** (proforma → Proforma-Invoices, estimate → Estimates), via `estimate_type`.
- [ ] **PI5. Enable Edit of a created PI.** NOTE: no edit path exists in the frontend at all (list drawer is create-only) — this is a real build, not a quick fix. `PUT /sales/estimates/{id}` exists on the backend.
- [x] **PI6. Record Payment hidden until "Mark Accepted"** — button now shows only when status = Accepted and not yet paid.
- [ ] ⚠ **PI7. Use important features from the OLD CRM invoice.** NEEDS DECISION: which features / which old CRM reference.
- [x] **PI8. T&C — render as bullets** — terms is rich-text HTML; now rendered as markup (was a run-on paragraph). Fixed on the PI detail.
- [ ] **PI9. Received-payment section:** TDS box (% selection + GST amount box); the button persists until full payment is received (not after any partial); a "Partial Paid" tag on the PI index.
- [ ] **PI10. Convert invoice items/services → Task** (create tasks from PI line items).
- [ ] ⚠ **PI11. Dedicated settings page for all sections/subsections.** NEEDS DECISION: which sections exactly (very broad as written).

## Tax Invoice (Sales)
- [x] **TI1. Copy public link — fixed.** Root cause: `navigator.clipboard` is unavailable in a non-secure context (plain http/LAN) and threw, reading as "failed". Added a robust `copyText()` helper (secure-context + legacy `execCommand` fallback); copy is now separate from link generation and, if all else fails, surfaces the URL to copy manually.
- [ ] ⚠ **TI2. Mark-as-sent shows the number of actual clicks.** NEEDS DECISION: count sends (button clicks) vs opens (customer opening the public link).
- [x] **TI3. Delete icon no longer highlighted** — de-emphasised to neutral (was alarming red) to avoid an accidental click.

## Record Payment (Sales)
- [x] **RP1. Invoice picker is a searchable typeahead** on the Record Payment page (type to filter by number/customer). NOTE: cross-document-type payments (paying a PI/Estimate from this page) would need separate backend routing — deferred; the page stays invoice-centric as it was.
- [x] **RP2. Separate TDS deduction box** on the Record Payment page (TDS % auto-computes the amount; TDS section field). Backend already persisted TDS on `sales_payments`.

## Project (my module)
- [x] **PR1. Billable — each task's amount, hidden by default, admin-only.** DECISION: fixed `billable_amount` field if set, else rate × logged hours (`Task::effectiveBillableAmount()`). API hides the amount from non-admins; Task detail shows it masked with an admin-only Show/Hide toggle; a fixed-amount field added to the task form. Guarded by `TaskBillableAmountTest` (4).
- [x] **PR2. Convert project / milestone / selected-tasks → PI.** `ProjectProformaService` builds a proforma Estimate from billable tasks (each task's effective amount → a line), via `POST /projects/{id}/convert-to-proforma` (scope: project|milestone|tasks). "Convert to PI" button on the project detail opens the created PI. Guarded by `ProjectConvertToProformaTest` (3).
- [ ] ⚠ **PR3. Feedback gate:** share feedback via WhatsApp/email when set to "awaiting feedback"; a task/project can't be "Completed" until feedback is received; once received & completed, a PI is generated. NEEDS DECISION: how "feedback received" is recorded (customer reply link vs admin marks received).

## Settings
- [ ] ⚠ **ST1. Email for each and every account.** NEEDS DECISION: per-tenant SMTP already exists — do you mean a per-staff-user sender identity?
- [x] **ST2. Recover deleted items** — a global **Recycle Bin** settings page (admin-only) listing soft-deleted Tax Invoices, Estimates/PIs, Projects, Tasks and Tickets, each restorable in one click. `GET /settings/recycle-bin` + `POST /settings/recycle-bin/restore`, tenant-scoped. Guarded by `RecycleBinTest` (3).

---

### Progress
- **Done:** T1, T2, V1, V2, V3, V4, PI4, PI6, PI8, TI1, TI3, RP1, RP2.
- **Deferred:** C1, C2 (Call CRM).
- **Open — clear, remaining (larger builds):** PI1 (item search — mostly exists), PI2 (HSN/SAC + govt import), PI3 (GST auto-detect), PI5 (edit PI — full build), PI9 (partial-pay + TDS + tag on PI), PI10 (items→task), PR2 (project→PI), ST2 (recover deleted / recycle bin).
- **Open — needs a decision (⚠):** V5, PI7, PI11, TI2, PR1, PR3, ST1.
