# Sangoe CRM — whole-application audit prompt

Paste this to an agent. It is written to be self-contained: every number in it
was measured from the repo, not estimated.

---

## The task

Audit the **entire** Sangoe CRM for (a) UI/UX inconsistency between modules,
(b) broken or dead interactions, (c) API and contract defects, and (d) logic
errors. Produce a ranked, verified findings list plus a concrete
standardisation plan.

The application was built by three developers working in parallel on separate
modules. Each invented their own conventions. The goal is a product that looks
and behaves as though one team built it: **the same components, the same button
placement, the same form layout, the same table shape, the same feedback
patterns, everywhere.**

## The codebase

```
/home/zafar-farooque/Desktop/sangoe_crm/CRM
  backend/    Laravel 12 — 2,267 API endpoints, 329 controllers,
              409 services, 34 route files, 104 test files
  frontend/   React 18 + Vite — 344 <Route> declarations, 387 .jsx files
```

**Modules and owners** (ownership matters: report per owner so each can act)

| Module | jsx | pages | Owner |
|---|---|---|---|
| hr | 67 | 39 | Harshal |
| tpv | 65 | 42 | Harshal |
| purchase | 44 | 37 | Harshal |
| sales | 56 | 28 | Zafar |
| accounts | 34 | 26 | Zafar |
| customer | 24 | 3 | Zafar |
| inventory | 31 | 21 | Shivam |
| helpdesk | 24 | 8 | Shivam |
| tasks | 9 | 3 | Shivam |
| projects | 8 | 2 | Shivam |
| settings | 16 | 15 | mixed |
| shared / compliance / notifications | 9 | 6 | mixed |

Plus five separate **portals** with their own shells and auth guards:
client (`pages/client-portal/`), vendor, purchase-vendor, company, workforce.

## The inconsistency, measured

These are competing solutions to the same problem, all live at once. This is
the core of the job.

| Problem | Competing implementations (files) |
|---|---|
| **Confirm a destructive action** | `window.confirm` **52** · `ConfirmDialog` 39 · `ConfirmModal` 19 · `ConfirmIconButton` 9 |
| **Show an overlay** | kit3d `Overlay` 44 · `Drawer` 19 · `Modal` 5 |
| **Tabular data** | hand-rolled `<table>` **184** · shared `DataTable` 10 |
| **List toolbar** | `ListToolbar` 16 · `ListControls` 6 · `TableToolbar` 2 |
| **Primary button** | inline `linear-gradient(135deg,#7C3AED…)` copy-pasted **141** · `AsyncButton` 3 |
| **User feedback** | `useToast` 77 · raw `alert()` **48** |
| **Empty state** | shared `EmptyState` 19 · ad-hoc text everywhere else |

Existing shared primitives in `frontend/src/components/ui/` — the palette to
standardise onto rather than inventing more:

```
AsyncButton  ConfirmDialog  DataTable  Drawer  EmptyState  FormField  KPICard
kit3d(Overlay)  ListControls  ListFilter  ListToolbar  MediaLightbox  Modal
Money  MultiSearchSelect  Pagination  RichTextEditor  SearchPicker  Select
TablePagination  TableToolbar  TagInput  Toast  WorkflowProgress  AuditTimeline
```

CSS conventions already dominant: `card-3d` (168 files), `text-muted` (449),
`text-h` (425), `label-caps` (154), `input-3d` (126), `btn-3d` (33).

## Audit dimensions

Run these as independent lenses. For each finding give **file:line**, the
**user-visible symptom**, and the **specific fix**.

### 1. Visual and structural consistency
Per module, and then across modules: page header shape and position; primary
action button placement (top-right vs bottom vs inline); form field order,
label casing, required markers, help text; grid columns and gutters; card
padding and radius; table header casing, column alignment (numbers must be
right-aligned and tabular), row action position and icon set; badge and status
pill styling; spacing scale; icon library consistency and sizes; light/dark
correctness. Produce a **side-by-side comparison of the same archetype across
owners** — e.g. the list page in Sales vs HR vs Inventory vs TPV — and name
which one should become the standard and why.

### 2. Dead and broken interactions
Buttons with no handler or a no-op/TODO handler; `href="#"`; disabled states
that never re-enable; forms that cannot submit; modals with no close path
(no X, no Esc, no backdrop click); double-submit exposure (async submit with
no pending guard); actions that fire twice; keyboard traps; tab order.

### 3. Navigation and link contracts
**This class has already caused three production bugs.** Every `/app/...` and
`/portal/...` path emitted by the backend or hard-coded in the frontend must
resolve to a real route AND a real screen — a route rendering `ComingSoon` is
a failure, not a pass. Every query parameter in a link must be **read by the
destination component** (`useSearchParams`) and **accepted by the backend
filter whitelist**. Check sidebar entries, breadcrumbs, KPI tiles, row
click-throughs, "view all" links, and post-save redirects.

### 4. API and contract correctness
For every frontend API call: does the route exist; does the frontend read the
keys the backend actually returns (some endpoints wrap in
`{status,message,data}`, others return raw — mismatches render blank silently);
array vs object shape; enum values; error envelope handling; pagination
contracts; N+1 queries; endpoints returning 500 on ordinary input.

### 5. Validation, both sides
Fields required in the UI but not the API, and the reverse; client rules that
contradict server rules; phone/email/GST/PAN/IFSC/pincode format rules that
differ between modules; numeric bounds; date ordering (`end >= start`); server
error messages surfaced verbatim to users, including raw exception text.

### 6. States: loading, empty, error
Unguarded `.length`/`.map` on `useState(null)`; missing error branches, so a
failed fetch renders as an empty state and the user re-enters data that already
exists; spinners that never clear; a shared loading flag across tabs; blank
panes; `NaN`/`undefined`/`Invalid Date` rendered; retry gestures that do
nothing.

### 7. Multi-tenancy and permissions
Every query scoped by `tenant_id`; every nested resource scoped to its parent;
`exists:` validation rules tenant-scoped; role checks that string-compare
free-text fields; portal endpoints that could return another customer's or
vendor's rows; internal-only data (health scores, risk, internal notes,
credentials) leaking into any customer/vendor portal; files on public disks;
IDOR on any `/{id}` route.

### 8. Data and money correctness
Rounding, currency formatting and symbol consistency; tax and discount maths;
balance vs total confusion; percentages with wrong denominators; division by
zero; averages over the wrong population; **soft deletes bypassed by
`DB::table()`** (this already caused a customer to be shown a larger balance
than staff see); date windows with no lower bound; timezone handling —
`config/app.php` is UTC while inputs are wall-clock, so anything entered after
18:30 IST can display as the next day.

### 9. Cross-module duplication
The same concept implemented separately per module — notes, attachments,
reminders, contacts, kickoff meetings, approvals, documents. Identify which
should collapse onto the shared `App\Models\Shared\*` tables and which are
legitimately distinct. **Constraint: modules may share logic and shared
infrastructure tables, but must not read or write another module's own tables
directly** — cross-module reads go through a service seam.

### 10. Portals
All five. Auth guard correctness, token isolation between portals, permission
gating enforced server-side (not just hidden in the nav), session expiry
behaviour, password reset flows, and consistency of layout between portals.

### 11. Accessibility and responsiveness
Focus states, labels tied to inputs, colour-only status, contrast, alt text,
Esc/Enter handling, and behaviour at 1280px, 1024px and mobile widths —
horizontal overflow, tables that cannot scroll, unreachable actions.

## How to verify — this part is not optional

Findings from reading code alone are **not acceptable**. Three bugs shipped in
this codebase precisely because screens were checked for *rendering* and never
for *wiring*.

- Run the app: `cd backend && php artisan serve` and `cd frontend && npm run dev`.
- Drive it with puppeteer-core (already installed; Chrome at
  `/usr/bin/google-chrome`). Authenticate by seeding `localStorage`:
  `crm_token`, `crm_user`, `crm_tenant`, `crm_remember` — mint a token via
  `php artisan tinker` and read `/api/auth/me` for the user/tenant payload.
- **Click things.** Follow links. Submit forms. Check what renders, not the
  HTTP status — this is a SPA, so `curl` returns 200 for a path that shows 404.
- Capture screenshots and *look* at them.
- Before reporting, try to **refute** your own finding: read enough surrounding
  code that a guard several lines above, or a route declared elsewhere, cannot
  make you wrong. Default to discarding when uncertain.

## Already known — do not re-report

Fixed: `/app/meetings` and `/app/tickets/:id` 404s; `?customer=` ignored by
Projects/Tasks/Tickets; the Open Tickets tile pointing at a ComingSoon stub;
proposals invisible because they were queried on `client_id` when the table is
polymorphic; draft invoices promoted to Overdue by opening the list;
single-session logout; an over-long MySQL index name.

Known and open (report only if you find *new* instances): portal reads bypass
soft deletes; portal password reset resolves by email with no tenant scope;
`client_contacts.role` is free text and the role middleware string-compares it;
customer attachments on the public disk; the customer detail tab cache is not
keyed by client id; `ClientExpenseController::rules()` references an undefined
`$request` so every save 500s; 13 migrations carry index names over MySQL's
64-character limit.

## Output

1. **Findings**, ranked by real user impact — `file:line`, symptom, fix,
   owner. Group anything sharing a root cause.
2. **A consistency scorecard** per module: which shared primitive each module
   uses for each of the seven competing patterns above, so divergence is
   visible at a glance.
3. **A standardisation plan**: for each competing pattern, the one component
   that wins, why, the migration order, and an estimate of files touched.
   Prefer adopting an existing primitive over writing a new one.
4. **The guards** that would stop each class recurring — automated tests
   preferred over documentation.

Be blunt and concrete. No cosmetic nitpicking, no invented findings; if a lens
turns up nothing real, say so.
