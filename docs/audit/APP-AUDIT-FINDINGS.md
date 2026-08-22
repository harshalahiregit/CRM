# Whole-app audit — the four dimensions that had never been run

Dimensions 2, 4, 7 and 8 of `docs/FULL-AUDIT-PROMPT.md`. Dimensions 1 (visual
consistency) and 3 (links) were done earlier and are in `CONSISTENCY-SCORECARD.md`
and `LINK-FINDINGS.md`.

**55 confirmed, 3 refuted.** Every finding was written by one agent and then
handed to a second one told to *refute* it and to default to refuted when unsure.
The three that were refuted are listed at the bottom with the reason, because a
finding that does not survive is worth recording too — it stops someone
re-reporting it.

| severity | count |   | dimension | count |   | owner | count |
|---|---:|---|---|---:|---|---|---:|
| critical | 8 |  | List surfaces — filter, search, sort, paginate, export | 14 |  | Zafar | 19 |
| high | 28 |  | Validation, both sides | 12 |  | Shivam | 8 |
| medium | 16 |  | States: loading, empty, error | 15 |  | Harshal | 18 |
| low | 3 |  | Dead and broken interactions | 14 |  | mixed | 10 |

## What the findings have in common

Three patterns account for most of the list, and each is one fix repeated rather
than N unrelated bugs:

1. **Truncation dressed as pagination.** A rows-per-page selector with no page
   number. The user sees 25 rows, no Next control, and in several places a
   footer that states the real total — so the screen admits there are records it
   will not show. Affects 14 Sales pages through one shared hook, the Customer
   directory, 8 Accounts pages, the Helpdesk ticket grid, Projects, Inventory
   Products, and HR payroll.

2. **A failed load rendered as an empty list.** `.catch` that sets rows to `[]`,
   or no `.catch` at all. "No NCRs." then means either zero records or the
   server is down, and the user cannot tell which. 32 TPV/Purchase register
   pages share one instance of this.

3. **Validation on one side only.** Server rules with no client hint (the user
   gets a 422 they cannot attach to a field), or client hints with no server
   rule (negative quantities on invoice lines).

---

## Critical (8)

### useListView has no page number — the rows-per-page control silently truncates the list on 14 Sales pages

- **Owner:** Zafar · **Module:** sales · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/hooks/useListView.js:48`
- **What it does:**
  The shared list hook every Sales list page uses slices the matched rows from index 0 and
  never offers a page offset. The toolbar renders a "25 / 50 / 100 / All" selector that looks
  like pagination, but rows past the selected size are simply unreachable — there is no next-
  page control anywhere on those pages.
- **Reproduce:**
  Create 60 invoices. Open /app/sales/invoices. The toolbar reads "60 records" and the table
  shows 25 rows. There is no Next / page-2 control. The only way to see invoice #26 is to
  switch the selector to 50 (still misses 10) or "All". Same on Leads, Estimates, Proposals,
  Payments, Credit Notes, Delivery Notes, Retainer Invoices, Payment Links, Items, Contracts,
  Commission, Sales Tasks, Web-to-Lead Forms (14 pages import this hook).

```
  const visible = useMemo(
    () => (pageSize > 0 ? matchedRows.slice(0, pageSize) : matchedRows),
    [matchedRows, pageSize],
  )
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute and could not. (1) Evidence verified verbatim at
frontend/src/hooks/useListView.js:48-51 — `visible = pageSize > 0 ? matchedRows.slice(0,
pageSize) : matchedRows`. The hook's state is only `search` and `pageSize`; its return is
{search,setSearch,pageSize,setPageSize,visible,matched,total} — no page/offset/setPage exists
in the signature at all. (2) Not supplied elsewhere: only one useListView file exists and all
14 sales pages import it from '@/hooks/useListView'. The toolbar chain is ListToolbar ->
ListControls; ListControls.jsx renders exactly a 25/50/100/All <select> plus a refresh button,
no prev/next. Pagination.jsx and TablePagination.jsx DO exist but grep shows their only
consumers are HR, TPV, admin, accounts and company-portal pages — zero sales pages import
either. Grepped all sales pages for
setPage|currentPage|nextPage|last_page|per_page|ChevronRight|loadMore|IntersectionObserver:
only hits are the pageSize/setPageSize destructures and unrelated matches (ContractDetail page
editor, a per_page:500 lead fetch in ProposalWizard). No page overrides initialPageSize, so
all 14 default to 25. (3) Reachable, not dead code: app/routes.jsx lazy-imports these pages
and registers routes; Sidebar.jsx and MobileBottomNav.jsx link /app/sales/invoices and
/app/sales/leads. The route wrapper <S> (routes.jsx:392) is just Suspense, not a list wrapper.
(4) Repro premise holds: no paginate() anywhere in backend/app/Http/Controllers/Api/Sales/ or
backend/app/Services/Sales/; InvoiceService::list() returns the repository collection
directly, so the client really does hold all 60 rows. (5) One detail makes it worse than the
claim states: ListToolbar sets showRange = (count !== total), and Invoices passes
count={matched} (60, the pre-slice count) and total={data.length} (60) — equal, so the toolbar
prints a flat "60 records" while 25 rows render. Grep for "showing|visible.length" found no
"showing X of Y" footer on any sales page, so nothing on screen signals the 35 hidden rows.
Only mitigation is the "All" option, which the claim already discloses; that is an escape
hatch, not pagination.

</details>

### Customer Directory shows "Showing 25 of 500" with no way to reach row 26

- **Owner:** Zafar · **Module:** customer · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/customer/pages/Customers.jsx:80`
- **What it does:**
  The customer list requests a server-paginated page but never sends a `page` param and
  renders no pager. It then prints the server's real total next to the 25 rows it has, so the
  UI itself admits there are records it cannot show.
- **Reproduce:**
  Import 500 customers. Open /app/customers. The footer reads "Showing 25 of 500" and the
  toolbar reads "25 of 500 customers". No Next button exists. Picking "All" in the rows
  selector sends per_page=1000, so a tenant with more than 1000 customers can never reach the
  tail at all.

```
    customerApi.list({ search: search || undefined, per_page: pageSize || 1000 })
      .then(res => { setRows(res.data ?? []); setMeta(res) })

// …line 435, the only paging affordance on the page:
          {meta.total > (meta.per_page ?? 25) && (
            <div className="px-4 py-3 text-xs" …>
              Showing {rows.length} of {meta.total}
            </div>
          )}
```

<details><summary>What the refutation attempt checked</summary>

Verified, could not refute. Evidence exists verbatim: Customers.jsx:80 is `customerApi.list({
search: search || undefined, per_page: pageSize || 1000 })` and lines 435-437 are the
`{meta.total > (meta.per_page ?? 25) && ... Showing {rows.length} of {meta.total}}` footer.
Checked every place the missing `page` could come from: the component has no `page` state at
all (only `pageSize` at line 74) and its effect deps are `[search, pageSize]` (line 88);
`customerApi.list` (services/customerApi.js:61) is a bare `api.get('/customers', { params })`
with a `{}` default that adds nothing; the axios client lib/api.js request interceptor only
attaches the Bearer token, no param injection. Checked for a pager supplied by a wrapper:
ListToolbar renders search, a count label, ListControls and export only; ListControls is just
a page-size select (25/50/100/All=0) plus refresh. Shared components/ui/TablePagination.jsx
and Pagination.jsx exist in the repo but Customers.jsx imports neither, and there is no
IntersectionObserver/loadMore/scroll handler/current_page/last_page anywhere in the 773-line
file. Confirmed the server really paginates, so the printed total is genuine:
routes/customer.php:72 -> ClientController@index -> ClientService::list() ends in
`$query->orderBy($sort,$order)->paginate((int) ($filters['per_page'] ?? 25))` with no per_page
cap, so page 2+ exists server-side and is reachable only by sending `page`, which the UI never
does. Confirmed reachable, not dead code: app/routes.jsx:130 lazy-imports it and :532 mounts
it as the index route under `path="customers"` inside `path="/app"` (line 444). One correction
for the developer: the headline "no way to reach row 26" overstates it -- the rows-per-page
selector does surface rows 26-500 if the user picks 50/100/All, and search/CSV export are
further escapes. What actually stands is the structural defect: no page param, no pager
affordance, and a hard ceiling at per_page=1000 beyond which the tail is unreachable by any UI
action.

</details>

### Every Accounts list hard-codes per_page and renders no pager — the voucher journal stops at 50 entries

- **Owner:** Zafar · **Module:** accounts · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/accounts/pages/Vouchers.jsx:32`
- **What it does:**
  VoucherService paginates server-side, but the Vouchers page pins per_page to 50, never sends
  `page`, and has no pagination control. Voucher 51 onward is unreachable from the UI. Seven
  sibling Accounts pages do the same with their own hard caps.
- **Reproduce:**
  Post 200 vouchers. Open /app/accounts/vouchers. Only the 50 most recent appear; there is no
  Next control and no total shown, so the journal silently looks 150 entries short. Same
  pattern: Bills.jsx:79 (per_page 100), Cheques.jsx:212 (100), ChartOfAccounts.jsx:31 (100),
  Registers.jsx:38 (200), Transfer.jsx:48 (50), BankAccounts.jsx:207 (100) and :266 (50),
  Settings.jsx:413 audit log (100).

```
// frontend Vouchers.jsx:30-33
  const { data: page, isLoading } = useQuery({
    queryKey: ['accounts', 'vouchers', filters],
    queryFn: () => accountsApi.vouchers.list({ ...filters, per_page: 50 }),
  })

// backend/app/Services/Accounts/VoucherService.php:39 — it really is paginated
        return $query->orderByDesc('date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
```

<details><summary>What the refutation attempt checked</summary>

Opened every file and tried each refutation route; all of them closed. 1) Quoted code exists
verbatim. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/accounts/pages/Vouchers.jsx lines 30-34
are exactly as quoted, with `per_page: 50` on line 32 and `const vouchers = page?.data ?? []`
on line 34. `filters` is only `{type, status, search}` (line 22) — no `page` key exists to
spread in, and no setter ever adds one. 2) Not supplied by a shared wrapper. Checked
/home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/DataTable.jsx in full —
its signature is `({ columns, rows, keyField, onRowClick, emptyState })`. It sorts and renders
only; it accepts no page/meta/onPageChange prop and emits no pager. Checked
modules/accounts/AccountsLayout.jsx — no pagination. Checked services/accountsApi.js:49 —
`list: (params) => api.get('/accounts/vouchers', { params })`, a thin passthrough that injects
no `page` and does no multi-page fetching. Grepped the whole Vouchers.jsx for
`total`/`last_page`/`current_page`: zero hits, so no count is displayed either. 3) Already-
handled-generically ruled out, and the codebase proves the intended pattern exists. Two shared
components exist — components/ui/Pagination.jsx and components/ui/TablePagination.jsx — and
grep shows the only consumer anywhere is modules/tpv/pages/TpvVendors.jsx; no Accounts page
imports either. Meanwhile sibling modules/accounts/pages/RegisterDetail.jsx:40-46,187-193 does
it correctly: `useState(1)` for page, sends `{page, per_page: 50}`, and renders a pager gated
on `meta.last_page > 1`. So this is a real omission, not a house style. 4) Backend really is
paginated and really would honor more pages.
backend/app/Services/Accounts/VoucherService.php:39 is `->paginate((int) ($filters['per_page']
?? 25))` as quoted. Api/Accounts/VoucherController.php:26-29 forwards only
`type,status,from,to,search,per_page` via `$request->only(...)`, but Laravel's paginator
resolves the page number from the query string itself, so `?page=2` would work — the server
side is fine; nothing but the UI is missing. 5) File is live, not dead code.
app/routes.jsx:140 lazy-imports it and :544 mounts `<Route path="vouchers">` under the
`accounts` parent route (:538), i.e. /app/accounts/vouchers. No earlier guard prevents the
state — the AccountsLayout gate is only a first-run setup screen. 6) Sibling line references
all verified exact by grep for `per_page` across modules/accounts/pages: Bills.jsx:79 (100),
Cheques.jsx:212 (100), ChartOfAccounts.jsx:31 (100), Registers.jsx:38 (200), Transfer.jsx:48
(50), BankAccounts.jsx:207 (100) and :266 (50), Settings.jsx:413 (100). Eight for eight,
matching the claim. Only nuance worth passing to the fixer: the caps are generous (50-200), so
the silent truncation bites only on tenants past those row counts — but for the voucher
journal, which grows unboundedly with every posting, that is a matter of time rather than an
edge case, and the missing total means the shortfall is invisible when it happens.

</details>

### Ticket grid "select all" selects every filtered ticket, including the ones the page-size hides

- **Owner:** Shivam · **Module:** helpdesk · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/helpdesk/pages/TicketGrid.jsx:198`
- **What it does:**
  The header checkbox operates on `rows` (the whole filtered set) while the table renders
  `pagedRows` (the first N). The comment claims selection is scoped to what's visible; it is
  not. Bulk delete / bulk status then applies to tickets the operator never saw.
- **Reproduce:**
  With 400 open tickets and the rows selector left at the default 25, open
  /app/helpdesk/tickets. Tick the header checkbox — the bulk bar reports 400 selected while 25
  rows are on screen. Click bulk Delete and confirm: all 400 tickets are deleted via
  helpdeskApi.tickets.bulk({ ids: [...selected] }) (line 248).

```
  // What the table actually renders — capped by the page-size control (0 = all).
  const pagedRows = useMemo(() => (pageSize === 0 ? rows : rows.slice(0, pageSize)), [rows, pageSize])

  // Selection is scoped to what's currently visible.
  const allSelected = rows.length > 0 && rows.every(t => selected.has(t.id))
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(rows.map(t => t.id)))
```

<details><summary>What the refutation attempt checked</summary>

Verified, with one correction to the repro's numbers. WHAT I CHECKED 1. Code exists exactly as
quoted. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/helpdesk/pages/TicketGrid.jsx line 195 is
`const pagedRows = useMemo(() => (pageSize === 0 ? rows : rows.slice(0, pageSize)), [rows,
pageSize])`; line 198-199 are the `allSelected` / `toggleAll` pair over `rows`, preceded by
the comment "Selection is scoped to what's currently visible." The comment is false: `rows`
(line 174) is the full in-memory filtered set, `pagedRows` is the slice. 2. Nothing else
scopes it. `selected` is plain local `useState(new Set())` at line 106 — no parent, hook, or
context touches it. The header checkbox at line 408 wires straight to `toggleAll`; rows render
from `pagedRows` at line 445. The bulk mutation at line 248 sends `ids: [...selected]` with no
intersection against `pagedRows`. `pageSize` defaults to 25 (line 113) and is not persisted,
so every page load starts at 25; ListControls (/home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/ListControls.jsx) only offers
25/50/100/All. 3. The file is live, not dead code. routes.jsx:166 lazy-imports it,
routes.jsx:573 mounts `<Route path="tickets">` under the helpdesk branch. 4. No server-side
pagination caps `rows`. helpdeskApi.tickets.list() calls GET /helpdesk/tickets with no params;
TicketController::index → HelpdeskService::listTickets → TicketRepository::filtered ends in
`return $query->latest()->get();` (TicketRepository.php:85) — the whole visible tenant set,
unpaginated. So rows > pageSize is the normal case, not a corner case. 5. The codebase's own
convention is the opposite. TaskBoard.jsx:580 does the page-scoped version with the comment
"Select-all applies to the current page only — the pager is the scope" and aria-label "Select
all on this page". ManpowerRequests.jsx:1327 does the same. TicketGrid is the outlier while
claiming in its comment to follow the rule. WHAT THE FINDING GETS WRONG (does not refute it)
The backend bulk endpoint validates `'ids' => ['required','array','min:1','max:200']`
(TicketController.php:299). So the literal repro — 400 selected, click Delete — 422s and
deletes nothing. The described outcome happens at any selection of 200 or fewer: e.g. 180
filtered tickets with 25 on screen deletes all 180 (per-ticket guardManage still applies, so
an admin loses all of them). The mechanism is real; only the auditor's chosen number exceeds
the server cap. PARTIAL MITIGATION (not a fix) The bulk bar shows "{selected.size} selected"
(line 374) and the ConfirmModal title reads `Delete ${selected.size} tickets?` (line 569), so
the count is disclosed twice before the destructive action. That reduces blast radius but does
not make the code match its comment, and the operator still never saw the rows.

</details>

### Payroll → Employee Salary only ever sees the first 200 employees; its search and counters lie about the rest

- **Owner:** Harshal · **Module:** hr · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/hr/pages/Payroll.jsx:614`
- **What it does:**
  The tab fetches one page of employees (per_page 200, which is also the server's hard clamp)
  and then searches and counts entirely within that array. There is no pager. Employee 201
  onward can never be found, never shows as "Pending", and can never be assigned a salary
  structure from this screen.
- **Reproduce:**
  Seed 300 active employees. Open /app/hr/payroll → Employee Salary. The "Employees" KPI reads
  200 and "Pending" is computed against 200. Type the name or employee code of the 250th
  employee into the search box — no result, because the client filter runs over the 200 rows
  already in state. There is no next page to go to.

```
// Payroll.jsx:612-616
    Promise.all([
      hrApi.employees.list({ per_page: 200 }),
      hrApi.payroll.salaryStructures.list({ status: 'Active' }),

// Payroll.jsx:632-636 — search runs over that slice only
  const filtered = employees.filter(e => {
    if (!search) return true
    const s = search.toLowerCase()
    return (e.name||'').toLowerCase().includes(s) || (e.employee_code||'').toLowerCase().includes(s) || (e.department||'').toLowerCase().includes(s)

// backend/app/Repositories/Hr/EmployeeRepository.php:42-43 — 500 is silently clamped to 200 too
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));
```

<details><summary>What the refutation attempt checked</summary>

I tried to refute this on five fronts and every one confirmed it instead. 1) Quoted code
exists verbatim. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/hr/pages/Payroll.jsx:614 is exactly
`hrApi.employees.list({ per_page: 200 }),` inside the `EmployeeSalary` component's mount
effect (component starts at line 603). The effect has `[]` deps, sends no `page` and no
`search` param, and stores the returned array in `employees`. The client-side filter at
632-636 and `assignedCount` at 638 are as quoted; the KPI tiles at 645-647 read
`{employees.length}`, `{assignedCount}`, `{employees.length - assignedCount}`. 2) No hidden
pagination in the API wrapper. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/services/hrApi.js:227 — `list: (params) =>
api.get('/hr/employees', {params}).then(r => Array.isArray(r.data) ? r.data : (r.data?.data ??
[]))`. It unwraps one Laravel page and throws away `last_page`/`total`; it does not loop
pages. A paged variant exists one line down (`listPaged`, :228) and is used by Employees.jsx —
but not here. 3) Backend clamp is real and on this exact path. routes/hr.php:238 `GET
/employees → EmployeeController@index`, which passes `per_page` straight through
(EmployeeController.php:19-24) to EmployeeService::list (:23-26) to
EmployeeRepository::filtered, where /home/zafar-
farooque/Desktop/sangoe_crm/CRM/backend/app/Repositories/Hr/EmployeeRepository.php:42-43 does
`min($perPage, 200)` then `->latest()->paginate($perPage)`. So 200 is a genuine ceiling and
the visible slice is the 200 newest rows — the oldest employees are the ones that vanish. 4)
It is reachable, not dead code. routes.jsx:68 lazy-imports Payroll, :459 mounts HRLayout at
`hr` under the `/app` ProtectedRoute, :483 `<Route path="payroll">`. Inside Payroll.jsx the
tab is declared `{ key:'employee', label:'Employee Salary', ready:true }` (:29) and line 80
renders `<EmployeeSalary />`. I searched the whole component for a pager, a `page` state, a
`setPage`, or a server-side search handoff — there is none; the only UI control is the client-
filtered search box. 5) No alternate path rescues the user.
`hrApi.payroll.employeeSalary.assign` is called from exactly one place in the frontend —
Payroll.jsx:726, inside the `ManageSalary` drawer, which is only opened by `setManage(e)` from
a row of this same 200-row table. So an employee outside the slice genuinely cannot be
assigned a structure from the UI. Mitigating context, not a refutation: the code comment at
:617 says "small tenant; per-employee endpoint", so the 200 cap is a deliberate assumption
rather than an oversight — but it also means the screen silently misreports for any tenant
above it (and fires up to 200 parallel salary GETs). Contrast Employees.jsx:105-134/436-448,
which does this correctly with `listPaged`, `page` state and Previous/Next, showing the
codebase's own convention is not followed here.

</details>

### Purchase forms post `vendor_id` but every Purchase FormRequest whitelists `purchase_vendor_id` — contracts 422 outright, POs/PRs/invoices/debit notes silently save with no vendor

- **Owner:** Harshal · **Module:** purchase · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/purchase/pages/PurchaseContracts.jsx:153`
- **What it does:**
  Migration 2026_08_30_000009 renamed the Purchase vendor FK to `purchase_vendor_id` and every
  FormRequest was updated, but five Purchase form pages still send `vendor_id`. Because
  controllers persist `$request->validated()`, which returns only keys that carry a rule,
  `vendor_id` is stripped. For contracts and kickoffs the server rule is `required`, so the
  form is unsubmittable; for orders/requests/invoices/debit notes the rule is `nullable`, so
  the record saves with a NULL vendor and no error at all.
- **Reproduce:**
  Purchase → Contracts → New Contract. Enter a title, pick a vendor from the Vendor* dropdown,
  add a rate line, Save. Result: a red banner reading only "Validation failed" and the
  contract is never created — the vendor you selected was discarded before validation ran.
  Then Purchase → Orders → New PO, pick a vendor, save: the PO is created successfully but its
  vendor is blank on the detail screen, and reopening it for edit shows "Select a vendor…"
  again.

```
frontend PurchaseContracts.jsx:153 — `title: f.title, type: f.type, vendor_id: Number(f.vendor_id),`
backend StorePurchaseContractRequest.php:20 — `'purchase_vendor_id'     => 'required|integer',`
backend PurchaseOrderController.php:31 — `$po = $this->purchaseOrderService->create($request->validated(), $request->user());`
backend PurchaseOrderService.php:38 — `$this->assertVendorEngageable($data['purchase_vendor_id'] ?? null, $tenantId);`
Same mismatch in PurchaseOrders.jsx:35, PurchaseRequests.jsx:152, PurchaseInvoices.jsx:32, PurchaseDebitNotes.jsx:32 (5 pages total).
```

<details><summary>What the refutation attempt checked</summary>

Confirmed after actively trying to refute it. (1) Quoted code exists verbatim at
frontend/src/modules/purchase/pages/PurchaseContracts.jsx:153 (`vendor_id:
Number(f.vendor_id)`), and at the four other cited sites (PurchaseOrders.jsx:35 in
`orderPayload`, consumed at lines 61 and 198; PurchaseRequests.jsx:152;
PurchaseInvoices.jsx:32; PurchaseDebitNotes.jsx:32). (2) No transform anywhere in the send
path: services/purchaseApi.js:60 is a pure pass-through `create: (data) =>
api.post('/purchase/contracts', data)`, and lib/api.js interceptors only attach a Bearer token
and handle 401 session failures — no payload rewriting. (3) I specifically searched for the
usual backend rescues and found none: `grep -rn
"prepareForValidation|passedValidation|function validated"` over app/Http/Requests/Purchase/
returns zero hits; StorePurchaseContractRequest extends Illuminate\Foundation\Http\FormRequest
directly (no custom base class); bootstrap/app.php appends only EnforceIdleTimeout (no key-
renaming middleware); the route group carries only ['auth:sanctum','role:admin,staff']. (4)
Sweeping all Purchase FormRequests for `vendor_id` excluding `purchase_vendor_id` yields only
`vendor_ids` (plural RFQ recipient array) — unrelated; StorePurchaseContractRequest.php:20 is
`'purchase_vendor_id' => 'required|integer'`, and the Order/Request/Invoice/DebitNote requests
use `nullable|integer|exists:purchase_vendors,id`. (5) Reachable, not dead code:
routes.jsx:221/592 route the page, backend routes/purchase.php:133 registers POST /contracts;
blast radius is wider than claimed since NewContractModal is also imported by
modules/tpv/components/VendorCommercialPanel.jsx:14 and pages/vendor-
detail/vendorDetailTabs.jsx:12. (6) Repro text matches code exactly: bootstrap/app.php renders
ValidationException as `'message' => 'Validation failed'` and the modal catch is
`e?.response?.data?.message || 'Could not create the contract.'`, producing the bare
"Validation failed" banner. (7) Silent-NULL path verified: PurchaseOrderService.php:395 (and
identical guards in the Invoice/Request services) begin `if (! $vendorId) { return; }`, so the
record persists with purchase_vendor_id NULL and no error; PurchaseOrders.jsx:176 reads back
`r.vendor_id ?? ''`, matching the blank-on-reopen symptom. One wording correction: migration
2026_08_30_000009_add_purchase_vendor_id_to_purchase_tables.php ADDS a nullable
purchase_vendor_id and explicitly leaves the old vendor_id column in place ("Additive and
reversible"), so it is not a rename — but this does not change the outcome, since validated()
strips vendor_id before it can reach either column. Fix belongs in the five frontend payload
keys.

</details>

### 12 Sales list and detail pages fetch with no .catch — a failed load spins the skeleton forever

- **Owner:** Zafar · **Module:** sales · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/sales/pages/InvoiceDetail.jsx:40`
- **What it does:**
  Every Sales page loads data with a bare `.then()` that flips `loading` to false only on
  success. There is no `.catch` and no `.finally`, so any 500, network drop, or expired
  session leaves `loading === true` permanently: the user stares at a pulsing grey skeleton
  with no error, no retry, and an unhandled promise rejection in the console.
- **Reproduce:**
  Open /app/sales/invoices/17 while the API is down (or the session has expired, or the
  invoice 500s). The three grey skeleton bars animate forever. Refreshing repeats it. Same on
  /app/sales/invoices, /app/sales/estimates, /app/sales/proposals, /app/sales/credit-notes,
  /app/sales/delivery-notes, /app/sales/payments, /app/sales/items, /app/sales/payment-links,
  /app/sales/retainer-invoices, and the Proposal and Estimate detail pages.

```
InvoiceDetail.jsx:39-47
  useEffect(() => {
    salesApi.invoices.get(id).then(inv => { setInvoice(inv); setLoading(false) })
  }, [id])

  if (loading) return (
    <div className="space-y-4 animate-fade-in">
      {[1, 2, 3].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
    </div>
  )

Identical uncaught pattern in 11 more Sales pages:
  ProposalDetail.jsx:57  salesApi.proposals.get(id).then(p => { setProposal(p); setLoading(false) })
  EstimateDetail.jsx:49  salesApi.estimates.get(id).then(e => { setEstimate(e); setLoading(false) })
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED after trying every refutation path. (1) Code exists verbatim:
InvoiceDetail.jsx:39-40 `salesApi.invoices.get(id).then(inv => { setInvoice(inv);
setLoading(false) })` with skeleton at 42-46; all 11 other sites verified at/near quoted
lines. (2) Strongest refutation candidate backfired: services DO have a handler — every
invoiceApi method ends `.catch(handleErr)` — but apiError.js:114 ends handleErr with a bare
`throw error`, and its docblock line 51 says "Always throws". It enriches and re-throws, never
toasts, never returns a fallback. So the promise rejects, `.then()` never runs,
setLoading(false) never fires. (3) Axios interceptor (lib/api.js:29-40) does not toast; it
only clearAuth()s on isSessionFailure and otherwise `return Promise.reject(error)`. (4)
ErrorBoundary exists (App.jsx:10) but uses getDerivedStateFromError/componentDidCatch, which
by React design catch render-phase errors only, never async rejections; grep found zero
`unhandledrejection` listeners in src/ or index.html. (5) Route wrapper `S` (routes.jsx:392)
is only a lazy-chunk Suspense fallback, not a data-error catcher. (6) Reachable, not dead
code: routes.jsx:509-511 mount invoices/:id, proposals/:id, estimates/:id; Invoices.jsx:240
renders `loading ? <skeleton> : (...)`, confirming the endless pulsing skeleton. (7) Same
files use `.catch(e => showToast(e.message,'error'))` on secondary fetches (Invoices.jsx:82,
Estimates.jsx:147, CreditNotes.jsx:135, Payments.jsx:155) and `.catch(() => {})` at
Proposals.jsx:71,73 — only the primary load path is unguarded, so this is an inconsistency
rather than a deliberate global convention. ONE INACCURACY in the report, insufficient to
refute: the "expired session" trigger IS handled — sessionFailure.js:23-40 matches auth-shaped
401s and the interceptor redirects to /auth/login. But 500s, network drops, 403s, 404s, and
non-auth-shaped 401s (deliberately passed through per the #45 fix) all reject into the void
and hang the skeleton permanently.

</details>

### All 11 Accounts reports ignore isError — a failed Trial Balance fetch is displayed as "⚠ Out of balance"

- **Owner:** Zafar · **Module:** accounts · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/accounts/pages/reports/TrialBalance.jsx:12`
- **What it does:**
  Every report under /app/accounts/reports destructures only `data` and `isLoading` from
  useQuery and never reads `isError`. When the request fails, `data` stays undefined, so the
  page renders a table head with zero rows, ₹0 totals, and — worst of all — the red banner "⚠
  Out of balance", because `data?.totals?.balanced` is undefined and therefore falsy. A
  transport failure is reported to an accountant as a books-don't-balance accounting failure.
- **Reproduce:**
  Open /app/accounts/reports/trial-balance while the endpoint 500s or the token has expired.
  After react-query exhausts its retries the spinner clears and the page shows an empty ledger
  table, Totals ₹0 / ₹0, and a red "⚠ Out of balance" line. Nothing tells the user the request
  failed. Same blank-with-no-error on BalanceSheet, ProfitAndLoss, CashFlow, DayBook,
  GeneralLedger, LedgerStatement, Ageing, Gstr1, Gstr3b and Tds.

```
TrialBalance.jsx:12-14
  const { data, isLoading } = useQuery({
    queryKey: ['accounts', 'report', 'trial-balance', to],
    queryFn: () => accountsApi.reports.trialBalance(to ? { to } : {}),
  })

TrialBalance.jsx:62-64
  <p className="text-sm font-bold" style={{ color: data?.totals?.balanced ? '#10b981' : '#f87171' }}>
    {data?.totals?.balanced ? '✓ Balanced — debits equal credits' : '⚠ Out of balance'}
  </p>

`grep -L isError` over reports/ returns all 11 files: Ageing, BalanceSheet, CashFlow, DayBook, GeneralLedger, Gstr1, Gstr3b, LedgerStatement, ProfitAndLoss, Tds, TrialBalance.
```

<details><summary>What the refutation attempt checked</summary>

Verified as described; I could not find anything that prevents the outcome. WHAT I CHECKED 1.
The quoted code exists. `frontend/src/modules/accounts/pages/reports/TrialBalance.jsx:12-15`
is exactly `const { data, isLoading } = useQuery({...})` with no `isError`/`error`. Lines
60-62 (auditor said 62-64 — off by two, cosmetic) are the banner: `style={{ color:
data?.totals?.balanced ? '#10b981' : '#f87171' }}` / `{data?.totals?.balanced ? '✓ Balanced —
debits equal credits' : '⚠ Out of balance'}`. It sits inside the `isLoading ? spinner :
(<>…</>)` ternary, so it renders unconditionally once loading ends — including on failure. 2.
All 11 siblings confirmed. Grepped each file in `reports/`: Ageing:13, BalanceSheet:42,
CashFlow:14, DayBook:13, GeneralLedger:12, Gstr1:12, Gstr3b:14, LedgerStatement:16,
ProfitAndLoss:36, Tds:12, TrialBalance:12 all destructure only `{ data, isLoading }`. Zero
occurrences of `isError` or `error` in the whole directory. 3. The API layer rethrows, it does
not swallow. `services/accountsApi.js:71` → `.then(r => r.data).catch(handleErr)`.
`services/apiError.js` `handleErr` ends in `throw error` (its own docblock says "Always
throws"). So the query genuinely enters the error state with `data === undefined` — not a
silently-returned empty object. 4. No generic handling anywhere upstream: - `main.jsx:12`
QueryClient defaults are only `retry: 1`, `staleTime`, `refetchOnWindowFocus: false`. No
`queryCache: new QueryCache({ onError })`, no `throwOnError`/`useErrorBoundary`. It is the
only `new QueryClient(` in the app. - `lib/api.js:29` response interceptor does not toast; it
only calls `isSessionFailure()` and otherwise `Promise.reject(error)`. -
`components/ErrorBoundary.jsx` (wrapping App.jsx:10) is a plain `getDerivedStateFromError`
boundary. React Query errors are not thrown during render without `throwOnError`, so it never
sees this. - No parent wrapper: routes render `<S><AccTrialBalance /></S>` where `S`
(routes.jsx:392) is just `<Suspense fallback={<PageLoader/>}>`. 5. Nothing throws to
accidentally surface an error. I checked whether `inr(undefined)` might crash into the
boundary (which would have refuted the "silent" part): `modules/accounts/format.js` is
`Number(v || 0).toLocaleString(...)` → renders "₹0.00". No throw. The page really does render
intact with ₹0.00 totals. 6. The route is live, not dead code. `app/routes.jsx:151` lazy-
imports it; `:549` mounts `reports/trial-balance` under `<Route path="accounts">` (:538) under
`/app` + `ProtectedRoute` (:444). Reachable, and linked from `ReportsIndex.jsx:5`. 7. The
premise that success shows "✓" is real.
`backend/app/Services/Accounts/Reports/TrialBalanceReport.php:49` returns `'balanced' =>
abs($totalDebit - $totalCredit) < 0.005`, so the field name matches and the green state is
achievable — meaning the red banner on failure is genuinely indistinguishable from a real out-
of-balance ledger. (BalanceSheetReport.php:68 has the same field, and BalanceSheet.jsx has the
same gap.) ONE PARTIAL CORRECTION (does not refute) The repro's "or the token has expired"
half is handled: `lib/api.js` + `lib/sessionFailure.js` clear auth and redirect to /auth/login
on an auth-shaped 401, so an expired token sends the user to login rather than to this screen.
But every other failure mode — a 500, a 403, a non-auth 401, a timeout, backend down — reaches
exactly the state described: after `retry: 1` exhausts, empty table, Totals ₹0.00 / ₹0.00, and
a red "⚠ Out of balance" with no indication the request failed.

</details>

## High (28)

### Vendor Portal dashboard: four of the six "quick action" tiles are wired to `() => {}`

- **Owner:** Harshal · **Module:** tpv / vendor-portal · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/vendor-portal/PortalDashboard.jsx:149`
- **What it does:**
  The Quick Actions card on the vendor portal home screen renders each entry as a real button
  with a hover state and a chevron, but four of the entries carry an empty arrow function as
  their action. Clicking them does nothing at all — no navigation, no toast, no error.
- **Reproduce:**
  Log in as a vendor (role `vendor`) → lands on /vendor-portal/dashboard → Quick Actions card
  → click "View Purchase Orders", "View Invoices" or "Contact Support". Nothing happens; the
  page does not move. Same for "Contact Support" on the third_party_vendor branch. Note
  /vendor-portal/support IS a declared route (app/routes.jsx:831 `<Route path="support"
  element={<S><PortalSupport /></S>} />`) with a fully built support screen, so "Contact
  Support" is pure mis-wiring, not a missing feature.

```
PortalDashboard.jsx:149 `{ label: 'Contact Support',        icon: PhoneCall,    color: '#6366f1', action: () => {} },`
PortalDashboard.jsx:152 `{ label: 'View Purchase Orders',   icon: ShoppingBag,  color: '#0ea5e9', action: () => {} },`
PortalDashboard.jsx:153 `{ label: 'View Invoices',          icon: FileText,     color: '#f59e0b', action: () => {} },`
PortalDashboard.jsx:155 `{ label: 'Contact Support',        icon: PhoneCall,    color: '#6366f1', action: () => {} },`
Rendered at PortalDashboard.jsx:496 `<button key={i} className="portal-qa-btn" onClick={a.action}>`
The sibling entries in the same array DO navigate, e.g. :148 `action: () => navigate('/vendor-portal/documents')`, which is why the dead ones are visually indistinguishable.
```

<details><summary>What the refutation attempt checked</summary>

Could not refute. Opened frontend/src/pages/vendor-portal/PortalDashboard.jsx: lines 149, 152,
153, 155 are verbatim `action: () => {}` and line 496 is `<button key={i} className="portal-
qa-btn" onClick={a.action}>` exactly as quoted. Checked for a handler supplied elsewhere and
found none: `quickActions` is declared once at line 140 and never shadowed or reassigned; the
element is a bare <button> with no wrapper, no Link, no spread props; VendorPortalShell.jsx
has only four onClicks (sidebar toggle, logout x2, theme) and none is a container delegate; no
`document.addEventListener('click')` anywhere under app/; PortalDashboard has one useEffect
(line 75) that only loads data. Checked reachability: /vendor-portal/dashboard is routed at
app/routes.jsx:819 behind ProtectedRoute roles=['vendor','third_party_vendor'], and the Quick
Actions card (481-503) is an unconditional grid sibling — the nearest conditionals `{!isTPV
&&` (509) and `{isTPV &&` (545) both open after it closes, so it renders for every vendor.
Checked the affordance: portal.css:482-501 gives .portal-qa-btn cursor:pointer plus a hover
border/background/translateX(3px), so dead tiles are indistinguishable from live ones.
Confirmed the auditor's route claim: app/routes.jsx:831 declares `support` -> PortalSupport, a
real 8KB screen, so 'Contact Support' is genuine mis-wiring. Two refinements that do not
overturn it: (1) only 'Contact Support' is pure mis-wiring — there is no vendor-portal LIST
route for orders or invoices (only orders/:id at 851 and invoices/:id at 852), so those two
tiles have no existing destination and fixing them needs a list screen, not just a navigate()
call; (2) 'four of the six' counts array entries across both branches — a vendor actually sees
4 tiles of which 3 are dead, a third_party_vendor sees 2-4 of which 1 is dead, nobody sees six
at once. Partial mitigation: support is still reachable via the sidebar link
(VendorPortalShell.jsx:133) and a working <Link to="/vendor-portal/support"> in the 'Need
Help?' card (PortalDashboard.jsx:605), so users are not blocked — the tiles are simply inert.

</details>

### TPV document Approve/Reject tells the user email + WhatsApp notifications were dispatched; the backend sends nothing

- **Owner:** Harshal · **Module:** tpv · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/tpv/components/TpvVendorDocuments.jsx:171`
- **What it does:**
  The document review confirm dialog promises the vendor will be emailed and WhatsApped, and
  the success banner asserts the notifications were dispatched.
  VendorDocumentService::review() updates the row, writes an audit record and a log line — it
  never touches NotificationService, so the vendor is never told their statutory document was
  rejected.
- **Reproduce:**
  Open /app/tpv/view/{id} → Documents tab → pick an uploaded document → Reject → type a reason
  → Confirm. Green banner reads "Document PAN Card Rejected successfully. Notifications
  (Email, WhatsApp & In-App) dispatched." The vendor receives no email, no WhatsApp and no in-
  app notification, and their portal shows the rejection only if they happen to log in and
  look. The vendor is left waiting for a re-upload request that never arrives.

```
TpvVendorDocuments.jsx:171 `setActionSuccess(\`Document ${row.type_label} ${decision === 'approve' ? 'Approved' : 'Rejected'} successfully. Notifications (Email, WhatsApp & In-App) dispatched.\`)`
TpvVendorDocuments.jsx:487-488 (the confirm dialog copy) `? 'Approving this document will mark it as verified, send email & WhatsApp notifications to the vendor, and update onboarding progress.' : 'Rejecting this document requires a mandatory rationale. The vendor will receive an email & WhatsApp alert with instructions to re-upload.'`
The call path is api.documents.review → `Route::post('/documents/{document}/review', [VendorDocumentController::class, 'review'])` (backend/routes/tpv.php:395) → VendorDocumentController::review (line 84) → backend/app/Services/Vendor/VendorDocumentService.php review(), whose complete body is: `$doc->update([...]); $doc->recordAudit(...); Log::channel('tpv')->info('Vendor document reviewed', [...]); return $doc->fresh(['reviewer:id,name']);` — no NotificationService, no Mail, no whatsapp() anywhere in the file.
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute on six fronts; all failed. 1. Quoted code exists verbatim.
frontend/src/modules/tpv/components/TpvVendorDocuments.jsx:171 is exactly
`setActionSuccess(\`Document ${row.type_label} ${decision === 'approve' ? 'Approved' :
'Rejected'} successfully. Notifications (Email, WhatsApp & In-App) dispatched.\`)`. Lines
486-488 carry the confirm-dialog copy promising "send email & WhatsApp notifications to the
vendor" / "The vendor will receive an email & WhatsApp alert with instructions to re-upload."
2. Reachability — confirmed, not dead code. frontend/src/app/routes.jsx:668 `<Route
path="view/:id" element={<S><TpvVendorDetail /></S>} />` under the /app/tpv prefix;
TpvVendorDetail.jsx:23 imports the component and line 459 renders `<TpvVendorDocuments
vendorId={v.id} vendor={v} manage={false} api={api} moduleName={moduleName} />` for the
Documents tab. 3. Best refutation candidate was the `manage={false}` prop — it fails. `manage`
appears exactly once in TpvVendorDocuments.jsx (grep -c = 1), only in the line-54 destructure;
it never gates anything. The Approve (line 351) and Reject (line 362) buttons are gated solely
on `row.uploaded && !isApproved` / `row.uploaded && !isRejected`, so an admin does hit the
described state. 4. Full call path traced, notification absent at every hop: -
frontend/src/services/tpvApi.js:218-219 `review: (documentId, decision, remarks = '') =>
api.post(\`/tpv/documents/${documentId}/review\`, { decision, remarks })` -
backend/routes/tpv.php:395, inside the `Route::middleware(['auth:sanctum',
'role:admin'])->prefix('tpv')` group (line 376) — plain auth/role middleware, nothing that
notifies. - backend/app/Http/Controllers/Api/Tpv/VendorDocumentController.php::review (line
84): tenant assert, service call, `response()->json($doc)`. Nothing else. (The auditor cited a
different controller path; the real one is Api/Tpv/, but the code is as described.) -
backend/app/Services/Vendor/VendorDocumentService.php::review: guard clauses,
`$doc->update([...])`, `$doc->recordAudit(...)`, `Log::channel('tpv')->info(...)`, `return
$doc->fresh(['reviewer:id,name'])`. Confirmed by grep that NotificationService / Mail:: /
whatsapp appear nowhere in the file — the only hit across app/Services/Vendor/ and
app/Http/Controllers/Vendor/ is VendorService.php. 5. Checked for indirect/generic dispatch —
none exists. `recordAudit` in app/Models/Traits/Auditable.php contains no
Notification/Mail/whatsapp reference. VendorDocument::booted()
(app/Models/Vendor/VendorDocument.php:68) only snapshots a file version, and only `if
($doc->wasChanged('file_path'))` — a review never changes file_path, so even that no-ops. No
`$dispatchesEvents`. No observers/listeners: grep for VendorDocument across app/Providers,
app/Observers, app/Listeners, app/Jobs, app/Events, app/Notifications returned zero hits. 6.
Capability exists and is deliberately wired elsewhere, so this is an omission rather than a
missing subsystem: app/Services/Notifications/NotificationService.php exists and
VendorService.php injects it (line 25) for other vendor events. Also note the twin
PurchaseDocumentService.php has the identical gap (same recordAudit at line 163, no
notification imports), so the same defect exists on the purchase path. Nothing in-app either —
the audit row and the tpv log line are not user-visible notifications. The UI copy asserts
three channels; zero fire.

</details>

### Vendor registration form swallows every server error — the submit button appears to do nothing

- **Owner:** Harshal · **Module:** auth / vendor onboarding · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/auth/VendorRegisterPage.jsx:39`
- **What it does:**
  `apiError` is set on every failed registration but is never rendered anywhere in the JSX. On
  a non-422 failure (500, network drop, duplicate that comes back as a message rather than
  field errors) the user clicks "Register", nothing visible changes, and there is no way to
  tell the submit even fired.
- **Reproduce:**
  Go to /auth/register/vendor, fill the form, submit while the API returns a 500 (or with the
  backend down). The button returns to idle, no banner, no toast, no field error — the form
  looks untouched. Users re-submit repeatedly. The two sibling registration screens built from
  the same template DO render the banner, so this is a regression in one file only.

```
VendorRegisterPage.jsx:39 `const [apiError, setApiError]  = useState('')` — the only occurrences of the identifier in the whole file are :39 (declare), :61 `setApiError('')`, :77 `setApiError('Please correct the highlighted fields and try again.')` and :80 `setApiError(err.response?.data?.message || 'Registration failed. Please try again.')`. There is no `{apiError && ...}` render block.
Contrast pages/auth/TPVRegisterPage.jsx:295-298 `{apiError && ( ... {apiError} ...)}` and pages/auth/ClientRegisterPage.jsx:173-176 `{apiError && ( ... {apiError} ...)}` — both siblings render it.
```

<details><summary>What the refutation attempt checked</summary>

I opened /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/pages/auth/VendorRegisterPage.jsx in full (360
lines) and tried several ways to refute it; none held. Quoted code exists as described. `grep
-n apiError VendorRegisterPage.jsx` returns exactly one line — 39 (`const [apiError,
setApiError] = useState('')`). Lines 61/77/80 write it inside `onSubmit`, and there is no
`{apiError && ...}` anywhere in the JSX (84-359). Interestingly the setter calls are on
61/77/80 as the auditor said, but grep only surfaces the declaration because the others use
`setApiError` — either way, the identifier is never read in render. Siblings do render it:
TPVRegisterPage.jsx:293-299 and ClientRegisterPage.jsx:172-178 both have the red banner block,
so this is a one-file omission from a shared template. Refutation attempts that failed: -
Global toast: Providers (src/app/providers.jsx) does wrap the tree in `ToastProvider`, but
VendorRegisterPage imports nothing from it — `grep -n "useToast|toast|Toast"` on the file
returns nothing (exit 1). No toast fires. - Axios interceptor: the page posts through
`@/lib/api` (src/lib/api.js). Its response interceptor only calls `clearAuth()`/redirect when
`isSessionFailure(...)` is true and otherwise does `return Promise.reject(error)` — it
displays nothing. No other instance is involved here (clientPortalApi/purchaseVendorApi/hrApi
are unused by this page). - Error boundary: App.jsx wraps routes in ErrorBoundary, but the
failure is caught by the page's own try/catch and never becomes a render-phase throw, so the
boundary is irrelevant. - Dead code: it is routed and linked. src/app/routes.jsx:416 `<Route
path="register/vendor" element={<VendorRegisterPage />} />` with no GuestRoute wrapper, and
RegisterPage.jsx:50/62 link to `/auth/register/vendor?type=standard|temporary`. Backend
endpoint exists too: routes/auth.php:14 `Route::post('/register/vendor',
[AuthController::class, 'registerVendor'])`. - Earlier guard: react-hook-form only blocks
submit for the ~8 fields in RULES; a valid form reaching a 500/network failure hits line 80
and shows nothing but the button flipping back from "Registering..." to "REGISTER" (line 353).
One thing that makes it slightly worse than stated: even the 422 branch is partly invisible.
`Field` only renders an error when passed `error=`, and that prop is absent for designation,
phone, company_phone, company_anniversary, manpower, city, pincode, state, country, address,
dob and terms — so server field errors on any of those are also dropped along with the "Please
correct the highlighted fields" message.

</details>

### Sales → Payments: the payment-mode filter chips are inert, the backend never reads the param

- **Owner:** Zafar · **Module:** sales · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/sales/pages/Payments.jsx:50`
- **What it does:**
  The Payments screen's mode chip row (All / Cash / Bank Transfer / …) sends `mode` as a query
  param on GET /sales/invoices. InvoiceController@index reads only `status` and `client_id`,
  so the param is dropped and the identical unfiltered payment list comes back. There is no
  client-side filter either.
- **Reproduce:**
  Open /app/sales/payments with payments recorded in several modes. Click the "Cash" chip — it
  highlights, a refetch fires, and the table still shows every payment of every mode. Same for
  every other chip. The only way to find cash payments is to read the whole table.

```
Payments.jsx:35 `const [filterMode, setFilterMode] = useState('All')`; :50 `salesApi.payments.list({ mode: filterMode !== 'All' ? filterMode : undefined })`; :53 `useEffect(() => { load() }, [filterMode])`. The only other uses of `filterMode` are :143-144, which are the chip's own background/colour — no `.filter(` anywhere in the file.
services/invoiceApi.js:39-41 `list: (params = {}) => api.get('/sales/invoices', { params: { ...params, include_payments: true } }).then(r => r.data.flatMap(inv => (inv.payments || []).map(...)))`
backend/app/Http/Controllers/Api/Sales/InvoiceController.php:20-26 `public function index(Request $request) { $invoices = $this->invoiceService->list($request->user()->tenant_id, ['status' => $request->input('status'), 'client_id' => $request->input('client_id')]); ... }` — `mode` (and `include_payments`) are never read; InvoiceRepository::filtered() likewise only branches on status and client_id.
```

<details><summary>What the refutation attempt checked</summary>

Opened every file in the chain and could not find anything that rescues the chips. 1.
`frontend/src/modules/sales/pages/Payments.jsx` — quoted code exists verbatim. :35 `const
[filterMode, setFilterMode] = useState('All')`; :50 `salesApi.payments.list({ mode: filterMode
!== 'All' ? filterMode : undefined })`; :53 `useEffect(() => { load() }, [filterMode])`;
:139-148 the chip row `['All', ...PAY_MODES].map(...)` whose only use of `filterMode` is the
background/color at :143-144. The rendered rows come from `visible` ← `useListView(filtered,
[])` ← `filtered` (:74-76), which filters on `search` against `invoice_number`/`client` only.
No mode predicate anywhere on the display path, so clicking a chip refetches and re-renders
the identical set. 2. Checked the "supplied elsewhere" escapes. `salesApi.payments` →
`paymentApi` (`salesApi.js:12,30`), whose `list` (`invoiceApi.js:39-41`) spreads params
straight into `api.get('/sales/invoices', ...)` and flat-maps `inv.payments` — no client-side
mode filter in the wrapper either. `src/lib/api.js` has only two interceptors: a request one
that attaches the Bearer token and a response one for session failure; neither touches
`params`. `useListView` (hooks/useListView.js:29-51) takes `fields = []` here, so its own
search does nothing and it only slices for page size — no hidden filtering. 3. Backend
confirmed. `routes/sales.php:156` maps `GET /invoices` → `InvoiceController@index`.
`InvoiceController.php:20-26` builds the filter array from exactly `$request->input('status')`
and `$request->input('client_id')` — no `mode`, no FormRequest (index takes a plain
`Request`). `InvoiceService::list()` (:26-33) passes the array through untouched to
`InvoiceRepository::filtered()`, which branches only on `status` and `client_id` before
`->latest()->get()`. `grep -rn "mode"` across `app/Repositories/Sales/` and
`InvoiceService.php` finds only `'mode' => $data['mode']` in recordPayment and `discount_mode`
on line items. `payments` is eager-loaded unconditionally in the repo, so the list does return
payments (the table is populated) — `include_payments` is simply redundant, not the cause. 4.
Reachability confirmed: `routes.jsx:89` lazy-imports this exact component and `:507` renders
it at `payments` nested under `:495` `<Route path="sales">`, i.e. the live
`/app/sales/payments` linked from `Sidebar.jsx:125`. One immaterial imprecision in the audit
text: the file does contain `.filter(` calls (:75 search, :79 modeBreakdown counts, :255
invoice picker) — but none of them filters the displayed list by `filterMode`, so the
substantive claim is unaffected. Additionally the KPI tiles and "By Mode" strip (:78-79,
:106-134) read `data`, so they never respond to a chip either.

</details>

### Notification Preferences: the whole 11×5 channel matrix saves successfully and is never consulted by anything

- **Owner:** mixed · **Module:** settings / notifications · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/settings/pages/NotificationPreferences.jsx:89`
- **What it does:**
  The page persists five master channel toggles plus a per-category × per-channel matrix into
  the `notifications` setting group and reports "Notification preferences saved". No code
  anywhere reads those keys — NotificationService dispatches email/whatsapp/sms
  unconditionally, so switching a channel off changes nothing.
- **Reproduce:**
  Open /app/settings/notification-preferences → turn Email OFF for the whole "Payroll"
  category (or flip the master Email toggle off) → Save → green toast "Notification
  preferences saved". Run a payroll action that mails employees: the emails still go out.
  Reload the page and the switch is still off, so the admin believes it took effect.

```
NotificationPreferences.jsx:34-37 `const d = await settingsApi.group.update('notifications', values); setValues(d?.values || {}); toast.success('Notification preferences saved')`, button at :89 `<button onClick={save} disabled={saving} ...> {saving ? 'Saving…' : 'Save Settings'}</button>`.
The keys are declared at backend/app/Support/Settings/SettingRegistry.php:130-137 (`'email' => ['cast' => 'bool', ...]`, `'whatsapp' => ...`, `'categories' => ['cast' => 'array', 'default' => self::notificationMatrix(), ...]`). Grepping the whole backend for consumers of that group outside the registry returns nothing — backend/app/Services/Notifications/NotificationService.php contains no setting lookup at all; its whatsapp() (line 91) and sms() (line 102) run unconditionally, and email() is likewise ungated.
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute it and could not. What I checked: QUOTED CODE EXISTS AS DESCRIBED.
/home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/settings/pages/NotificationPr
eferences.jsx lines 32-39 are verbatim the quoted save handler
(`settingsApi.group.update('notifications', values)` → `toast.success('Notification
preferences saved')`), and line 89 is the quoted Save button. Backend
SettingRegistry.php:130-137 declares the group exactly as quoted, with `categories` defaulting
to `notificationMatrix()` (11 categories × 5 channels at lines 142-160). THE SAVE REALLY
PERSISTS (so the toast is not lying about saving). settingsApi.js:34 sends `{ values }`, which
is the shape UpdateSettingsGroupRequest expects; SettingsGroupController::update →
SettingsService::setGroup persists every registry key and returns the refreshed group. So
state genuinely survives reload, as the repro says. THE PAGE IS REACHABLE, NOT DEAD CODE.
routes.jsx:119 lazy-imports it and :794 routes `settings/notification-preferences`;
SettingsLayout.jsx:20 links it in the settings nav with `ready: true`. NO CONSUMER EXISTS — I
looked in the places an auditor could plausibly have missed: - Grepped the whole backend
(app/, routes/, config/, database/) for the literal `'notifications'`/`"notifications"`: the
only hit outside unrelated contexts (EmailTemplateRegistry category label, OnboardingService
array key, config/services.php slack block) is the registry declaration itself. - Enumerated
every SettingsService read call site instead of trusting a keyword grep: `getGroup(` is called
only for `general`, `branding`, `currency`, `localization` and by the generic controller;
`settings->get(` call sites use groups `numbering`, `general`, `payroll`, purchase keys — none
use `notifications`. - backend/app/Services/Notifications/NotificationService.php: confirmed
no setting lookup at all; `whatsapp()` at line 91 and `sms()` at line 102 gate only on an
empty recipient, and `email()`/`emailHtml()` are likewise ungated. - Checked the newer Central
Notification Engine as the likely "somewhere else it is honored": NotificationEngine.php:48
takes channels from `$template->enabledChannels()` (DB templates), falling back to
`['in_app','email']` — never from settings. ChannelManager and NotificationQueueService
contain zero settings lookups either, so nothing in the queue/dispatch path consults the
matrix. - Frontend side (in case `browser`/`push` were honored client-side): grepping all of
frontend/src for the group returns only lines 29 and 35 of this same page. - Only other
reference anywhere is tests/Feature/Settings/SettingsGroupsDTest.php:70, which just asserts
the registry default round-trips — a test, not a consumer. No parent component, hook,
FormRequest, middleware, or default argument supplies the missing gate; the keys are write-
only configuration. The one nuance worth passing to the developer: the page's own copy calls
these "workspace defaults" and the service docblocks call WhatsApp/SMS "stubbed until a
provider is wired", so this looks like deliberate scaffolding rather than a regression — but
the admin-visible outcome (flip Email off for Payroll, save, emails still send) is exactly as
reported.

</details>

### Security Settings: the Sessions & Lockout card (2FA, single-session, session timeout, lockout) is decorative with no disclaimer

- **Owner:** mixed · **Module:** settings · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/settings/pages/SecuritySettings.jsx:70`
- **What it does:**
  Every key on this page stores fine and is read back, but nothing in the backend ever
  consults them. The Password Policy and Access Control cards at least carry a caveat in their
  subtitle; the Sessions & Lockout card carries none, so an admin turning on "Two-factor
  authentication" or "Allow only a single active session" gets a success toast and a security
  posture that has not changed.
- **Reproduce:**
  Open /app/settings/security → tick "Two-factor authentication (feature flag)" and "Allow
  only a single active session", set "Failed logins before lockout" to 3 and "Session timeout
  (minutes)" to 5 → Save → toast "Security settings saved", and the values persist across
  reload. Now sign in from two browsers at once (both stay live), fail the password ten times
  (no lockout), and idle for an hour (session still valid). No 2FA prompt ever appears.

```
SecuritySettings.jsx:69-83 renders the card with no caveat: `<h2 ...>Sessions & Lockout</h2>` (:70) then `{NUMS.slice(2).map(...)}` (:72) and `{TOGGLES.slice(4).map(...)}` (:78) — i.e. `session_timeout_minutes`, `failed_login_lockout`, `lockout_duration_minutes`, `remember_me_days`, `api_token_expiry_days`, `force_logout_after_password_change`, `single_session_only`, `two_factor_enabled`. Save at :98 `<button onClick={save} disabled={saving} ...>` → :40 `const d = await settingsApi.group.update('security', values) ... toast.success('Security settings saved')`.
Backend: grepping app/ for `session_timeout_minutes|failed_login_lockout|two_factor_enabled|single_session_only|password_min_length` returns exactly one hit, the declaration itself — app/Support/Settings/SettingRegistry.php:111 `'password_min_length' => ['cast' => 'int', 'default' => 8, 'rules' => [...]]`. No middleware, guard, login controller or FormRequest reads any of them.
```

<details><summary>What the refutation attempt checked</summary>

Opened the file — the quoted code is exact. SecuritySettings.jsx:69-84 is the "Sessions &
Lockout" card: `<h2>Sessions & Lockout</h2>` at :70 with NO `<p>` caveat under it, then
`NUMS.slice(2).map(...)` at :72 and `TOGGLES.slice(4).map(...)` at :78. The two neighbouring
cards DO carry caveats (:53 "applied when authentication enforcement lands", :88 "Placeholders
— stored now, enforced later"), which makes the omission on the middle card the exact
asymmetry described. Save at :98 -> :41 `settingsApi.group.update('security', values)` -> :43
`toast.success('Security settings saved')`. Refutation attempts, all failed: 1. Parent/wrapper
disclaimer — read src/modules/settings/SettingsLayout.jsx. It renders only "Workspace /
Settings" + the section nav + `<Outlet/>`. No page-level caveat, and Security is listed
`ready: true` (no "SOON" badge). Nothing outside the file supplies the missing warning. 2.
Dead code — no. Routed at src/app/routes.jsx:793 `<Route path="security" .../>` under
`/app/settings` (routes.jsx:445 `/app` + :779 `settings`), lazy-imported at :118. Backend
routes/settings.php:30-33 accept `security` in the group whitelist under `auth:sanctum` +
`role:admin`, so an admin really reaches it and the values really persist. 3. Backend consumer
somewhere the auditor missed — grepped the whole backend for every key on the card plus the
password keys and the string `'security'`. The ONLY hits are the declarations at
app/Support/Settings/SettingRegistry.php:110-126 and one unit test
(tests/Feature/Settings/SettingsGroupsDTest.php:64). No middleware, guard, FormRequest or
service reads them. 4. A settings->config bridge — grepped for runtime `config([...])`
overrides touching auth/session/security and for `auth_sessions` in app/Providers/. Zero hits.
Nothing maps the tenant setting onto the config the auth code actually reads. 5. Enforcement
under a different name — this is the one real complication, and it does not save the finding.
There IS live session machinery: app/Http/Middleware/EnforceIdleTimeout.php (registered
globally in bootstrap/app.php:27) and app/Services/Auth/SessionService.php. But both read
`config('auth_sessions.*')`, i.e. env vars (config/auth_sessions.php:
`AUTH_SESSION_IDLE_MINUTES`, `AUTH_SESSION_CONCURRENCY`, `AUTH_SESSION_MAX_DEVICES`), never
the settings group. So `session_timeout_minutes = 5` does nothing (effective idle window stays
30), and `single_session_only = true` does nothing (concurrency defaults to 'multi' with
`max_devices` 0 = unlimited, so both browsers stay live). Lockout: /api/auth/login
(routes/auth.php:12) has no throttle middleware, and Laravel's default api group only adds
`throttle:` when `throttleApi()` is called, which bootstrap/app.php never does;
AuthService::login:29-32 just does `Hash::check` and throws — no attempt counter. So ten
failed logins genuinely produce no lockout. 2FA: no TOTP/2FA/OTP code exists anywhere in
AuthController or app/Services/Auth/. One correction to the repro, which does not change the
verdict: "idle for an hour (session still valid)" is wrong for a non-remember-me login —
EnforceIdleTimeout will 401 it after 30 minutes. But that timeout is env-driven and ignores
`session_timeout_minutes`, and it is skipped entirely for remember-me sessions. The card's
controls remain inert either way. Note the source itself concedes the point at
SecuritySettings.jsx:6-7 ("authentication behaviour is unchanged for now") and
SettingRegistry.php:109 ("Security (settings only — no auth changes)") — both developer
comments, invisible to the admin. The fix is a one-line subtitle on the Sessions & Lockout
card matching the other two.

</details>

### Main dashboard fabricates KPI numbers whenever the tenant's real counts are zero

- **Owner:** mixed · **Module:** dashboard / shared · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/dashboard/DashboardPage.jsx:142`
- **What it does:**
  The `/dashboard` query has a `select` that inspects three real counts and, if all three are
  zero, throws the entire real response away and substitutes a hardcoded block (128 contacts,
  34 open deals, ₹284,500 pipeline, 68% win rate). A brand-new or quiet tenant sees invented
  business metrics on the first screen after login.
- **Reproduce:**
  Sign in on a fresh tenant with no contacts, no deals and no tasks due today. /app/dashboard
  renders "Total Contacts 128", "Open Deals 34", "Pipeline ₹2,84,500", "Win Rate 68%". Worse:
  a tenant that genuinely has 3 overdue invoices but zero contacts/deals/tasks has its real
  overdue_invoices value discarded and replaced with the literal 3 from the fake block — the
  numbers on screen are not the tenant's.

```
DashboardPage.jsx:141-154 `// Only show real data if at least one count is non-zero`
`select: (apiData) => {`
`  const hasRealData = apiData.contacts_count > 0 || apiData.open_deals > 0 || apiData.tasks_due_today > 0`
`  return hasRealData ? apiData : { contacts_count: 128, open_deals: 34, tasks_due_today: 7, overdue_invoices: 3, pipeline_value: 284500, win_rate: 68 }`
`},`
The same literals also sit in `placeholderData` at :133-140, so the fakes are shown while loading as well.
```

<details><summary>What the refutation attempt checked</summary>

Could not refute. Opened /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/pages/dashboard/DashboardPage.jsx: the quoted
code is present verbatim — placeholderData with the fake block at :133-140, `select: (apiData)
=> {` exactly at :142, `hasRealData` check at :143-145, and the substituted literals
(contacts_count 128, open_deals 34, tasks_due_today 7, overdue_invoices 3, pipeline_value
284500, win_rate 68) at :146-153, under the comment "// Only show real data if at least one
count is non-zero" at :141. Reachability: it is routed and is the default landing page after
login. app/routes.jsx:18 lazy-imports it and :448 mounts `<Route path="dashboard"
element={<S><DashboardPage /></S>} />` under /app; :447 redirects the /app index to it;
pages/auth/LoginPage.jsx:52 defaults the post-login target to '/app/dashboard';
router/ProtectedRoute.jsx:25 routes normal roles there. Not dead code. Guards: no dev/demo/env
gate exists — grep for import.meta.env, process.env, DEV, MOCK, demo in the file returns
nothing. lib/api.js is a real axios instance (baseURL VITE_API_URL ||
http://127.0.0.1:8000/api) whose only interceptors attach the bearer token and handle session-
ending 401s; there is no mock/MSW layer that would replace the response. Backend check made it
broader, not narrower: backend/routes/auth.php:32 maps GET /dashboard to
backend/app/Http/Controllers/Api/DashboardController.php, which hardcodes contacts_count,
open_deals, tasks_due_today, overdue_invoices, pipeline_value, win_rate and revenue_this_month
all to 0 for every tenant ("As modules are built, add real queries here"). So hasRealData is
always false and the fabricated block is shown to EVERY tenant on every load, not just
fresh/quiet ones. React Query is ^5.56.0, where select also transforms placeholderData, so the
fakes render while loading as well. Render path confirmed: the kpis array feeds
data?.contacts_count?.toLocaleString(), data?.open_deals, data?.tasks_due_today,
data?.overdue_invoices and pipeline_value into the four KpiCards. isError is destructured at
:129 but never used in the JSX, so an API error also leaves the fake numbers on screen.
Adjacent recentActivity entries ("Acme Corp — $12,500") and barData are hardcoded fakes too.
One inaccuracy in the audit that does not refute it: the repro's claim that a tenant with 3
genuine overdue invoices loses its real value is currently unreachable, since the controller
also hardcodes overdue_invoices to 0 — that scenario is hypothetical about a future backend.
The core claim (fabricated KPI numbers rendered as the tenant's own on the first screen after
login) is confirmed and in fact affects all tenants today.

</details>

### Dashboard "View all" on Recent Activity has no onClick, and the feed it fronts is a hardcoded array

- **Owner:** mixed · **Module:** dashboard / shared · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/dashboard/DashboardPage.jsx:343`
- **What it does:**
  The Recent Activity card's "View all →" control is the only <button> on the page and it
  carries no onClick — only two cosmetic mouse handlers that set the same gap value on enter
  and leave. The five rows it sits above are a literal array of invented events, not data from
  any endpoint.
- **Reproduce:**
  Sign in as any user → /app/dashboard → the Recent Activity card lists "New deal created —
  Acme Corp — $12,500", "Contact added — Sarah Johnson — Globex Inc." etc. for every tenant,
  identical every time. Click "View all →": nothing happens, no navigation, no menu. The hover
  effect fires, so the control reads as live.

```
DashboardPage.jsx:343-349 `<button className="flex items-center gap-1 text-xs font-semibold transition-all" style={{ color: '#a78bfa' }} onMouseEnter={e => e.currentTarget.style.gap = '4px'} onMouseLeave={e => e.currentTarget.style.gap = '4px'}> View all <ArrowRight size={12} /> </button>` — no onClick, no type, not inside a form.
The data it fronts, DashboardPage.jsx:196-202: `const recentActivity = [ { action: 'New deal created',  description: 'Acme Corp — $12,500', time: '2m ago' }, { action: 'Invoice sent', description: 'INV-2024-042 to TechCorp Ltd', time: '1h ago' }, ... ]`, rendered at :353 `{recentActivity.map((item, i) => (<ActivityItem key={i} idx={i} {...item} />))}`. There is no activity endpoint call in the file.
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED after trying to refute it on six fronts. 1) Code exists verbatim at the exact lines.
`awk` on /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/pages/dashboard/DashboardPage.jsx (421 lines)
prints 343-349 as the `<button className="flex items-center gap-1 text-xs font-semibold
transition-all" style={{color:'#a78bfa'}} onMouseEnter=... onMouseLeave=...>View all
<ArrowRight size={12}/></button>` block, and 196-202 as the literal `recentActivity` array
with "Acme Corp — $12,500", "INV-2024-042 to TechCorp Ltd", "Sarah Johnson — Globex Inc.",
etc. Line numbers in the audit are exact. 2) Handler is genuinely absent, not supplied
elsewhere. `grep -n "onClick|<button|<Link|navigate|useNavigate|href|type="` over the whole
file returns exactly ONE hit: line 343, the `<button` itself. Zero onClick, zero Link, zero
useNavigate, zero href in all 421 lines. The button takes no props from a parent (it is
written inline in DashboardPage's own JSX), so there is no spread prop or wrapper to inherit a
handler from. 3) No global/delegated click handler catches it. The only document-level click
listener in src/ is src/components/ui/MediaLightbox.jsx:75
(`document.addEventListener('click', open, true)`), and its handler at :43-60 acts only when
`tgt.tagName === 'IMG'` or `'VIDEO'` and `isInsideRichArea(tgt)`. A `<button>` on the
dashboard never matches. No onClickCapture anywhere. 4) The file is live, not dead code.
src/app/routes.jsx:18 lazy-imports it and :448 renders `<Route path="dashboard"
element={<S><DashboardPage/></S>} />` under `/app` (ProtectedRoute + AppShell). `S` is only
`({children}) => <Suspense fallback={<PageLoader/>}>{children}</Suspense>` (routes.jsx:393) —
pure Suspense, no event wiring. Line 448 is the only `path="dashboard"` directly under `/app`
(the other 11 hits are nested under hr/sales/accounts/purchase/tpv or the portals), so nothing
shadows it. routes.jsx:447 `<Route index element={<Navigate to="dashboard" replace/>} />`
makes it the post-login landing screen — every user hits it. 5) No earlier guard prevents the
state. The card renders unconditionally; the hardcoded array is not behind a loading/error
branch. 6) Nothing handles it generically. The only API call in the file is
`api.get('/dashboard')` at :131 for KPIs; `recentActivity` is never fed from it.
src/services/dashboardApi.js exposes only sales/hr/main stat getters — no activity method.
Backend routes/ has activity endpoints only inside sales.php, customer.php and tpv.php; there
is no global activity feed and this page calls none of them. One minor correction to the REPRO
wording, which makes it slightly deader rather than refuting it: both mouse handlers set `gap
= '4px'` and the base class `gap-1` is already 0.25rem = 4px, so the hover produces no visible
change at all — the control is inert on hover as well as on click. Separately (beyond the
claim's scope, noted only as context): :133-155 also force fabricated KPI values via
`placeholderData` plus a `select` that discards real API data whenever contacts/deals/tasks
are all zero, so the fabricated-data pattern on this page is broader than the activity feed
alone.

</details>

### Sortable table headers re-sort only the rows already downloaded, not the query

- **Owner:** Zafar · **Module:** shared · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/components/ui/DataTable.jsx:11`
- **What it does:**
  DataTable's sort is a pure client-side sort of the `rows` prop. On the Accounts pages that
  feed it a hard-capped server page, clicking a sortable header reorders only that page — the
  arrow appears and the order changes, but no request is made and the true largest/smallest
  rows in the table are never fetched.
- **Reproduce:**
  Open /app/accounts/vouchers with 200 vouchers (only the 50 newest are loaded, ordered by
  date desc). Click the "Amount" header. It shows an arrow and reorders, giving the largest of
  the newest 50 — not the largest voucher in the ledger. Affects Vouchers, ChartOfAccounts,
  Registers, Budgets, BankAccounts, Transfer (6 Accounts pages passing `sortable: true`).

```
  const sortedRows = useMemo(() => {
    if (!sortKey) return rows
    return [...rows].sort((a, b) => {
      const av = a[sortKey]
      const bv = b[sortKey]
      …
  }, [rows, sortKey, sortDir])
```

<details><summary>What the refutation attempt checked</summary>

Tried hard to refute it and could not for the primary repro. 1. Code exists as quoted.
/home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/DataTable.jsx:12-21 is
verbatim the quoted `sortedRows` useMemo over `[...rows]`. The component signature at line 8
is `({ columns, rows, keyField = 'id', onRowClick, emptyState })` — no rest/spread, no
`onSort`, `sortKey`, `total`, or server-sort escape hatch. A repo-wide grep for
`onSort|serverSort|sortKey=|defaultSort` across all .jsx returns zero hits, so no parent,
hook, or wrapper supplies one anywhere. 2. Not shadowed or dead. `find src -name "DataTable*"`
returns exactly one file; vite.config.js aliases `@` -> `/src`, so `@/components/ui/DataTable`
resolves to the audited file. routes.jsx:141 lazy-imports Vouchers and line 543 registers
`<Route path="vouchers">` under `accounts`, so /app/accounts/vouchers is live. 3. The cap is
real, and there is no pagination to escape it. Vouchers.jsx:30-34 calls
`accountsApi.vouchers.list({ ...filters, per_page: 50 })` and takes `page?.data ?? []`. There
is no `page` state, no `Pagination`/`TablePagination` import, no "load more" — grep for
`Pagination|last_page|current_page|total` in Vouchers.jsx hits only the voucher-drawer
debit/credit totals. So only the server's first page is ever in `rows`. 4. Server side
confirms both halves of the claim. VoucherController::index
(app/Http/Controllers/Api/Accounts/VoucherController.php:26-30) forwards only `type, status,
from, to, search, per_page` — no sort/order param is accepted at all.
VoucherService::list:40-41 ends `->orderByDesc('date')->orderByDesc('id')->paginate(per_page
?? 25)`. So the loaded 50 are the newest by date, and clicking "Amount" reorders exactly those
50 client-side, never refetching. The repro is accurate as written. 5. No generic guard.
Nothing in the render path re-queries on sort; react-query's key is
`['accounts','vouchers',filters]` and `filters` has no sort field, so no request is triggered.
Scope correction for whoever fixes it: of the 6 pages listed, 4 are genuinely affected because
they consume a capped server page — Vouchers (per_page 50), ChartOfAccounts.jsx:31 (per_page
100), Registers.jsx:38 (per_page 200, backend clamps to 500 max), Transfer.jsx:48 (history
per_page 50). The other 2 are false positives inside the finding: Budgets.jsx:22
(`accountsApi.budgets.list`) and BankAccounts.jsx:101 (`accountsApi.bankAccounts.list`) hit
BudgetController::index and BankAccountController::index, both of which return the full
unpaginated collection, so a client-side sort there is correct and complete. (BankAccounts'
sub-tables at lines 205 and 266 do use capped queries.) The core defect stands.

</details>

### Sales CSV export searches different columns than the on-screen search box, so the file disagrees with the screen

- **Owner:** Zafar · **Module:** sales · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `backend/app/Services/Sales/SalesExportService.php:44`
- **What it does:**
  The list pages search client-side across a set of fields that includes the joined client
  name; the export endpoint re-runs `search` server-side against a much narrower column list.
  Searching by anything outside that list produces a screen full of rows and a CSV with none.
- **Reproduce:**
  On /app/sales/invoices type a client name, e.g. "Acme", in the search box. The table filters
  down to Acme's invoices (the hook matches the `client` field). Click Export → the request is
  /sales/export/invoices?search=Acme, which LIKEs only `number` and `reference`, so the
  downloaded CSV contains just the header row. Payments is the same: the screen searches
  invoice number + client (Payments.jsx:75) while the exporter searches transaction_id + mode
  (SalesExportService.php:96).

```
// backend SalesExportService.php:44
                'search' => ['number', 'reference'],

// frontend Invoices.jsx:186 — what the box actually searches
    useListView(data, ['number', 'client', 'status', 'reference'])

// frontend Invoices.jsx:226 — the same term is handed to the exporter
        onExport={() => exportSalesList('invoices', { status: filter !== 'All' ? filter : undefined, search: search || undefined })
```

<details><summary>What the refutation attempt checked</summary>

Could not refute; every refutation avenue checked and closed. (1) Quoted code is verbatim at
the stated path: SalesExportService.php:44 is `'search' => ['number', 'reference'],` and :96
is `'search' => ['transaction_id', 'mode'],`. (2) Frontend evidence exact: Invoices.jsx:186
`useListView(data, ['number','client','status','reference'])`, Invoices.jsx:226 forwards
`search` to exportSalesList; Payments.jsx:75 filters invoice_number+client, :154 forwards
search. (3) The param is not supplied or widened anywhere in between — salesApi.js:57 passes
params straight to the querystring, SalesExportController.php:30 does
`$request->only('status','type','search')`, and rows() (lines 162-173) LIKEs only
$def['search']. No FormRequest, middleware, default arg, or shared wrapper intervenes. (4) Not
dead code: backend/routes/api.php:15 requires sales.php, which registers
`Route::get('/export/{type}', SalesExportController::class)` at line 93; pages are routed at
frontend/src/app/routes.jsx:505 and :507. (5) The Export button really renders —
ListToolbar.jsx:72-78 renders it whenever onExport is passed, which both pages do. (6) No
guard prevents the state: useListView.js:41-44 genuinely matches the `client` field client-
side, so the screen fills while the SQL LIKE matches nothing and rows() returns only
[$def['header']] — a silent header-only CSV, no error toast, no axios interceptor involved.
(7) Verified `client` is not a searchable column: SalesInvoice.php:77 `protected $appends =
['client']` is an accessor over the customer() relation (line 158), so even adding 'client' to
the list would be stripped by the Schema::hasColumn filter at line 166 — meaning the fix
requires a join, not a column name. The service's own docblock (lines 24-25) claims "the same
`search` matching the UI applies, so what you download is what you were looking at", which the
code does not honor. Scope is actually wider than claimed: estimates, credit-notes, delivery-
notes and contracts have the same narrower server-side search than their pages.

</details>

### Leads and Payments Export ignore the active filter and dump the whole table

- **Owner:** Zafar · **Module:** sales · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/sales/pages/Leads.jsx:251`
- **What it does:**
  Both pages narrow the list with a server-side filter (Leads by status_id, Payments by mode)
  but pass only `search` to the export call, so the CSV contains every row regardless of the
  tab you are looking at. For Leads the export can never honour a status filter anyway — the
  exporter filters on a `status` column and the leads table only has `status_id`.
- **Reproduce:**
  Open /app/sales/leads, click the "Qualified" status tab (10 of 400 leads shown), click
  Export. The file contains all 400 leads. Same on /app/sales/payments: pick Mode = "Bank
  Transfer", export, and cash/cheque payments are in the file.

```
// Leads.jsx:251 — `filter` (the status tab) is not passed
            onExport={() => exportSalesList('leads', { search: search || undefined })

// Payments.jsx:50 vs Payments.jsx:154 — `filterMode` is sent to the list, dropped on export
    salesApi.payments.list({ mode: filterMode !== 'All' ? filterMode : undefined })
            onExport={() => exportSalesList('payments', { search: search || undefined })

// backend SalesExportService.php:155 — leads has no `status` column (only status_id), so it could not work
        if (! empty($filters['status']) && $filters['status'] !== 'All' && Schema::hasColumn($table, 'status')) {
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED — I opened every file and could not find anything that prevents the outcome. Quoted
code exists verbatim: - /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Leads.jsx:251 — `onExport={()
=> exportSalesList('leads', { search: search || undefined })`. Line 47 loads the list with
`salesApi.leads.list(filter !== 'all' ? { status_id: filter } : {})`, and the tabs at line 219
do `setFilter(s.id)`, so `filter` is a real status id. It is not passed to the export. -
/home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Payments.jsx:50
vs :154 — `mode: filterMode` on the list, only `search` on the export. Nothing supplies the
missing param elsewhere. I checked the shared wrapper /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/ListToolbar.jsx (line 73 calls
`onClick={onExport}` with no arguments and injects nothing) and the API helper /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/services/salesApi.js:56 (`exportSalesList` passes
`params` straight to axios untouched — no defaults, no merge). There is no parent component,
hook, or spread prop adding a filter. `useListView(filtered, [])` only pages the already-
fetched array; `filtered` on Payments applies search only, no client-side mode narrowing.
Backend confirms it could not work even if sent. SalesExportController.php:30 whitelists
`$request->only('status', 'type', 'search')` — `status_id` and `mode` are dropped before
reaching the service. SalesExportService.php:156 guards on `Schema::hasColumn($table,
'status')`, and I checked the migrations: 2026_07_07_000001_create_leads_core_tables.php:67
defines only `status_id` (FK to lead_statuses); no migration touching `leads` (the 2026_07_15
parity and 2026_10_09 engagement ones) adds a `status` column. The 'payments' definition (line
93) has no mode filter at all. So the exporter genuinely cannot honour either filter.
Reachability: both pages are routed — routes.jsx:89 and :100 lazy-import them — and both are
in Sidebar.jsx (:113 leads, :125 payments). Not dead code. No guard blocks the described
state; the Export button renders unconditionally whenever `onExport` is passed. One correction
to the finding's framing, which does not change the verdict: on Payments the mode filter is
not actually working on the list either. `paymentApi.list` (invoiceApi.js:38) forwards `mode`
to `GET /sales/invoices`, but InvoiceController::index reads only `status` and `client_id`, so
`mode` is silently ignored there too — the Mode tabs are currently cosmetic. The export
outcome described in the repro is still exactly right, but a fixer should know the Payments
filter needs backend support on both paths, not just the export param. The Leads half is
precisely as described.

</details>

### Estimates "Expired" tab is computed in the browser; exporting it asks the DB for a status nothing ever writes

- **Owner:** Zafar · **Module:** sales · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/sales/pages/Estimates.jsx:56`
- **What it does:**
  Expiry is derived on the client from valid_until, but Export forwards the literal filter
  value to the server, which matches it against the stored `status` column. No Sales code ever
  sets an estimate's status to 'Expired', so the export of an Expired-filtered screen is
  always empty.
- **Reproduce:**
  Create 5 estimates with valid_until in the past and status 'Sent'. Open /app/sales/estimates
  — the Expired KPI reads 5 and the Expired tab lists them. Click Export while on that tab →
  GET /sales/export/estimates?status=Expired → `where('status','Expired')` matches nothing →
  CSV contains only the header row.

```
// Estimates.jsx:56 — client-derived status
const effectiveStatus = (e) => (isExpired(e) ? 'Expired' : e.status)

// Estimates.jsx:217 — the tab filters on the derived value
  const byStatus = filter === 'All' ? data : data.filter(e => effectiveStatus(e) === filter)

// Estimates.jsx:320 — but the derived value is sent to the server as a column filter
          onExport={() => exportSalesList('estimates', { type: docType, status: filter !== 'All' ? filter : undefined, search: search || undefined })
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED after trying hard to break it. (1) Quoted code is verbatim at
frontend/src/modules/sales/pages/Estimates.jsx — line 56 effectiveStatus derives 'Expired'
client-side, line 217 filters the tab on the derived value, line 320 forwards `filter`
literally as the server `status` param. The docblock above line 56 even admits "Nothing in the
app ever writes the 'Expired' status — the old CRM did it from a nightly cron." (2) Traced the
server side: SalesExportController passes $request->only('status','type','search') straight
into SalesExportService::rows, and SalesExportService.php:156-157 does a literal
$query->where('status', $filters['status']) with no Expired special-case. Critically, the
Schema::hasColumn guard PASSES — migration 2026_07_04_000003 line 47 defines
enum('status',['Draft','Sent','Accepted','Declined','Expired']) — so the filter really applies
rather than silently falling through and exporting everything. (3) Ruled out a writer of the
status: no scheduler entry or console command touches Estimate (ExpireOffers operates on
HrOffer, SweepExpiredContracts on contracts); EstimateService writes only 'Draft', 'Sent' and
'Accepted'; the Estimate model has no observer, accessor, or global scope on status. (4) Ruled
out generic handling: lib/api.js interceptors only attach a Bearer token and handle auth
responses, with no paramsSerializer or param rewriting. (5) Ruled out dead code / unreachable
state: the page is routed at routes.jsx:502-503 (/app/sales/estimates and /app/sales/proforma-
invoices), the tab strip is ['All', ...STATUSES] which includes 'Expired', and ListToolbar:72
renders the Export button whenever onExport is supplied — which it is. One nuance that does
NOT rescue the code: the edit drawer's status select (lines 547-548) offers 'Expired' and
Store/UpdateEstimateRequest permit it, so a manually-set Expired row would export; "always
empty" is thus marginally overstated. But the derived-expired rows (status 'Sent' with a past
valid_until — the entire reason the tab and KPI exist) are invisible to the export filter, so
the exported set genuinely does not match the on-screen set.

</details>

### Ticket grid rows-per-page truncates with no pager and no count of what is hidden

- **Owner:** Shivam · **Module:** helpdesk · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/helpdesk/pages/TicketGrid.jsx:195`
- **What it does:**
  The grid loads every ticket in the tenant and then shows only the first `pageSize`. There is
  no page navigation and no "x of y" readout, so the table silently looks like the tenant has
  25 tickets. Sorting a column re-orders the whole set before the slice, so changing sort
  silently swaps which 25 you can see.
- **Reproduce:**
  With 400 tickets, open /app/helpdesk/tickets. 25 rows render; the tab badges say "open 312"
  but nothing on the table says rows are hidden and there is no Next control. The only escape
  is choosing "All" in ListControls, which renders all 400 rows in one DOM table.

```
  const pagedRows = useMemo(() => (pageSize === 0 ? rows : rows.slice(0, pageSize)), [rows, pageSize])

// line 127 — the whole table is fetched with no server filter/paging at all
  const { data: raw = [], isLoading, refetch: refetchTickets } = useQuery({ queryKey: ['helpdesk-tickets'], queryFn: () => helpdeskApi.tickets.list() })
```

<details><summary>What the refutation attempt checked</summary>

Verified — I tried to refute it on five fronts and each attempt failed. 1) Quoted code exists
verbatim. `/home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/helpdesk/pages/TicketGrid.jsx:195` is
exactly `const pagedRows = useMemo(() => (pageSize === 0 ? rows : rows.slice(0, pageSize)),
[rows, pageSize])`, and line 127 is the unparameterised `useQuery({ queryKey: ['helpdesk-
tickets'], queryFn: () => helpdeskApi.tickets.list() })`. `pageSize` defaults to 25 (line
113). `pagedRows` is what the tbody maps (line 445). 2) No pager anywhere in the component. I
grepped the whole 604-line file case-insensitively for next/prev/showing/paginat/page — the
only hits are the `pageSize` state, the `ListControls` mount at line 333, and unrelated `prev`
identifiers in the optimistic-mutation rollback. There is no footer element at all: after
`</table>` (line ~557) the JSX goes straight to `NewTicketModal` and `ConfirmModal`. 3) The
only count on screen does not disclose the cap, and arguably contradicts it. Line 295 renders
`{rows.length} of {tickets.length} tickets` — that is filtered-vs-loaded, not visible-vs-
filtered. With 400 tickets and the default "all" view it reads "400 of 400 tickets" while 25
rows render. The tab badges (`counts`, lines 143-151) are likewise computed over the full
`tickets` array, so they show 312 open while 25 rows exist, exactly as the repro says. 4)
Nothing upstream limits the payload. `helpdeskApi.tickets.list()`
(`frontend/src/services/helpdeskApi.js:67-68`) passes `params` undefined. Backend
`TicketController::index` builds filters only from query params and calls
`HelpdeskService::listTickets`, which returns a `Collection`; `TicketRepository::filtered`
ends in `return $query->latest()->get();` — no `paginate`, no `limit`, no `take`. So the
client really does receive every ticket the caller may see. 5) File is live, not dead code.
`frontend/src/app/routes.jsx:166` lazy-imports it and line 573 mounts it at `<Route
path="tickets">` under the helpdesk layout, i.e. /app/helpdesk/tickets. 6) The sort-before-
slice claim holds: sorting happens inside the `rows` useMemo (lines 171-192) over the whole
filtered set, and the slice is applied afterwards, so changing sort key/direction swaps which
25 rows are reachable. Two caveats the developer should know, neither of which refutes the
finding. (a) This is a codebase-wide convention, not a TicketGrid one-off —
`inventory/pages/ProductList.jsx:82` and `projects/pages/ProjectList.jsx:109` do the identical
`slice(0, pageSize)` with no pager, so a fix should probably be made in the shared layer. (b)
The shared `components/ui/ListControls.jsx` labels the selector `aria-label="Rows per page"` /
`title="Rows per page"`, which actively promises pagination that does not exist — this
strengthens rather than weakens the claim. Separately I noticed `toggleAll` selects all `rows`
(400) despite the comment saying selection is scoped to what is visible; that is adjacent, not
part of this finding.

</details>

### Projects and Inventory Products repeat the truncate-with-no-pager pattern

- **Owner:** Shivam · **Module:** projects · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/projects/pages/ProjectList.jsx:109`
- **What it does:**
  Both pages render a rows-per-page selector that slices from index 0 with no page offset and
  no pager, so rows beyond the selected size cannot be reached. The Project list comment even
  asserts the header count "works off the full filtered set" — which is precisely what makes
  the missing pager confusing rather than obvious.
- **Reproduce:**
  Create 60 projects. Open /app/projects — 25 rows, a "25/50/100/All" selector, and no Next
  control; project 26 is unreachable at the default size. Same on /app/inventory/products
  (ProductList.jsx:82) with 60 products.

```
// ProjectList.jsx:108-111
  const visible = useMemo(
    () => pageSize === 0 ? projects : projects.slice(0, pageSize),
    [projects, pageSize],
  )

// ProductList.jsx:82
  const pagedProducts = pageSize === 0 ? products : products.slice(0, pageSize)
```

<details><summary>What the refutation attempt checked</summary>

Opened both files and the quoted code is verbatim at the stated paths/lines.
ProjectList.jsx:106-110 has the commented useMemo `pageSize === 0 ? projects :
projects.slice(0, pageSize)` (comment: "Page-size limits the rows shown client-side; 0 = All.
The header count and the CSV export both work off the full filtered set, not this slice."),
and ProductList.jsx:82 has `const pagedProducts = pageSize === 0 ? products :
products.slice(0, pageSize)`. Both slice from index 0 with no page-index state. Refutation
attempts, all failed: - Pager elsewhere in the page: grepped both files for
page/paged/Next/Prev/offset/slice and read the full render bodies to EOF. ProjectList renders
`visible.map` (line 237) then only ProjectFormDrawer + ConfirmModal; ProductList renders
`pagedProducts.map` (line 307) then modals. No pager markup, no "showing X of Y". - Pager
inside a shared wrapper: read /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/ListControls.jsx — it renders only
a rows-per-page `<select>` (aria-label "Rows per page") and a refresh button. No next/prev.
ProjectList doesn't even pass pageSize to it (its own Select at line 186-187 drives PAGE_SIZES
= [10,25,50,100,0]); ProductList passes pageSize/onPageSize at line 178. - Dead code: both are
routed — app/routes.jsx:180+741 (`/app/projects`) and 190+756 (`/app/inventory/products`). -
Server-side pagination that would make the slice moot: ProjectController::index returns
`$this->projects->list(...)` and ProjectService::list (line 156) returns an unpaginated
Collection; projectApi.list / inventoryApi.products.list are plain GETs with filter params
only — no page param, no meta envelope. So the client receives the whole filtered set and
drops everything past `pageSize`. - Already-generic handling: the codebase does have real
pagers (components/ui/Pagination.jsx, components/ui/TablePagination.jsx — the latter
explicitly documented as giving "an honest 'showing X–Y of Z'", used by
modules/tpv/pages/TpvVendors.jsx:256). Neither ProjectList nor ProductList imports either,
which makes these two inconsistent with the in-house pattern rather than deliberately pager-
free. Aggravating detail confirmed: both headers show the full filtered total
(`{projects.length}` ProjectList.jsx:132, `{products.length}` ProductList.jsx:163) while only
`pageSize` rows render, so the badge says 60 next to 25 visible rows with no offset control.
One mitigation the finding already acknowledges: the size selector includes "All" (0) and
50/100 on both pages, so no row is permanently unreachable — a user who realizes the dropdown
is the escape hatch can see everything in one click. That lowers severity (data
loss/unreachability is not absolute) but does not refute the claim: at the default 25 there is
no pager and no indication that rows are hidden. Same pattern also exists at
helpdesk/pages/TicketGrid.jsx:195 and projects/components/ProjectTabs.jsx:144 if a fix is
generalized.

</details>

### HR Department and Designation filter dropdowns are built from only the first 200 employees

- **Owner:** Harshal · **Module:** hr · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/hr/pages/Employees.jsx:134`
- **What it does:**
  The filter options are derived by de-duplicating the `department` / `designation` values of
  a single 200-row page of employees. A department whose staff all sort outside that page
  never appears as an option, so the server-side filter that would work perfectly is simply
  not offered.
- **Reproduce:**
  Seed 300 employees where everyone in "Logistics" was created earliest (so they fall outside
  the newest-200 page). Open /app/hr/employees — "Logistics" is absent from the Department
  dropdown, and there is no way to filter to it even though
  hrApi.employees.listPaged({department:'Logistics'}) works. Identical construction on
  Attendance.jsx:64/66-67.

```
// Employees.jsx:134 — one 200-row page powers the dropdowns
  useEffect(()=>{ hrApi.employees.list({ per_page: 200 }).then(r => setOptionsList(Array.isArray(r) ? r : (r?.data ?? []))).catch(()=>{}) },[])

// Employees.jsx:138-139
  const departments = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.department).filter(Boolean))], [optionsList])
  const designations = useMemo(()=>['All', ...new Set(optionsList.map(e=>e.designation).filter(Boolean))], [optionsList])
```

<details><summary>What the refutation attempt checked</summary>

Verified end-to-end and could not refute it. 1) Code exists exactly as quoted. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/hr/pages/Employees.jsx:134 is verbatim
`useEffect(()=>{ hrApi.employees.list({ per_page: 200 }).then(r =>
setOptionsList(...)).catch(()=>{}) },[])`, and lines 138-139 build
`departments`/`designations` by de-duplicating `optionsList`. `optionsList` is declared once
(line 85) and written only by that one effect; grep found no other setter. 2) The dropdowns
really are wired to those memos. Employees.jsx:212 and :216 render the Department and
Designation filter selects from `departments`/`designations`. No merge with another source, no
escape hatch for a selected-but-missing value. 3) Checked the place the auditor might have
missed, and it exists but is NOT used for filters. The same component already loads Org Setup
master data via `useMasterData()` (lines 73-78:
deptNames/desigNames/deptOptions/desigOptions), which is the complete department/designation
list. Grep shows `deptOptions(form)`/`desigOptions(form)` are used only inside the add/edit
modal (lines 346, 352), never in the filter bar. The superset is loaded on the page but not
offered as filter options. 4) The 200 cap is real, not nominal. hrApi.js:227 `list` hits GET
/hr/employees -> routes/hr.php:238 -> EmployeeController::index (passes per_page through) ->
EmployeeService::list -> EmployeeRepository::filtered, which does `$perPage = max(1,
min($perPage, 200)); return $query->latest()->paginate($perPage);`
(EmployeeRepository.php:42-45). One page of the newest 200 rows by created_at, and the client
cannot request more. 5) The server-side filter it fails to offer does work:
EmployeeRepository::filtered lines 20-25 apply `where('department', ...)` and
`where('designation', ...)`, and Employees.jsx:112-113 send them, so only the UI option is
missing. 6) Reachability: routed and live. routes.jsx:54 lazy-imports the page, routes.jsx:471
mounts it at `employees` under the HR layout (/app/hr/employees), and it is linked from
Sidebar.jsx:79, HRLayout.jsx:29 and HRDashboard.jsx:248. Not dead code. 7) Attendance.jsx:64
and :66-67 are the identical construction (`hrApi.employees.list({ status:'Active', per_page:
200 })` feeding the same two Sets), so the second half of the claim holds too. No guard
prevents the state: nothing caps a tenant at 200 employees, and the comment in EmployeeService
explicitly describes a SangoeTrack bulk importer for "hundreds of existing staff". One
fairness note that lowers severity without refuting: the REPRO's "no way to filter to it" is
overstated. The free-text Search box maps to `params.search` and EmployeeRepository lines
29-38 match department and designation with LIKE, so typing "Logistics" still surfaces those
employees. The dropdown option is nonetheless genuinely absent, and the fix is trivial since
`deptNames`/`desigNames` are already in scope at lines 74-75.

</details>

### Purchase Order Returns and Vendor Items export only the page on screen with no warning

- **Owner:** Harshal · **Module:** purchase · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/purchase/pages/PurchaseOrderReturns.jsx:77`
- **What it does:**
  Both pages are correctly server-paginated, but the Export button feeds `page.data` — the
  current page — into exportCsv. The button is labelled plainly "Export", the footer right
  beside it says "Showing 1 to 25 of 480 entries", and the file contains 25 rows.
  (TpvVendors.jsx:180 does the same thing but at least renders an exportScopeNote saying so;
  these two do not.)
- **Reproduce:**
  Create 100 order returns. Open /app/purchase/order-returns with Rows = 25. Footer reads
  "Showing 1 to 25 of 100 entries". Click Export → order-returns-<date>.csv holds 25 data
  rows. Same on /app/purchase/vendor-items (PurchaseVendorItems.jsx:84).

```
// PurchaseOrderReturns.jsx:69 — rows is one server page
  const rows = page.data || []

// PurchaseOrderReturns.jsx:77 — and that page is what gets exported
  const doExport = () => exportCsv(stampedName('order-returns'), rows, [
    { label: 'Order Return Number', value: r => r.or_number },

// PurchaseVendorItems.jsx:84
  const doExport = () => exportCsv(stampedName('vendor-items'), rows, [
```

<details><summary>What the refutation attempt checked</summary>

Could not refute; every check confirmed the finding. (1) Quoted code exists verbatim at the
exact lines: PurchaseOrderReturns.jsx:69 `const rows = page.data || []` and :77 `doExport = ()
=> exportCsv(stampedName('order-returns'), rows, [...])`; PurchaseVendorItems.jsx:84
identical. (2) Checked the helper — frontend/src/lib/exportCsv.js is purely client-side,
iterating the passed array into a Blob with no API call or re-fetch, so nothing widens the
scope downstream. (3) Verified pagination is genuinely server-side, so page.data really is one
page: routes/purchase.php -> PurchaseOrderReturnController::index ->
PurchaseOrderReturnService::list -> PurchaseOrderReturnRepository::filtered ending in
`$query->latest()->paginate($perPage)` with `$perPage = min($perPage, 200)`; the repo comment
says "cap it so a rogue per_page can't dump the table." Same chain for PurchaseVendorItem*. No
escape hatch loads the full set. (4) Both pages are reachable, not dead code:
routes.jsx:237-238 lazy imports, :624 `vendor-items` and :626 `order-returns` under the
purchase layout — repro URLs correct. (5) No mitigation on either page: buttons are `<button
onClick={doExport} disabled={!rows.length}>Export</button>` with no title tooltip and no
adjacent note; both pages hand-roll their toolbar instead of using the shared wrapper. (6) The
codebase's own convention proves this is a recognized hazard —
components/ui/TableToolbar.jsx:26 accepts `exportScopeNote` and renders it at :146, and
TpvVendors.jsx:176 supplies "Exports the N row(s) on this page. Set Rows to X to include
more." These two pages bypass TableToolbar entirely. (7) Misleading footer confirmed:
PurchaseOrderReturns.jsx:185 and PurchaseVendorItems.jsx:191 both render "Showing {page.from}
to {page.to} of {page.total} entries", so the full total sits on-screen next to a plain Export
that silently yields only the current page. Minor write-up slips, not material: the
exportScopeNote is at TpvVendors.jsx:176-178 not 180, and the footer is in the pagination bar
below the table rather than literally beside the button. This is a silent data-completeness/UX
defect (valid-looking but truncated CSV, no indication), not a crash, and the fix precedent
already exists in-repo.

</details>

### Every 422 in HR/TPV/Purchase renders as the literal words "Validation failed" — the global handler overwrites `message` and 414 catch blocks read only `message`

- **Owner:** Harshal · **Module:** hr / tpv / purchase · **Dimension:** Validation, both sides
- **Where:** `backend/bootstrap/app.php:59`
- **What it does:**
  The API exception handler replaces Laravel's per-field summary with the hardcoded string
  `'Validation failed'` and puts the real detail in `errors`. `services/apiError.js` exists
  precisely to unpack that (it even lists 'validation failed' in its GENERIC_TITLES), but
  hrApi.js and the pages in hr/tpv/purchase bypass it and read `e.response?.data?.message`
  directly — 414 call sites. Every validation failure in those three modules therefore shows
  two useless words and the user has no way to tell which field is wrong.
- **Reproduce:**
  HR → Employees → Add Employee. Fill Name, Department, Designation, Joining Date but leave
  the "Probation *" policy dropdown on "Choose a probation policy…" and don't tick exempt.
  Click Add Employee. Toast reads "Validation failed". The backend actually wrote a helpful
  message for this exact case ('Choose a probation policy, or mark this hire as exempt with a
  reason.') and it is discarded, because that text lands in `errors.probation_policy_id[0]`,
  never in `message`.

```
backend/bootstrap/app.php:57-61 —
```
if ($e instanceof ValidationException) {
    return response()->json([
        'status'  => 'error',
        'message' => 'Validation failed',
        'errors'  => $e->errors(),
    ], $e->status);
}
```
frontend hr/pages/Employees.jsx:169 — `} catch (e) { showToast(e.response?.data?.message||'Failed','error') }`
Count by module of `response?.data?.message`: hr 203, tpv 99, purchase 81, shared 22, notifications 4, compliance 3, tasks 1, settings 1 = 414.
```

<details><summary>What the refutation attempt checked</summary>

Could not refute; every refutation angle failed. VERBATIM: backend/bootstrap/app.php:57-61
contains the quoted ValidationException branch exactly, setting message='Validation failed'
and putting detail in errors. It returns before the ApiErrorMapper path below it, so the
mapper never softens a 422. BYPASS CONFIRMED (not supplied elsewhere):
src/services/apiError.js handleErr() exists and explicitly lists 'validation failed' in
GENERIC_TITLES (line 73), rebuilding the title from errors. 34+ services import it, but grep
for apiError|handleErr in hrApi.js, purchaseApi.js, tpvApi.js returns ZERO hits — their calls
are bare .then(r=>r.data) with no .catch(handleErr). NO INTERCEPTOR RESCUE: only 4 response
interceptors exist (lib/api.js:29, hrApi.js:23, purchaseVendorApi.js:30,
clientPortalApi.js:32). The two relevant ones only test isSessionFailure then Promise.reject —
no rewrite of data.message. No global axios.interceptors in main.jsx/App.jsx; repo-wide grep
for "data.message =" finds only the read inside apiError.js. REACHABLE, NOT DEAD CODE:
routes.jsx:54 lazy-imports Employees, :470 renders <Route path="employees">. Backend
routes/hr.php:239 registers POST /employees ->
Api\Hr\EmployeeController::store(StoreEmployeeRequest $request) at EmployeeController.php:26,
so the FormRequest genuinely gates the endpoint. NO EARLIER GUARD: StoreEmployeeRequest.php:47
has probation_policy_id => required_unless:skip_probation,true,1 with the custom message at
line 55 verbatim. Client EMPTY_FORM (lines 16-18) defaults probation_policy_id:'' and
skip_probation:false; the select at line 377 defaults to the exact option "Choose a probation
policy…"; submit is a plain onClick={handleSave} button (line 429), not a native form with
required. The client guard at line 160 checks only name/department/designation/joining_date,
leaving probation unguarded — so the described state is genuinely reachable. TOAST CANNOT
RECOVER: showToast (line 103) stores {msg,type}; JSX renders {toast.msg} as a plain string.
Employees.jsx:169 is verbatim `} catch (e) {
showToast(e.response?.data?.message||'Failed','error') }` — the errors object is never read.
MINOR INACCURACY (not disqualifying): my counts are hr 203 (exact match), tpv 99 (exact
match), purchase 95 (claim said 81), repo-wide 511 (claim said 414). The auditor's purchase
scope was slightly narrower, but the magnitude and the defect hold.

</details>

### Sales create/save handlers have no try/catch — a 422 or 500 produces an unhandled promise rejection and absolutely no feedback

- **Owner:** Zafar · **Module:** sales · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/sales/pages/Invoices.jsx:119`
- **What it does:**
  `handleCreate`, `handlePay` (Invoices), `handleCreate` (Estimates), `handleCreate`
  (CreditNotes) and `handleSave` (Items) `await` the API call with no try/catch, wired
  straight to a button's onClick. The service layer throws through `handleErr`, so the
  rejection escapes into React's event handler and is swallowed. The success toast never fires
  either, so the drawer just sits there.
- **Reproduce:**
  Sales → Invoices → New Invoice. Pick a customer, clear the Due Date field, click "Create
  Invoice". Nothing happens at all: no toast, no error, the drawer stays open, and clicking
  again repeats the void. (The server rejected it with `due_date is required`.) Same with
  Record Payment for an amount above the invoice balance.

```
frontend sales/pages/Invoices.jsx:119-123 —
```
const handleCreate = async () => {
  if(!form.client_id) return showToast('Customer required','error')
  await salesApi.invoices.create({...form, client_id: Number(form.client_id), project_id: form.project_id ? Number(form.project_id) : null})
  showToast('Invoice created!'); setShowDrawer(false); setForm(EMPTY); load()
}
```
Invoices.jsx:496 — `<button onClick={handleCreate} ...>Create Invoice</button>`
Same shape at Invoices.jsx:124 (handlePay), Estimates.jsx:194, CreditNotes.jsx:69, Items.jsx:70.
```

<details><summary>What the refutation attempt checked</summary>

Verified against the source, and tried and failed to refute it on every angle. (1) Quoted code
is verbatim at frontend/src/modules/sales/pages/Invoices.jsx:119-123 (handleCreate) and
124-128 (handlePay); same no-try/catch shape at Estimates.jsx:194, CreditNotes.jsx:69,
Items.jsx:70. Notably handleMarkSent at Invoices.jsx:135 in the SAME file does use try/catch,
so this is an inconsistent omission, not house style. (2) The service layer really throws:
services/salesApi.js:27 maps invoices -> services/invoiceApi.js, whose create/recordPayment
are .catch(handleErr); services/apiError.js handleErr ends in `throw error` and documents
"Always throws". (3) No generic handler catches it: lib/api.js response interceptor only
clears auth on isSessionFailure and does `return Promise.reject(error)` with no toast;
components/ErrorBoundary.jsx is a componentDidCatch class boundary, which cannot catch async
rejections from event handlers; grep found no unhandledrejection/onunhandledrejection listener
anywhere in frontend/src or index.html; these are raw awaits, not react-query mutations with
onError. (4) Reachable: all four pages are routed (app/routes.jsx:504 invoices, 501 estimates,
507 credit-notes, 508 items). Items is reachable with no precondition at all — openCreate
(Items.jsx:63) on a plain "New Item" button (line 119), handleSave wired to the drawer button
(line 308). (5) The 422 is guaranteed, not an edge case:
backend/app/Http/Requests/Sales/StoreInvoiceRequest.php has 'due_date' => 'required|date',
while Invoices.jsx:27 EMPTY sets due_date:'' and the date input at line 354 has no required
attribute, no default, and is not in a submitting form. A second guaranteed 422 source is
'sale_agent' => 'nullable|exists:users,id' vs the STAFF name strings at Invoices.jsx:21. ONE
CORRECTION to the repro (does not affect the defect): the in-page "New Invoice" button at
Invoices.jsx:199 navigates to /app/sales/invoices/new (DocumentStart) and does NOT open this
drawer; the drawer opens via ?new=1, set by the customer profile's buttons at
modules/customer/pages/CustomerDetail.jsx:360 (and 363/364 for Estimates/Credit Notes). So the
entry path is the customer profile, not the invoice list, but the drawer, the handlers, and
the silent-failure outcome are all genuine.

</details>

### Invoice, estimate and credit-note line items have no server-side rules at all — negative quantities and rates persist and produce negative-total documents

- **Owner:** Zafar · **Module:** sales · **Dimension:** Validation, both sides
- **Where:** `backend/app/Http/Requests/Sales/StoreInvoiceRequest.php:36`
- **What it does:**
  `StoreInvoiceRequest`, `UpdateInvoiceRequest`, `StoreEstimateRequest`,
  `UpdateEstimateRequest` and `StoreCreditNoteRequest` all declare `line_items =>
  nullable|array` with zero `line_items.*` rules, while the sibling `StoreProposalRequest` in
  the same folder correctly declares `qty`/`rate` as `numeric|min:0`.
  `InvoiceService::syncLineItems` then writes `$item['qty']` and `$item['rate']` raw. The only
  guard anywhere is an HTML `min` attribute on an input that is not inside a `<form>` and is
  never submitted natively, so it is decorative.
- **Reproduce:**
  Sales → Invoices → New Invoice, pick a customer and a due date, then in the line-items grid
  type `-10` into Qty and `500` into Rate. Save. The invoice is created with a total of
  −5,000, it counts toward outstanding receivables, and a payment can be recorded against it.
  Do the same on a Credit Note and the customer's available credit goes negative.

```
backend StoreInvoiceRequest.php:36 — `'line_items'    => 'nullable|array',`  (no `line_items.*.qty` / `.rate` rules; compare StoreProposalRequest.php:55-56 — `'line_items.*.qty' => 'required_with:line_items|numeric|min:0',` / `'line_items.*.rate' => 'required_with:line_items|numeric|min:0',`)
backend InvoiceService.php:284-286 — `'qty' => $item['qty'],` … `'rate' => $item['rate'],`
frontend sales/components/LineItemsTable.jsx:225 — `type="number" min="0.01" step="0.01"` (React `onChange` writes the raw string; the button at Invoices.jsx:496 is `onClick`, not a form submit, so `min` is never checked)
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute on seven fronts; all failed. (1) Quoted code exists verbatim:
StoreInvoiceRequest.php:36 is `'line_items' => 'nullable|array',` with zero `line_items.*`
rules. Same bare rule confirmed at UpdateInvoiceRequest:25, StoreEstimateRequest:38,
UpdateEstimateRequest:23, StoreCreditNoteRequest:25. The sibling contrast is real:
StoreProposalRequest:55-56 has `'line_items.*.qty' =>
'required_with:line_items|numeric|min:0'` and the same for `.rate`. (2) Rules supplied
elsewhere? No. All five extend `Illuminate\Foundation\Http\FormRequest` directly (no
intermediate base class), and I grepped all five for `prepareForValidation`, `withValidator`
and `after(` — none exist. No middleware or service provider touches `line_items`/`qty`
(grepped app/Http/Middleware, bootstrap/app.php, app/Providers). app/Observers contains only
Accounts, no Sales observer. (3) `$request->validated()` does not filter it out — with only a
parent `line_items` array rule, Laravel returns the whole nested array untouched, which is why
the service can read `$item['item_name']` at all. (4) Service quote exact:
InvoiceService.php:284 `'qty' => $item['qty'],` and :286 `'rate' => $item['rate'],`. (5) No
clamping downstream. SalesLineItem casts qty/rate as `decimal:2`, which preserves sign;
`computeTotal` (Model:90-96) is `qty*rate - discount + tax%` with no abs/max. Tellingly,
`discountAmount` (Model:84) DOES clamp — `round(min(max($amount,0), max($base,0)), 2)` — so
with qty=-10 the discount floors to 0 and the -5000 passes straight through.
`CalculatesDocumentTotals::computeDocumentTotals` (lines 105-121) sums `$base = qty*rate` with
no clamp, so subtotal and total both go negative. (6) Reachable, not dead code:
routes/sales.php:157 registers `POST /invoices` → InvoiceController::store, which calls the
service with `$request->validated()`. (7) Frontend guard is genuinely decorative, as claimed.
LineItemsTable.jsx:225 is exactly `type="number" min="0.01" step="0.01"`; its onChange calls
`update(idx,'qty',e.target.value)` and `update` (lines 91-97) spreads the raw string with no
coercion. There is no `<form>` tag anywhere in Invoices.jsx (grepped `<form`/`</form` — zero
hits) and the save button at :496 is `onClick={handleCreate}`, so native constraint validation
never fires. `handleCreate` (119-123) validates only `!form.client_id`. (8) Credit-note half
of the repro also holds: CreditNoteService:196-229 writes raw qty/rate then sets
`$cn->remaining = round($total,2)`, so a negative total yields negative remaining credit. One
minor imprecision, not enough to refute: the repro says the invoice "counts toward outstanding
receivables", but `recalcBalance` (SalesInvoice:121) sets status 'Paid' when `balance <= 0`,
so a negative-total invoice would likely read as Paid rather than outstanding. That is a wrong
peripheral consequence; the core defect — unvalidated negative qty/rate persisting into
negative-total documents — is confirmed. Changed nothing and ran only read commands.

</details>

### Custom fields marked "Required" show an asterisk but are enforced on neither side — they save blank forever

- **Owner:** mixed · **Module:** customer / inventory · **Dimension:** Validation, both sides
- **Where:** `backend/app/Services/Customer/CustomFieldService.php:99`
- **What it does:**
  `CustomFieldForm` lets an admin tick "Required" on a custom field,
  `CustomFieldService::valuesFor` returns the `required` flag, and four form screens render a
  ` *` next to the label. But `saveValues` never checks it, the parent FormRequests only say
  `'custom_fields' => 'nullable|array'`, and no client-side save handler inspects
  `def.required`. The asterisk is pure decoration.
- **Reproduce:**
  Settings → Custom Fields → add a field for Customers named "PO Reference", tick Required,
  save. Now Customers → New Customer, fill only the company name and billing address, leave
  "PO Reference *" blank, Save. The customer is created with the field empty and no warning —
  then every invoice raised for that customer is missing the PO reference that finance was
  told was mandatory.

```
backend CustomFieldService.php:99-121 —
```
public function saveValues(int $tenantId, string $fieldTo, int $relId, array $values): void
{
    if (empty($values)) { return; }
    $validIds = CustomField::forTenant($tenantId)->for($fieldTo)->pluck('id')->flip();
    foreach ($values as $fieldId => $value) { ... }
}
```
(no read of `$field->required` anywhere in the file — only `'required' => $f->required,` at line 76, which merely echoes it to the UI)
frontend customer/pages/Customers.jsx:673 — `<label className="label">{def.name}{def.required ? ' *' : ''}</label>`
frontend inventory/components/ProductFormModal.jsx:468 — `<Field key={def.id} label={def.label + (def.required ? ' *' : '')}>`
frontend customer/components/ContactFormDrawer.jsx:282 — same pattern
backend StoreProductRequest.php — `'custom_fields'   => 'nullable|array',`
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED — I opened every file and tried each refutation avenue; all failed. VERBATIM CHECK:
CustomFieldService.php:99-121 matches the quote exactly. `saveValues` reads only `$validIds`
(tenant/field_to ownership) and writes; `required` appears in the file exactly once, at line
76, echoing `$f->required` to the UI. The `required` column is real and persists (migration
2026_07_11_000002:26 `boolean('required')->default(false)`; model casts it boolean,
`$fillable` includes it; CustomFieldController.php:64 validates `'required' =>
['nullable','boolean']`), so the admin's tick genuinely round-trips to the UI — it just gates
nothing. BACKEND, all 5 call sites of `saveValues` (ClientService.php:78 and :123,
ClientContactController.php:52 and :63, LeadEngagementController.php:105): none validates
required. StoreClientRequest:64 / UpdateClientRequest:92 / StoreProductRequest:47 /
UpdateProductRequest:44 are all `'custom_fields' => 'nullable|array'`, and `grep -c
"withValidator|public function after|Validator::"` returns 0 in all four FormRequests. No
custom Rule (app/Rules holds only Gstin/Ifsc/Pan/PhoneNumber/Pincode), no Observer
(app/Observers has only Accounts), no middleware or provider references CustomField.
ClientController::store/update just pass `$request->validated()` straight through. FRONTEND,
the four named screens: the shared `CustomFieldInput.jsx` never emits a native HTML `required`
attribute on any of its 11 branches — so no browser-level guard either. Customers.jsx `save`
(line 181) is the decisive one: `STEPS` marks `{ key: 'Custom Fields', optional: true }` (line
26) and the loop does `if (s.optional) continue` (line 184), and `stepError` (153-160) has no
'Custom Fields' branch at all — custom fields are validation-skipped by construction.
CustomerDetail.jsx `save` (476) checks only `form.company`. ContactFormDrawer.jsx `save` (101)
checks only `first_name` and password length. ProductFormModal.jsx `submit` (204) is worse: it
deletes every `''` key before posting, so a blank required field is stripped entirely; on the
backend `ConfigService::sanitizeValues` (205-233) likewise `continue`s past empty values and
never reads `$def->required`. REACHABILITY: not dead code — routes.jsx:130 lazy-imports
Customers, routes.jsx:531 mounts `path="customers"`. The repro path is live. ONE CORRECTION to
the claim's wording, which strengthens rather than refutes it: "no client-side save handler
inspects def.required" is over-broad. `LeadCustomFieldsTab.jsx:40` — same engine, same
`CustomFieldInput`, same `f.id` keying — does check it: `const missing = defs.filter(f =>
f.required && !String(values[f.id] ?? '').trim())`. And the separate web-to-lead system
enforces required on both sides (PublicLeadForm.jsx native `required=` +
PublicWebToLeadController.php:50-58 returning 422). So the intended behavior demonstrably
exists elsewhere; the four named screens are the inconsistent ones, and even the leads tab's
check is client-only and bypassable via the API since `saveValues` still never enforces it.
The finding's core — asterisk renders, nothing enforces, record saves blank — holds for all
four screens named.

</details>

### Purchase portal onboarding validates nothing client-side, and its error banner shows only "Validation failed" for a 40-field form

- **Owner:** Harshal · **Module:** purchase · **Dimension:** Validation, both sides
- **Where:** `frontend/src/pages/purchase-portal/PurchasePortalOnboarding.jsx:230`
- **What it does:**
  The vendor-facing onboarding profile step renders GST, PAN, account number and IFSC as plain
  text inputs with no regex, no length check and no paired-field check, while
  `SavePurchaseOnboardingProfileRequest` enforces a GSTIN rule, a PAN regex,
  `digits_between:9,18` and a custom `Ifsc` rule with mutual `required_with`. The error banner
  reads `data.message` first, which the global handler always sets to 'Validation failed', so
  the `errors` fallback after the `||` is dead code and can never run. The TPV equivalent
  (TpvOnboardingWizard.jsx:63-67) mirrors all of these rules correctly, so this is a gap, not
  a policy.
- **Reproduce:**
  Log in to the purchase vendor portal → Onboarding → Company Profile. Type an IFSC of
  `HDFC1234` and an account number of `12345`, fill the rest, click "Save & Continue". A red
  banner appears saying only "Validation failed". Nothing indicates the IFSC or the account
  number is the problem, none of the ~40 inputs is highlighted, and the vendor's other typed
  data has to be re-verified by hand.

```
frontend PurchasePortalOnboarding.jsx:230 — `setErr(e?.response?.data?.message || Object.values(e?.response?.data?.errors || {})[0]?.[0] || 'Could not save profile.')`  (message is always the string 'Validation failed', so the errors branch is unreachable)
frontend PurchasePortalOnboarding.jsx:263-264 — `{F('Account Number', 'bank_account_number')}` / `{F('IFSC', 'bank_ifsc')}`  (helper `F` at :235 renders a bare `<TextInput>` with no validation props)
backend SavePurchaseOnboardingProfileRequest.php:48-49,55 —
```
'profile.bank_account_number' => ['nullable', 'required_with:profile.bank_ifsc', 'digits_between:9,18'],
'profile.bank_ifsc'           => ['nullable', 'required_with:profile.bank_account_number', new Ifsc],
'profile.pan_number' => ['nullable', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'],
```
```

<details><summary>What the refutation attempt checked</summary>

Verified end-to-end; could not refute. (1) Quoted code exists verbatim:
PurchasePortalOnboarding.jsx:230 is the exact setErr(...message || ...errors...) line; helper
F at :234-236 renders a bare Field/TextInput; :263-264 pass no props for
bank_account_number/bank_ifsc. (2) The load-bearing claim checks out:
backend/bootstrap/app.php:56-62 hardcodes 'message' => 'Validation failed' for every
ValidationException on api/*, so data.message is always truthy and the errors branch after the
|| is genuinely unreachable — on all paths, since the generic ApiErrorMapper branch (:92-97)
also sets message whenever it sets errors. (3) No interceptor rewrites it:
src/lib/purchaseVendorApi.js has a single response interceptor that only handles session
failure then re-rejects; its baseURL ends in /api so the handler's is('api/*') guard matches.
(4) No validation supplied upstream: kit3d.jsx:110-116 shows Field is a bare label wrapper
with no error prop and TextInput is a bare <input> spread; no react-hook-form/yup/zod anywhere
under src/pages/purchase-portal/, and the component's full 368-line import list has no
validation hook. (5) Reachable, not dead: routes.jsx:894 mounts it at /purchase-
portal/onboarding and the adjacent comment notes these steps exist in no other screen;
editable is true for In_Progress so the save buttons render. (6) Backend rules match exactly
(SavePurchaseOnboardingProfileRequest.php:48-49,55) and the repro values fail — Ifsc.php:16 is
/^[A-Z]{4}0[A-Z0-9]{6}$/ so HDFC1234 fails, and 12345 fails digits_between:9,18. (7)
TpvOnboardingWizard.jsx:51-69 contains the full RE/validateProfile mirror including both
required_with pairs, confirming a gap rather than policy. Two overstatements that do not
negate the defect: the form has 16 fields, not ~40; and GST/PAN/pincode do carry maxLength
(15/10/6) and email uses type=email — those cap length but enforce no format, so malformed
values still produce the same bare 'Validation failed' banner with no field highlighted.

</details>

### 32 TPV/Purchase register pages swallow the load error into an empty list — "No NCRs." means either zero records or a dead API

- **Owner:** Harshal · **Module:** tpv · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/tpv/pages/TpvNcr.jsx:30`
- **What it does:**
  Every TPV and Purchase register uses the same three-state render (`rows === null` →
  Loading…, `rows.length === 0` → "No X.", else table) but its loader catches the failure with
  `.catch(() => setRows([]))`. A failed fetch is therefore rendered as a confirmed-empty
  register, with no toast, no banner, and no retry affordance.
- **Reproduce:**
  Open /app/tpv/ncrs with the backend returning 500. The page briefly shows "Loading…" then
  "No NCRs." — indistinguishable from a genuinely empty register. A safety officer concludes
  there are no open non-conformances when in fact the query never ran. Reproduces on 32 files:
  TpvViolations, TpvCapaRegister, TpvRenewals, TpvContracts, TpvInspections, TpvPermits,
  TpvDocumentVault, TpvOffboarding, TpvComplianceRegister, TpvPerformanceIndex,
  TpvSiteRegisters, TpvWorkPackages, TpvApprovalRegister, TpvCompetency, TpvSafetyEngagement,
  TpvWorkAuthorization, TpvIncidents and the mirrored Purchase* registers.

```
TpvNcr.jsx:27-32
  const load = useCallback(() => {
    tpvApi.ncrs.list(statusF ? { status: statusF } : {})
      .then(d => { setRows(d?.data ?? []); setMeta({ severities: d?.severities ?? [], statuses: d?.statuses ?? [] }) })
      .catch(() => setRows([]))
  }, [statusF])

TpvNcr.jsx:70-71
  {rows === null ? <tr><td colSpan={7} …>Loading…</td></tr>
    : rows.length === 0 ? <tr><td colSpan={7} …>No NCRs.</td></tr>

`grep -rln "catch(() => setRows(\[\]))" modules/` → 33 files, 32 of them under tpv/ and purchase/.
```

<details><summary>What the refutation attempt checked</summary>

Verified, could not refute. The quoted code exists verbatim at
frontend/src/modules/tpv/pages/TpvNcr.jsx:27-31 (`.catch(() => setRows([]))` on line 30) with
the three-state render at lines 69-70 ("Loading…" / "No NCRs." / table), so a failed fetch is
indistinguishable from an empty register. Refutation attempts, all failed: (1) No global toast
— lib/api.js:29-40 re-rejects every error and only calls clearAuth() when
lib/sessionFailure.js:24 matches an auth-shaped 401; a 500 passes straight to the component
catch. (2) No error boundary rescue — components/ErrorBoundary.jsx is a render-phase boundary
(getDerivedStateFromError); the rejection is already consumed by .catch() so React never sees
it. No `unhandledrejection` listener exists anywhere in src/. (3) No shared wrapper — the
route wrapper `<S>` is only `<Suspense fallback={<PageLoader/>}>` (app/routes.jsx:392);
TPVLayout.jsx has zero error handling. (4) No service-layer fallback — services/tpvApi.js:82
is a bare `api.get('/tpv/ncrs', {params}).then(r => r.data)`. (5) Not dead code — routed at
app/routes.jsx:655 (`path="ncr"` under `path="tpv"` at line 633), lazy-imported line 291,
backed by a real endpoint at backend/routes/tpv.php:267 → TpvNcrController@index. (6) No error
state elsewhere in the file — the only setErr (line 133) is inside NcrModal's save handler,
not the loader. Same in siblings: setErr in TpvPermits/TpvIncidents/TpvViolations is confined
to modal save paths (lines 84/102/139), never the list loader. Scope confirmed: `grep -rln
"catch(() => setRows(\[\]))" modules/` returns exactly 33 files, 32 under tpv/ and purchase/ —
matching the claim. Two immaterial inaccuracies: the real URL is /app/tpv/ncr (not
/app/tpv/ncrs), and TpvContracts is not in that grep — it uses `.catch(() =>
setContracts([]))` at lines 27-28, the same swallow under a different setter name.
TpvPermits/TpvIncidents use a `loading` boolean rather than `rows === null`, but the failure
renders identically as a confirmed-empty register. Neither weakens the defect.

</details>

### Compliance Workspace swallows the error and reports "No checklists yet" with all KPIs at zero

- **Owner:** mixed · **Module:** compliance · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/compliance/pages/ComplianceWorkspace.jsx:71`
- **What it does:**
  The ledger loads checklists and stats with `Promise.all(...).catch(() => setLoad(false))`.
  On failure `rows` stays `[]` and `stats` stays `{}`, so the four KPI tiles all render 0
  (`stats.open ?? 0`) and the table renders the never-created empty state that invites the
  user to issue their first checklist.
- **Reproduce:**
  Open /app/compliance while /api/compliance/checklists 500s. The header reads Open 0,
  Awaiting signature 0, High risk 0, Overdue 0, and the body says "No checklists yet — Issue
  one against a vendor or worker…". A compliance officer reads that as a clean board when in
  fact nothing loaded. Refresh gives the same silent result.

```
ComplianceWorkspace.jsx:67-72
  const load = useCallback(() => {
    setLoad(true)
    Promise.all([complianceApi.checklists.list(filters), complianceApi.checklists.stats()])
      .then(([r, s]) => { setRows(r?.data ?? r ?? []); setStats(s?.data ?? s ?? {}); setLoad(false) })
      .catch(() => setLoad(false))
  }, [filters])

ComplianceWorkspace.jsx:78 — <Kpi label="Open" value={stats.open ?? 0} sub={`${stats.total ?? 0} all time`} …/>
ComplianceWorkspace.jsx:102-104
  ) : rows.length === 0 ? (
    <Empty icon={ClipboardCheck} title="No checklists yet"
      body="Issue one against a vendor or worker — they'll get a link they can fill in on their phone, no login needed." />
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED — I opened the file and tried each refutation route; all of them failed. 1. Code is
verbatim as quoted. /home/zafar-farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/complianc
e/pages/ComplianceWorkspace.jsx:67-72 has `Promise.all([...]).then(...).catch(() =>
setLoad(false))` exactly as evidenced. Line 78 is `value={stats.open ?? 0}`; lines 103-105 are
the `rows.length === 0` -> `<Empty title="No checklists yet" …>` branch. State inits are
`useState([])` for rows (61) and `useState({})` for stats (62), so on rejection both stay
untouched while loading flips false — the four KPIs render 0 and the empty state renders,
precisely as claimed. 2. Not handled by an interceptor. The shared instance at
frontend/src/lib/api.js has only two interceptors: a request one that attaches the bearer
token, and a response one whose error branch does nothing but `if (isSessionFailure(error,
!!getToken())) { clearAuth(); redirect to /auth/login }` then `return Promise.reject(error)`.
There is no global toast/snackbar/notification on failure, so a 500 is handed straight to the
component's swallowing `.catch`. 3. Not handled by an error boundary. `ErrorBoundary` exists
(App.jsx:4,10 -> components/ErrorBoundary.jsx with componentDidCatch) but it is a React
render-phase boundary and cannot see an already-handled promise rejection. Because `.catch()`
consumes the rejection, no `unhandledrejection` fires either — I grepped the whole of
frontend/src and there is no `unhandledrejection` listener. 4. No local error UI exists.
`ChecklistsTab` declares no error state at all — the only `err` state and the only `<ErrBox>`
usage in the file are inside `IssueModal` (lines 169, 189, 238). Nothing in the ledger path
can display a load failure. 5. The file is live, not dead code. routes.jsx:334 lazy-imports it
and routes.jsx:696 mounts `<Route path="compliance" element={<S><ComplianceWorkspace /></S>}
/>` under the /app/tpv parent. `S` is just `const S = ({children}) => <Suspense
fallback={<PageLoader/>}>{children}</Suspense>` (routes.jsx:392) — no guard, no error
handling. It is linked from Sidebar.jsx:51 and :58, TPVLayout.jsx:47, and
modules/registry.js:181. 6. No earlier guard prevents the state. The backend endpoints really
exist (backend/routes/compliance.php:34 `/checklists/stats`, :35 `/checklists`), so this is a
reachable screen against real endpoints; nothing short-circuits before the fetch. Two caveats
that do not refute it, but the developer should know: - The REPRO's URL is wrong: the route is
/app/tpv/compliance, not /app/compliance. Path typo in the write-up only. - This is a house-
wide pattern, not a one-off: `catch(() => set…` appears 145 times across frontend/src/modules.
The finding is genuine for this screen (a compliance ledger silently reading "clean" is the
worst instance of it), but fixing it in isolation addresses one symptom of a systemic
convention.

</details>

### Six HR report dashboards spin forever when their fetch fails — the error path never clears the loader

- **Owner:** Harshal · **Module:** hr · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/hr/pages/ExitReports.jsx:98`
- **What it does:**
  Each HR reporting dashboard keeps its data in a single `null` state and gates the whole view
  on `if (!d) return <HrLoading …/>`. The catch handler only fires a toast; it never sets an
  error flag or a sentinel value, so `d` stays null and the spinner runs indefinitely. The
  toast disappears after a few seconds and the user is left with a permanent spinner.
- **Reproduce:**
  Open /app/hr/exit-reports (Dashboard tab) with the reports endpoint failing. A toast flashes
  "Failed to load dashboard", then the tab shows a spinning loader with "Loading exit
  dashboard…" forever. Switching tabs and back repeats it. Same on
  hr/pages/LeaveReports.jsx:95, PayrollReports (via useReport), LearningReports.jsx:174,
  ProbationReports.jsx:218, Performance.jsx:78 and EmployeeSurveys.jsx:594.

```
ExitReports.jsx:96-98
  function Dashboard({ showToast }) {
    const [d, setD] = useState(null)
    useEffect(() => { hrApi.exit.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard', 'error')) }, [showToast])
    if (!d) return <HrLoading label="Loading exit dashboard…" />

EmployeeSurveys.jsx:590-594
    hrApi.surveys.dashboard().then(setData).catch(e => showToast(e?.response?.data?.message || 'Could not load', 'error'))
  }, [showToast])

  if (!data) return <HrLoading label="Loading dashboard…" />

Performance.jsx:77-78
  useEffect(() => { hrApi.performance.dashboard().then(setD).catch(() => showToast('Failed to load dashboard', 'error')) }, [showToast])
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute and failed — the code is exactly as quoted and nothing upstream rescues it.
WHAT I VERIFIED, line by line: 1. ExitReports.jsx:96-98 matches the evidence verbatim: `const
[d, setD] = useState(null)` / `useEffect(() => {
hrApi.exit.reports.dashboard().then(setD).catch(() => showToast('Failed to load dashboard',
'error')) }, [showToast])` / `if (!d) return <HrLoading label="Loading exit dashboard…" />`.
No error state, no sentinel, no retry affordance (grepped ExitReports.jsx for
retry/reload/refresh — zero hits). 2. The other five files match too — LeaveReports.jsx:95,
Performance.jsx:77, LearningReports.jsx:173, ProbationReports.jsx:217,
EmployeeSurveys.jsx:590/594 — all the identical `useState(null)` + bare-toast catch + `if (!d)
return <HrLoading/>` shape. 3. PayrollReports useReport (line 85-96) does have `.finally(() =>
setLoading(false))`, so I checked the call sites: line 117 gates on `if (loading || !data)` —
after a failed fetch loading is false but data is still null, so that tab still spins forever.
(Line 167 gates on `loading` alone and does escape the spinner, so the claim is slightly over-
broad for that one sub-report, but the summary tab genuinely hangs. ExitReports has the same
local useReport at line 70.) REFUTATION ATTEMPTS THAT FAILED: - Axios interceptor
(services/hrApi.js:22-31): it does NOT swallow errors — it ends with `return
Promise.reject(err)`. Only `isSessionFailure` triggers clearAuth + hard redirect to
/auth/login; every other failure (500, 422, network drop, non-session 403) propagates to the
component `.catch`. So the error path is reached exactly as described. - Global ErrorBoundary
(components/ErrorBoundary.jsx, mounted in App.jsx:10): it's a classic
getDerivedStateFromError/componentDidCatch boundary — it only catches render-phase throws, not
a caught promise rejection. Nothing throws here, so it never engages. - HrLoading
(components/ui/HrState.jsx) is purely presentational — a spinning Loader2 plus label, no
timeout, no error slot. It will spin indefinitely. - Parent supplies nothing:
ExitManagement.jsx:60 defines `showToast` as an inline function that sets toast state and
clears it after 3000ms. It passes only showToast down (line 95 → ExitReports → line 60
`<Dashboard showToast={showToast}/>`). No error prop, no data prefetch, no wrapper. -
Reachability: routed and live — app/routes.jsx:485 `exit-management`, :481 surveys, :483
performance, :484 leave-management, :486 learning-development, :487 probation-management. All
lazy-loaded behind a plain Suspense wrapper (`const S` at routes.jsx:392) that only covers
chunk loading. - The API methods exist (hrApi.js:621 exit dashboard, :502 leave, :722
learning, :801 probation), so this isn't dead/unwired code. ONE CORRECTION TO THE REPORT: the
repro URL is wrong. There is no /app/hr/exit-reports route; ExitReports is the "reports" tab
inside /app/hr/exit-management (ExitManagement.jsx:95). The symptom is otherwise reproducible
as written. ADDITIONAL DETAIL THE AUDITOR MISSED (makes it worse, not better): because
`showToast` is an unstable inline closure in the parent and the effect depends on
`[showToast]`, each toast set/clear re-renders the parent, produces a new showToast identity,
and re-fires the effect — so a persistently failing endpoint yields a refetch/toast storm
behind the permanent spinner, not just one silent hang.

</details>

### Five Settings pages render a permanent skeleton when their settings group fails to load

- **Owner:** mixed · **Module:** settings · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/settings/pages/CurrencySettings.jsx:36`
- **What it does:**
  The settings-group pages initialise `values` to `null`, fetch once, and gate the entire form
  on `if (!values) return <skeleton/>`. The catch only toasts; `values` is never set, so the
  page is stuck showing an animated grey block with no error text and no retry button.
- **Reproduce:**
  Open /app/settings/currency while /api/settings/group/currency fails (or the user lacks the
  permission and gets a 403). A toast flashes, then a 10rem grey skeleton card sits there
  permanently. Reload repeats. Identical on /app/settings/localization
  (LocalizationSettings.jsx:40), /app/settings/security (SecuritySettings.jsx:47),
  /app/settings/uploads (UploadSettings.jsx:41) and /app/settings/notifications
  (NotificationPreferences.jsx:41).

```
CurrencySettings.jsx:17
  const [values, setValues] = useState(null)

CurrencySettings.jsx:22-24
  useEffect(() => {
    settingsApi.group.get('currency').then(d => setValues(d?.values || {})).catch(e => toast.error(e.message))
  }, [])

CurrencySettings.jsx:36
  if (!values) return <div className="card-3d"><div className="skeleton h-40 rounded-xl" style={{ background: 'var(--border)' }} /></div>

Same three lines in LocalizationSettings.jsx:21/27/40, SecuritySettings.jsx:29/35/47, UploadSettings.jsx:23/29/41, NotificationPreferences.jsx:19/29/41.
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute on six fronts; all failed. 1. Code exists verbatim at the exact quoted lines.
/home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/settings/pages/CurrencySettings.jsx:17
`const [values, setValues] = useState(null)`, :22-24 the useEffect with `.catch(e =>
toast.error(e.message))`, :36 `if (!values) return <div className="card-3d"><div
className="skeleton h-40 rounded-xl" .../></div>`. Grepped the other four:
LocalizationSettings.jsx 21/27/40, SecuritySettings.jsx 29/35/47, UploadSettings.jsx 23/29/41,
NotificationPreferences.jsx 19/29/41 — every line number in the claim matches. No
`loading`/`error` state, no retry, no second fetch anywhere in these files. 2. Not dead code.
All five are routed in app/routes.jsx:787,788,791,792,793 as children of `<Route
path="settings" element={<S><SettingsLayout/></S>}>`, itself under `/app` (routes.jsx:444). 3.
Global ErrorBoundary does not help. components/ErrorBoundary.jsx is wired in App.jsx:10, but
it is a render-phase class boundary — the fetch rejection is already swallowed by the page's
own `.catch`, so nothing ever propagates to it. 4. Axios interceptor does not help. lib/api.js
only handles session failures, and lib/sessionFailure.js `isSessionFailure` returns false for
anything that is not a 401 — a 403 is explicitly passed through to the caller (there's even a
comment saying "A 403 never reaches here"). services/apiError.js `handleErr` builds a rich
Error and always throws; the page turns it into a transient toast and nothing more. 5. No
earlier guard prevents the 403 state — it actually makes it more likely. Backend
routes/settings.php:22 puts `/settings/group/{group}` behind `['auth:sanctum','role:admin']`,
and EnsureUserHasRole.php:68-73 returns 403. The frontend route is only `<ProtectedRoute>`
with no `roles` prop, SettingsLayout.jsx renders all 14 sections unconditionally (`ready:
true`), and Sidebar.jsx:33 links `/app/settings` for everyone. So any authenticated non-admin
can click through and land on the permanent skeleton. 6. `d?.values || {}` would rescue an
empty success body (`{}` is truthy), so the null state is reachable only via the error path —
which is exactly the path with no recovery. Only inaccuracy found, and it is cosmetic: the
repro says /app/settings/notifications; the actual route is /app/settings/notification-
preferences (routes.jsx:793). The defect itself is unaffected.

</details>

### Email Templates settings shows a permanent skeleton both on load failure and when zero templates exist

- **Owner:** mixed · **Module:** settings · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/settings/pages/EmailTemplatesSettings.jsx:117`
- **What it does:**
  The page gates on `if (!data || !form)`. Two separate paths reach that guard permanently:
  (a) the fetch fails and only toasts, leaving `data` null; (b) the fetch succeeds but returns
  zero templates, so `d.templates[0]` is undefined, `setForm` is never called and `form` stays
  null. Both render an indefinite grey skeleton with no message.
- **Reproduce:**
  Path A: open /app/settings/email-templates with the endpoint down — a toast flashes and a
  grey skeleton stays forever. Path B: on a fresh tenant whose email_templates table has not
  been seeded, the request succeeds with an empty array and the page still shows the skeleton
  forever, so the admin cannot tell whether it is loading, broken, or simply empty.

```
EmailTemplatesSettings.jsx:44-55
  const load = useCallback((keepSelection) => {
    settingsApi.emailTemplates.list()
      .then(d => {
        setData(d)
        const first = keepSelection
          ? d.templates.find(t => t.key === keepSelection)
          : d.templates[0]
        if (first) { setSelected(first.key); setServer(first); setForm(pick(first)) }
      })
      .catch(e => toast.error(e.message))
  }, [toast])

EmailTemplatesSettings.jsx:117
```

<details><summary>What the refutation attempt checked</summary>

Opened frontend/src/modules/settings/pages/EmailTemplatesSettings.jsx (285 lines) — the quoted
code is exact: load() at 45-55 and the guard at 117 match character-for-character. I tried
hard to refute and could only refute HALF the claim. PATH A (load failure) — CONFIRMED, this
is a real defect: - settingsApi.emailTemplates.list() (services/settingsApi.js:23) ends in
.catch(handleErr), and handleErr (services/apiError.js) ALWAYS rethrows ("Always throws —
services use it as .catch(handleErr)"), so the rejection reaches the component's .catch(e =>
toast.error(e.message)). - That catch never sets any error/loaded flag. The component's full
state list is data/selected/server/form/preview/errors/saving/search/category/tab — `errors`
is only preview field-validation, there is no error state and no retry control anywhere in the
file. - Nothing upstream rescues it. The route (app/routes.jsx:790) is
<S><EmailTemplatesSettings /></S>, and S (routes.jsx:392) is ONLY `<Suspense
fallback={<PageLoader/>}>` — not an error boundary. components/ErrorBoundary.jsx exists but is
irrelevant: the promise rejection is caught, and the component renders successfully (it
returns the skeleton), so no boundary can fire. - The axios interceptor (lib/api.js) only
clears auth and redirects when isSessionFailure() is true; a 500, a network-down backend, or a
403 for a non-admin all fall through to the caller. So data stays null and line 117 renders
the grey skeleton indefinitely after one transient toast. Reproducible and user-reachable.
PATH B (zero templates) — REFUTED, the auditor is wrong here and a developer should NOT chase
it: - The premise "a fresh tenant whose email_templates table has not been seeded returns an
empty array" is false. Templates are NOT DB-seeded. EmailTemplateService::all()
(backend/app/Services/Email/EmailTemplateService.php:34-64) iterates
EmailTemplateRegistry::all() and overlays DB rows; the email_templates table holds only per-
tenant OVERRIDES. EmailTemplateRegistry::templates()
(app/Support/Email/EmailTemplateRegistry.php) is a hardcoded PHP literal of ~35 shipped
templates (auth.welcome, system.test, …) with no module/tenant gating — it returns $t
unconditionally. So an unseeded tenant gets 35 templates, and d.templates[0] is always
defined. - The only way all() returns [] is the server-side category/search filters, and the
frontend never sends them: list() is called as load() with no args, so params is {}. Filtering
is client-side (the `filtered` useMemo at 109-115) and already handles emptiness with "No
templates match." (line 144). Verdict: the finding is real at the quoted line, but only for
the fetch-failure path. The fix is an error/empty state instead of the unconditional skeleton
at line 117; the "unseeded tenant" scenario in the REPRO is not reachable.

</details>

### TPV and Purchase vendor detail pages report "Vendor not found" for any load failure, including 403 and 500

- **Owner:** Harshal · **Module:** tpv · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/tpv/pages/TpvVendorDetail.jsx:160`
- **What it does:**
  Both vendor detail layouts catch the fetch error, clear the loading flag, and leave the
  vendor state null. The only remaining branch is the not-found message, so a server error, a
  network drop, or a permission denial is presented to the user as a claim that the vendor
  record does not exist.
- **Reproduce:**
  Open /app/tpv/vendors/42 while the API is returning 500 (or as a user the backend rejects
  with 403). The spinner clears and the page reads "Vendor not found." The vendor does exist;
  the user has no way to know the difference and may raise a data-loss ticket. Same on
  /app/purchase/vendors/42 → "Purchase vendor not found."

```
TpvVendorDetail.jsx:158-165
  const load = useCallback(() => {
    setLoad(true)
    cfg.api.vendors.get(id).then(r => { setV(r?.data ?? r); setLoad(false) }).catch(() => setLoad(false))
  }, [id, cfg.api])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={wrap}>…<Loader2 …/></div>
  if (!v) return <div style={wrap}>…<p style={{ color: 'var(--text-muted)' }}>Vendor not found.</p></div>

frontend/src/modules/purchase/pages/vendor-detail/PurchaseVendorDetailLayout.jsx:52-61 and :109
      .catch(() => {})
      .finally(() => setLoading(false))
  …
```

<details><summary>What the refutation attempt checked</summary>

Opened both files; the quoted code exists essentially verbatim. TpvVendorDetail.jsx:159-166:
`const load = useCallback(() => { setLoad(true); cfg.api.vendors.get(id).then(...).catch(() =>
setLoad(false)) }, [id, cfg.api])` followed immediately by `if (loading) return <spinner>` and
`if (!v) return <p>Vendor not found.</p>` — the catch discards the error object, sets no error
state, and the null-vendor branch is the only remaining exit.
PurchaseVendorDetailLayout.jsx:52-61 is the same shape (`.catch(() => {}).finally(() =>
setLoading(false))`) with `if (!vendor) return ... 'Purchase vendor not found.'` at line 109.
Refutation attempts, all failed: (1) Shared client — services/tpvApi.js:377 and
services/purchaseApi.js:160 are plain `api.get(...).then(r => r.data)` on lib/api.js; that
instance's only response interceptor calls isSessionFailure() and re-rejects everything else.
lib/sessionFailure.js explicitly documents that a 403 never reaches it, and there is no global
error toast anywhere in the axios layer. (2) Error boundary — App.jsx wraps the tree in
components/ErrorBoundary, but that is a render-phase class boundary; a caught promise
rejection never reaches it, and there is no window 'error'/'unhandledrejection' handler in
frontend/src. (3) Dead code — both are routed: routes.jsx:667 `<Route path="view/:id"
element={<S><TpvVendorDetail /></S>}>` under /app/tpv, and routes.jsx:611 `<Route
path="vendors/:id/*" element={<S><PurchaseVendorDetailLayout /></S>}>` under /app/purchase.
`S` is only `({children}) => <Suspense fallback={<PageLoader/>}>` — no error handling. (4)
Earlier guard — the /app parent is `<ProtectedRoute>` with no `roles` prop and neither the tpv
nor purchase layout route restricts roles (only /vendor-portal and /company-portal pass a
roles list), so a user the backend rejects with 403 does reach the page. (5) Parent/hook
supply — modules/tpv/useVendorModule.js only picks which api client to use; it adds no error
handling. Two accuracy nits that do not change the defect: the TPV route is /app/tpv/view/:id,
not /app/tpv/vendors/:id as the repro says, and the anchor line is 159 (message at 166) rather
than 160.

</details>

### Inventory Dashboard has no error state — a failed summary renders every KPI tile as 0 stock, 0 products, 0 warehouses

- **Owner:** Shivam · **Module:** inventory · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/inventory/pages/InventoryDashboard.jsx:42`
- **What it does:**
  Four useQuery calls destructure only `data`/`isLoading`; `isError` is never read. When the
  summary request fails, `s` is undefined and every tile falls back through `s?.x ?? 0` or
  `fmtQty(undefined)` — which returns the string "0". Because the queries carry
  `refetchInterval: REFRESH_MS`, a dashboard that was showing correct numbers silently flips
  to all-zeros mid-session when the backend hiccups.
- **Reproduce:**
  Leave /app/inventory open and stop the API (or let the session expire). At the next refetch
  the tiles change to Available Stock 0, Reserved 0, Active Products 0, Warehouses 0, Out of
  Stock 0, Movements Today 0, with no banner, no toast and no stale-data indicator. A
  warehouse manager reads it as "we have nothing in stock".

```
InventoryDashboard.jsx:42-44
  const { data: s, isLoading, isFetching } = useQuery({
    queryKey: ['inv-summary'], queryFn: inventoryApi.summary, refetchInterval: REFRESH_MS,
  })

InventoryDashboard.jsx:74-79
    { key: 'available', label: 'Available Stock', value: fmtQty(s?.available), … },
    { key: 'products', label: 'Active Products', value: s?.products ?? 0, … },
    { key: 'warehouses', label: 'Warehouses', value: s?.warehouses ?? 0, … },
    { key: 'out_of_stock', label: 'Out of Stock', value: s?.out_of_stock ?? 0, … },

frontend/src/services/inventoryApi.js:531-534
  export const fmtQty = (q) => {
    const n = Number(q ?? 0)
```

<details><summary>What the refutation attempt checked</summary>

Opened the file. Evidence is verbatim: InventoryDashboard.jsx:42-44 destructures only {data:
s, isLoading, isFetching}; tiles at 74-79 use `fmtQty(s?.available)` and `s?.products ?? 0`;
inventoryApi.js:531-534 fmtQty returns '0' for undefined. Grepped the entire 465-line file for
/error/ — ZERO hits, so isError is genuinely never read and there is no local banner. Tried
hard to find generic handling and found none. main.jsx QueryClient defaults are only {retry:1,
staleTime, refetchOnWindowFocus:false} — no QueryCache/MutationCache, no global onError.
`throwOnError`/`useErrorBoundary` appear nowhere in the repo, so the ErrorBoundary in App.jsx
cannot catch query rejections (v5 defaults to not throwing; installed version 5.101.1). The
axios interceptor in lib/api.js only clears auth on isSessionFailure and never toasts. Route
is live and reachable: routes.jsx:754 `<Route path="inventory"
element={<S><InventoryDashboard/></S>}>` under /app, `<S>` is plain Suspense with no error
fallback, so not dead code. Checked whether the service swallows errors — it does not: every
call is `.catch(handleErr)` and services/apiError.js handleErr ALWAYS throws, so the query
really does enter error state with data undefined. IMPORTANT CORRECTION — the auditor's
mechanism and both repro steps are WRONG, only the conclusion survives. I read the installed
node_modules/@tanstack/query-core/src/query.ts:670-684: the 'error' reducer action spreads
`...state` and does NOT clear `data` (its own comment: "flag existing data as invalidated if
we get a background error"). So the headline scenario — "a dashboard showing correct numbers
silently flips to all-zeros mid-session when the backend hiccups" via refetchInterval — does
not happen; the tiles retain the last successful summary. The second repro path also fails: an
expired session returns 401 "Unauthenticated", lib/sessionFailure.js isSessionFailure matches
on the message, and the interceptor calls clearAuth() + redirects to /auth/login, so the user
never sees zeroed tiles. Marked real anyway because the defect is reachable by a path the
auditor did not describe: a COLD load where /inventory/summary fails with no cached data (e.g.
a 500 from the summary aggregation while the rest of the app is healthy). retry:1 exhausts,
status becomes 'error' so isLoading (isPending && isFetching) goes false, the skeleton return
at line 55 is skipped, s stays undefined, and every tile renders Available Stock 0 / Active
Products 0 / Warehouses 0 with no banner, toast or indicator. ProtectedRoute only checks a
locally-stored auth flag, so no earlier guard prevents it. This is also a clear deviation from
the codebase's own convention — sibling pages in the same module handle it
(ProductDetail.jsx:34 `if (isError)`, InventoryReports.jsx:178), as do
HelpdeskAnalytics.jsx:267 and VendorTasksPanel.jsx:75. The prescribed fix (read isError,
render an error state) is correct; the developer should ignore the "leave it open and stop the
API" repro and instead test a failed first load.

</details>

## Medium (16)

### Notification bell in both vendor portal headers is a dead button with a live-looking unread dot

- **Owner:** Harshal · **Module:** vendor-portal / purchase-portal · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/vendor-portal/VendorPortalShell.jsx:173`
- **What it does:**
  Both portal shells render a bell icon button with an unread indicator dot next to the
  working theme toggle and sign-out buttons. Neither has an onClick, so the dot advertises
  notifications the user can never open.
- **Reproduce:**
  Sign in to /vendor-portal (or /purchase-portal) → top-right header shows a bell with a red
  dot → click it. Nothing opens. The buttons immediately beside it (theme toggle, sign out)
  work, so the bell reads as functional. Repeat on the purchase portal for the identical
  control.

```
VendorPortalShell.jsx:173-176 `<button className="portal-icon-btn" title="Notifications">` / `  <Bell size={16} />` / `  <span className="notif-dot" />` / `</button>` — no onClick, immediately followed at :179 by the working `<button onClick={() => setTheme(t => t === 'dark' ? 'light' : 'dark')} ...>`.
Same control, second file: pages/purchase-portal/PurchasePortalShell.jsx:100 `<button className="portal-icon-btn" title="Notifications"><Bell size={16} /><span className="notif-dot" /></button>`, again beside a working `onClick={() => setTheme(...)}` and `onClick={doLogout}` on the same line group.
```

<details><summary>What the refutation attempt checked</summary>

Confirmed after failing to refute on every angle. (1) Code exists verbatim:
VendorPortalShell.jsx:173-176 is exactly as quoted, and PurchasePortalShell.jsx:100 is the
identical one-line control; neither button has onClick, type, or spread props. (2) No handler
supplied elsewhere: grepped all of src/ for document-level click delegation — only two
addEventListener('click') exist (InlineMentions.jsx, MediaLightbox.jsx), and the lightbox one
is scoped to images via closest(RICH_SELECTORS). Nothing keys on [title="Notifications"] or
.portal-icon-btn. The parent <div className="portal-header-right"> has no onClick, so no
delegation catches it. Neither shell imports any notification component. (3) Both files are
live-routed: app/routes.jsx:816 mounts VendorPortalShell under /vendor-portal behind
ProtectedRoute roles=['vendor','third_party_vendor']; routes.jsx:884 mounts
PurchasePortalShell under /purchase-portal behind PurchaseVendorPortalGuard (token check
only). No guard prevents the described state. (4) The dot is worse than described:
portal.css:223 renders .notif-dot as a solid 8px #ef4444 circle unconditionally, with no
conditional rendering in either JSX — it is not bound to any unread count, so it permanently
signals a false unread state. (5) Two aggravating findings beyond the audit: the app already
ships a correct components/notifications/NotificationBell.jsx with onClick={toggle}, API-
fetched unread state and a dropdown, which the portals do not reuse; and
PortalDashboard.jsx:458 comments "no notification API is wired yet", so there is no portal
notification data source for the bell to open even if a handler were added. Only near-
mitigation: the vendor portal dashboard has a Notifications card offering pseudo-notifications
by another path, but that does not make the header button functional and the purchase portal
has no such counterpart.

</details>

### Customer note row menu: "Convert to Project" is permanently disabled on a stale "module coming soon" excuse

- **Owner:** Zafar · **Module:** customer · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/customer/components/NotesTab.jsx:183`
- **What it does:**
  The row menu on every customer note offers Convert to Task / Ticket / Project. The Project
  entry is hard-coded `disabled` with the tooltip "Projects module coming soon", but the
  Projects module is built, routed and already reachable from the same customer record.
- **Reproduce:**
  Open /app/customers/{id} → Notes tab → the ⋯ menu on any note. "Convert to Project" is
  greyed at 45% opacity with cursor:not-allowed; hovering says "Projects module coming soon".
  Meanwhile /app/projects and /app/projects/:id are live routes, and the customer's own
  Expenses tab already fetches and offers a real project picklist.

```
NotesTab.jsx:183 `<button className="row-menu-item" disabled title="Projects module coming soon" style={{ opacity: 0.45, cursor: 'not-allowed' }}>Convert to Project</button>` — the three siblings around it are live: :181 `onClick={() => startEdit(n)}`, :182 `onClick={() => convertToTask(n)}`, :183 (ticket) `onClick={() => convertToTicket(n)}`.
The Projects module exists: app/routes.jsx declares `/app/projects` and `/app/projects/:id`. The same claim is stale in two more places in Zafar's code — modules/customer/components/recordSchemas.js:46 `helpText: 'Projects module coming soon — the link activates when it ships.'` on a field whose options are actually fetched at CustomerDetail.jsx:193 `customerApi.projects.list()`, and modules/accounts/pages/Cheques.jsx:428 `hint={projects.length ? null : 'Projects module coming soon'}` against a real endpoint (backend PartyDirectoryController::projects, now backed by ProjectDirectoryContract).
```

<details><summary>What the refutation attempt checked</summary>

Confirmed at the exact path and line.
`frontend/src/modules/customer/components/NotesTab.jsx:183` is verbatim the quoted `<button
className="row-menu-item" disabled title="Projects module coming soon" style={{ opacity: 0.45,
cursor: 'not-allowed' }}>Convert to Project</button>`, with live siblings at :180 startEdit,
:181 convertToTask, :182 convertToTicket, :184 share, :185 delete (the audit's ":183 (ticket)"
is an off-by-one typo; the disabled Project button is unambiguously 183). Refutation attempts,
all failed: - Not dead code: `CustomerDetail.jsx:358` renders `{tab === 'Notes' && <NotesTab
.../>}`, and 'Notes' is a declared tab at CustomerDetail.jsx:60 with a real loader at :162.
`RowMenu` (modules/sales/components/RowMenu.jsx) simply portals `{children}` into the menu, so
the disabled button really renders and really is inert — no wrapper injects an onClick, and
`disabled` blocks the menu's own click-to-close handler path for that item too. - No handler
supplied elsewhere: there is no `convertToProject` anywhere in the file (grep of the whole
component shows only convertToTask/convertToTicket/share/doDelete), no spread props, no
parent-supplied action. Nothing in a hook or shared wrapper can re-enable a hard-coded literal
`disabled`. - The excuse is factually stale: `app/routes.jsx:180-181` lazy-loads
ProjectList/ProjectDetail and `:741-742` mounts `/app/projects` and `/app/projects/:id`
unconditionally — the `<S>` wrapper is only `Suspense` (routes.jsx:393), no module flag, no
permission gate, so every user reaching the Notes tab also has Projects.
`services/projectApi.js` exposes a full real API including `create: POST /projects`, and
`backend/routes/api.php:19` loads `api_projects.php`. - The two corroborating stale strings
are also real: `recordSchemas.js:46` carries helpText "Projects module coming soon — the link
activates when it ships." on `project_id`, while `CustomerDetail.jsx:190` actually fetches
`customerApi.projects.list()` → `GET /customers/projects-stub`, which
`backend/routes/customer.php:84` resolves through the real
`ProjectDirectoryContract::listProjects()` (its own comment says the empty stub was replaced).
`accounts/pages/Cheques.jsx:428` shows the same stale hint against a populated list. So a real
user on /app/customers/{id} → Notes → ⋯ sees a permanently greyed "Convert to Project" whose
tooltip asserts a module that is shipped, routed, and already feeding a picklist on the
sibling Expenses tab. Nothing generic (error boundary, interceptor, toast wrapper) is relevant
— the control never fires at all. Severity is low (misleading dead UI / unimplemented
conversion, not a crash or data bug), but the finding as stated is accurate and the fix is in
Zafar's own files.

</details>

### "Terms & Conditions" on all three self-service registration screens is href="#" — the mandatory checkbox links nowhere

- **Owner:** mixed · **Module:** auth · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/auth/VendorRegisterPage.jsx:339`
- **What it does:**
  Every public registration form requires the user to tick an "I accept the Terms &
  Conditions" box (validation message: "Please accept the terms to continue."), and the terms
  link beside it is a bare `href="#"` with no handler. Clicking it jumps to the top of the
  page. The login page's Terms / Privacy / Support footer links are the same.
- **Reproduce:**
  Go to /auth/register/vendor (or /auth/register/tpv, /auth/register/client) → the submit is
  blocked until the terms box is ticked → click the "Terms & Conditions" link to read what you
  are agreeing to → the page scrolls to top and nothing opens. Seven occurrences in total
  across four auth screens.

```
VendorRegisterPage.jsx:339 `<a href="#" className="text-blue-400 hover:underline font-medium">Terms & Conditions</a>` with the gating rule at :57 `terms: { required: 'Please accept the terms to continue.' }`.
Same link, two more files: pages/auth/TPVRegisterPage.jsx:310 `<a href="#" className="text-blue-400 hover:underline font-medium">Terms & Conditions</a>`; pages/auth/ClientRegisterPage.jsx:298 `<a href="#" className="text-teal-400 hover:underline font-medium">Terms & Conditions</a>`.
And the login footer, pages/auth/LoginPage.jsx:340-342 `<a href="#" className="hover:underline">Terms</a>` / `<a href="#" className="hover:underline">Privacy</a>` / `<a href="#" className="hover:underline">Support</a>`.
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute and could not. (1) Quoted code exists verbatim: VendorRegisterPage.jsx:339 is
the exact bare `<a href=\"#\" className=\"text-blue-400 hover:underline font-medium\">Terms &
Conditions</a>`, and :57 is `terms: { required: 'Please accept the terms to continue.' },`.
TPVRegisterPage.jsx:310, ClientRegisterPage.jsx:298 and LoginPage.jsx:340-342 all match as
quoted. (2) No handler is supplied anywhere the auditor didn't look — these are raw `<a>`
elements (not a shared wrapper/custom component), with no onClick, no spread props, no data
attributes, and no delegating parent. (3) No global interception: the only document-level
capture click listener in the app is MediaLightbox.jsx:75 (mounted app-wide at App.jsx:14),
whose `open` handler returns unless e.target.tagName is IMG or VIDEO inside a rich-text
container, so anchors pass through; the only other listener (InlineMentions.jsx:111) is scoped
to an editor element. (4) There is no Terms/Privacy page to link to — grepped app/routes.jsx
and the entire frontend for terms/privacy routes or page components and found none; the only
\"Terms & Conditions\" hits are per-document body fields on invoices/estimates/proposals/POs.
So the content was never built, it's not merely a lost `to=`. (5) Not dead code and not
guarded: routes.jsx:416-418 registers all three pages under /auth with NO
GuestRoute/ProtectedRoute wrapper (unlike login/forgot-password which are wrapped),
RegisterPage.jsx:20-80 links into them, and LoginPage.jsx:312 links to /auth/register — an
anonymous user reaches them. (6) The gating is real on Vendor (RULES.terms required) and
Client (zod at ClientRegisterPage.jsx:22 `terms: z.literal(true, ...)` message 'Accept to
continue'). THREE CORRECTIONS that do not overturn the finding: (a) TPV's checkbox is NOT
actually mandatory — TPVRegisterPage.jsx:41 uses `useForm()` with no resolver and :307 is
`{...register('terms')}` with no rules, so the form submits with the box unticked and the red
asterisk is cosmetic; the claim's \"every public registration form requires the user to tick\"
is false for TPV (arguably a separate defect: an unenforced consent gate). (b) The count is
six occurrences, not seven — 1 Vendor + 1 TPV + 1 Client + 3 Login footer, confirmed by per-
file grep -c. (c) The auditor missed CompanyRegisterPage.jsx:86-87, which has a genuinely
gated checkbox (:30 `if (!terms) { setApiError('Please accept the terms to continue.'); return
}`) whose \"Terms of Service and Privacy Policy\" text has no link at all — same class of gap.
Core defect confirmed: gated consent on public registration with a dead link and no terms
content anywhere in the app.

</details>

### Vendor registration "⚙ Setup access period" button inside the temporary-vendor warning does nothing

- **Owner:** Harshal · **Module:** auth / vendor onboarding · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/auth/VendorRegisterPage.jsx:178`
- **What it does:**
  The yellow "Temporary Vendor Account — Limited Access" banner offers a bordered, hover-
  styled call-to-action promising to configure the 5-day access window. The element has no
  onClick, no type and is not inside a form — it is inert markup.
- **Reproduce:**
  Open /auth/register/vendor?type=temporary → the banner explains the account expires 5 days
  after approval and offers "⚙ Setup access period" → click it. Nothing happens. The user is
  left believing they can set their own window; they cannot, and the copy directly above
  already says to contact an administrator.

```
VendorRegisterPage.jsx:178-181 `<button className="mt-2 text-xs font-semibold text-yellow-300 border border-yellow-400/40` / `                   px-3 py-1 rounded-lg hover:bg-yellow-400/10 transition-colors">` / `  ⚙ Setup access period` / `</button>` — no onClick, no type="submit"; the enclosing element is a plain `<div className="mx-6 mt-5 flex items-start gap-3 px-4 py-4 rounded-xl">` at :169, not a form, and the real `<form onSubmit={handleSubmit(onSubmit)}>` does not start until :186.
```

<details><summary>What the refutation attempt checked</summary>

Opened frontend/src/pages/auth/VendorRegisterPage.jsx and confirmed the code at :178-181
verbatim — the \"⚙ Setup access period\" button has no onClick, no type, no disabled, no form
attribute and no spread props. Tried to refute five ways and all failed: (1) DOM nesting — the
button sits in the plain <div> banner at :169 which closes at :184, while <form
onSubmit={handleSubmit(onSubmit)}> only opens at :186 as a sibling, so even a default
type=\"submit\" has no form ancestor to submit; every other real button on the page (:306,
:316, :349) carries an explicit handler/type. (2) Injected handler — the component signature
is VendorRegisterPage() with no props, and grep -rn \"VendorRegisterPage\" across frontend
returns only its own definition plus the import and route line, so no parent/wrapper can
supply one. (3) Generic catcher — grepped src/ for document click delegation; only
InlineMentions.jsx:111 (scoped to an editor ref) and MediaLightbox.jsx:75 (capture listener
for images in rich-content areas, not mounted on the auth tree) exist; neither intercepts this
click. (4) Reachability — routes.jsx:416 registers <Route path=\"register/vendor\"> under the
unguarded /auth parent, and RegisterPage.jsx:62 sends the \"Temporary Vendor\" card to
/auth/register/vendor?type=temporary via handleSelect → navigate(route) at :90-91/:136, so
isTemporary is true and the banner renders for a real user. (5) Copy check — :176 already says
\"Contact your administrator\", confirming the CTA promises something the flow does not offer.
Only mitigating context: the same file has another handler-less decorative button at :139
(\"Explore Updates —\"), which suggests a mock-button pattern but broadens rather than refutes
the finding.

</details>

### HR Exit Management "Download Summary" is a Download button that only raises an error toast

- **Owner:** Harshal · **Module:** hr · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/modules/hr/pages/ExitManagement.jsx:1036`
- **What it does:**
  In the Full & Final settlement card, "Download Summary" is styled identically to the live
  Mark Reviewed / Approve / Mark Settled actions beside it, carries a Download icon, and its
  entire handler is a red toast saying the feature is not built. Nothing is generated or
  fetched.
- **Reproduce:**
  Open /app/hr/exit-management → pick an exit with a settlement → the settlement card shows
  "Mark Reviewed / Approve / Mark Settled" and "⬇ Download Summary" in the same button row →
  click Download Summary → a red error toast reads "Summary export arrives with Exit Reports
  (next phase)." No file, and the button is not visually distinguished from its working
  neighbours or disabled.

```
ExitManagement.jsx:1036 `{c && <button onClick={()=>showToast('Summary export arrives with Exit Reports (next phase).','error')} className="flex-1 py-2.5 rounded-xl text-sm font-bold flex items-center justify-center gap-2" style={{ background:'var(--bg-input)', color:'var(--text-h)', border:'1px solid var(--border)' }}><Download size={14}/> Download Summary</button>}`
Its three siblings in the same flex row (:1033-1035) are all real mutations: `onClick={review}`, `onClick={approve}`, `onClick={settle}`. Compare modules/sales/pages/Estimates.jsx:375, which handles the same situation honestly by labelling the item "Send to Client (soon)" AND setting `disabled: true`.
```

<details><summary>What the refutation attempt checked</summary>

I tried to refute this and could not. What I checked: 1. **Code exists verbatim.**
`/home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/hr/pages/ExitManagement.jsx:1036` matches
the quoted evidence character-for-character (grep across all of frontend/src returns exactly
this one hit). The handler's entire body is `showToast('Summary export arrives with Exit
Reports (next phase).','error')` — no API call, no blob, no window.open, no ref to any export
helper. 2. **Nothing supplies the behavior elsewhere.** `showToast` is not a shared hook that
could be intercepted — it is a plain local `useState` setter defined at line 60 of the same
file and threaded down as a prop (`FullAndFinal` at :94 → `SettlementDrawer` at :912). Line 65
renders it as a red gradient toast when `type==='error'`. No parent, wrapper, or interceptor
is in the path. I also checked the API layer: `frontend/src/services/hrApi.js:608-617` defines
`hrApi.exit.settlements` with only `list / get / history / forEmployee / generate / review /
approve / settle` — there is **no** export/download/pdf method. Backend confirms the same:
`backend/routes/exit.php:58-65` has no export or download route for settlements. So there is
nothing the button could have been wired to. 3. **The siblings really are live.** Lines
1033-1035 call `review` / `approve` / `settle`, defined at :926-928 as
`act(()=>hrApi.exit.settlements.review(id), …)` etc. — real PATCH mutations with busy state
and reload. The contrast in the same flex row is genuine. 4. **It is reachable, not dead
code.** Routed at `frontend/src/app/routes.jsx:71,486` (`/app/hr/exit-management`), linked in
`frontend/src/components/layout/Sidebar.jsx:101`. The Full & Final tab is `{ key:'fnf', …
ready:true }` (line 23), so it is not hidden behind a not-ready gate. The list row's "Open"
button (`onClick={()=>setView(r.id)}`, :905) opens `SettlementDrawer`. 5. **No earlier guard
prevents the state.** The render condition is `c = s?.components` (line 934), i.e. the button
appears for any settlement that has been generated — precisely the same state in which the
three working buttons appear. A user reaching the settlement card unavoidably sees it. 6.
**Not disabled and not labelled as pending.** No `disabled` attribute, no "(soon)" in the
label. The codebase's own honest precedent is confirmed at
`frontend/src/modules/sales/pages/Estimates.jsx:375`: `{ icon: Send, label: 'Send to Client
(soon)', action: () => showToast('Estimate emailing is coming soon','error'), disabled: true
}`. One small correction to the finding's wording, which does not change the verdict: the
button is not styled *identically* to its neighbours — it uses a neutral `var(--bg-input)`
background with a border rather than a colored gradient. But it is the same size, shape,
weight and row position, is fully enabled, and carries no "soon"/disabled affordance, so it
still reads as an available action. The substantive claim — clicking it produces only a red
error toast and generates or fetches nothing — is fully verified.

</details>

### Invoice KPI cards are computed from the status-filtered list, so picking a tab zeroes the other counters

- **Owner:** Zafar · **Module:** sales · **Dimension:** List surfaces — filter, search, sort, paginate, export
- **Where:** `frontend/src/modules/sales/pages/Invoices.jsx:176`
- **What it does:**
  `data` is the server response already narrowed by the active status tab, yet the six KPI
  tiles above the table are derived from it. Selecting any status makes the other tiles read 0
  and turns "Total Invoiced" / "Outstanding" into a figure for that tab only, while still
  being labelled as totals. The sibling Estimates page deliberately avoids this and documents
  why.
- **Reproduce:**
  Open /app/sales/invoices with a mix of statuses. The tiles read e.g. Total 40 / Unpaid 12 /
  Overdue 5 / Paid 23. Click the "Paid" tab: Unpaid and Overdue drop to 0, Total drops to 23,
  and Outstanding drops to ~0 — although nothing was paid.

```
// Invoices.jsx:64 — the list is server-filtered by the tab
    salesApi.invoices.list({status: filter!=='All'?filter:undefined}).then(d=>{setData(d);setLoading(false)})

// Invoices.jsx:174-181 — the KPIs then count that same narrowed array
  const stats = {
    total: data.length,
    unpaid: data.filter(i=>i.status==='Unpaid').length,
    overdue: data.filter(i=>i.status==='Overdue').length,
    paid: data.filter(i=>i.status==='Paid').length,
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute it and could not. What I checked: 1. Quoted code exists verbatim. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Invoices.jsx:62-66 is
`salesApi.invoices.list({status:
filter!=='All'?filter:undefined}).then(d=>{setData(d);setLoading(false)})` inside `load()`,
re-run by `useEffect(()=>{load()},[filter])`. Lines 174-181 build `stats`
(total/unpaid/overdue/paid/totalAmt/outstanding) from that same `data` array. Lines 205-219
render all six tiles from `stats`, unconditionally — no `filter==='All'` guard, no separate
summary source. 2. The narrowing is real server-side, not a no-op param.
frontend/src/services/invoiceApi.js `list` passes params straight to GET /sales/invoices;
backend/app/Http/Controllers/Api/Sales/InvoiceController.php::index forwards `status` to
InvoiceService::list, which calls backend/app/Repositories/Sales/InvoiceRepository.php:16-18 —
`if (!empty($filters['status']) && $filters['status'] !== 'All') $query->where('status',
$filters['status'])`. So on the "Paid" tab the response contains only Paid rows, making
unpaid/overdue mathematically 0 and total/totalAmt/outstanding tab-scoped. 3. Looked for the
data the auditor might have missed: only one `useState` list (`data`, line 42), one fetch call
site (grep for `salesApi.invoices.list` / `setData` returns just line 64), no second
unfiltered request, no parent/hook/wrapper supplying full-set counts. Route is live:
frontend/src/app/routes.jsx:87 lazy-imports this exact file and :505 mounts it at `invoices`
under the sales path, so a user reaches it at /app/sales/invoices. 4. Confirmed the sibling
contrast the finding cites. Estimates.jsx:107-112 fetches deliberately unfiltered with the
comment "Unfiltered on purpose: the KPI boxes must count every estimate of this type…",
filters client-side into `byStatus` (line 217) and feeds only that to useListView, with a
second comment at 219-221: "The KPI boxes above stay on the FULL set (`data`) — they're a
summary of the workspace, not of the current search." Invoices does the opposite. Nothing
prevents the described outcome. Impact is display-only (misleading tiles, plus ListToolbar
`total={data.length}` at line 225 is narrowed too) — no data corruption — but the tiles are
labelled as totals and read wrong on every non-"All" tab.

</details>

### Invoice "Due Date" is `required` server-side but carries no asterisk, no default and no client check

- **Owner:** Zafar · **Module:** sales · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/sales/pages/Invoices.jsx:354`
- **What it does:**
  `StoreInvoiceRequest` marks both `date` and `due_date` as `required|date`. The form defaults
  `date` to today but leaves `due_date` as an empty string, renders it with a plain "Due Date"
  label (no `*`), and `handleCreate` only checks `client_id`. Combined with the missing
  try/catch on the same handler, the resulting 422 is completely invisible.
- **Reproduce:**
  Sales → Invoices → New Invoice. Select a customer, add a line item, and click "Create
  Invoice" without touching Due Date (it is blank on open and nothing says it is needed).
  Nothing happens — no invoice, no message. There is no way for the user to discover that Due
  Date is the blocker.

```
frontend Invoices.jsx:353-354 —
```
<label className="label">Due Date</label>
<input type="date" className="input-3d text-sm" value={form.due_date} onChange={e => sf('due_date', e.target.value)} />
```
frontend Invoices.jsx:26-27 — `due_date:'',` in EMPTY
frontend Invoices.jsx:120 — `if(!form.client_id) return showToast('Customer required','error')`  (no due_date check)
backend StoreInvoiceRequest.php:21 — `'due_date'      => 'required|date',`
```

<details><summary>What the refutation attempt checked</summary>

Confirmed at the exact quoted lines. Invoices.jsx:353-354 has a plain \"Due Date\" label with
no asterisk and an input with no `required` attribute; line 27 sets `due_date:''` in EMPTY
while line 26 defaults `date` to today; handleCreate (119-123) checks only `client_id` and has
no try/catch, unlike the four sibling handlers directly below it which all wrap their calls.
Backend StoreInvoiceRequest.php:20 is `'due_date' => 'required|date'` and the whole 39-line
file has only authorize() + rules() — no prepareForValidation or merge — and
routes/sales.php:157 binds it to InvoiceController@store, so validation fires before any
controller default could apply. I chased every escape route: nothing sets due_date on the
drawer-open path (the ?new=1 effect at 71-84 sets only client_id; applyTemplate copies line
items/terms/notes/currency/discount, never a date); invoiceApi.create ends in
.catch(handleErr) and apiError.js:114 always throws; lib/api.js's response interceptor only
handles session failures and re-rejects, with no toast; grep for unhandledrejection across all
of frontend/src returns zero hits and App.jsx's ErrorBoundary cannot catch async rejections;
the submit button at line 496 has no `disabled` and there is no <form> element in the file so
native HTML validation cannot fire; and app/routes.jsx:504 routes the page, so it is live
code. One correction to the repro: the New Invoice button (line 199) goes to
/app/sales/invoices/new, which renders the DocumentStart template picker; its go() helper
(DocumentStart.jsx:56-60) then navigates back to ?new=1 and opens this drawer — one extra
click, identical end state.

</details>

### HR Add Employee does not enforce the `required_unless` probation rule, so a valid-looking form is rejected with two words

- **Owner:** Harshal · **Module:** hr · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/hr/pages/Employees.jsx:155`
- **What it does:**
  `StoreEmployeeRequest` requires `probation_policy_id` unless `skip_probation` is true, and
  requires `probation_skip_reason` when it is. `handleSave` checks only
  name/department/designation/joining_date, so both branches of the probation block can be
  left blank. The section is even labelled "Probation *", promising a check that does not
  exist, and the FormRequest's hand-written `messages()` for exactly this case is destroyed by
  the global handler.
- **Reproduce:**
  HR → Employees → Add Employee. Fill Name, Department, Designation, Joining Date. Leave the
  "Probation *" dropdown on its placeholder and leave the exempt checkbox unticked. Click "Add
  Employee". Toast reads "Validation failed"; the Probation dropdown is not highlighted and
  nothing points at it. Ticking "exempt" and leaving the reason box blank fails identically.

```
frontend hr/pages/Employees.jsx:155 — `if (!form.name||!form.department||!form.designation||!form.joining_date) return showToast('Name, department, designation & joining date required','error')`  (no probation check)
frontend hr/pages/Employees.jsx:373 — `<p className="text-[11px] font-black mb-2" ...>Probation *</p>`
frontend hr/pages/Employees.jsx:169 — `} catch (e) { showToast(e.response?.data?.message||'Failed','error') }`
backend StoreEmployeeRequest.php:45-46 —
```
'probation_skip_reason'  => 'required_if:skip_probation,true,1|nullable|string|max:500',
'probation_policy_id'    => 'required_unless:skip_probation,true,1|nullable|integer',
```
backend StoreEmployeeRequest.php:55 — `'probation_policy_id.required_unless' => 'Choose a probation policy, or mark this hire as exempt with a reason.',` (never reaches the client)
```

<details><summary>What the refutation attempt checked</summary>

I opened every file and tried to find something that prevents the outcome; nothing does.
Quoted code verified verbatim: - /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/hr/pages/Employees.jsx:155 — `if
(!form.name||!form.department||!form.designation||!form.joining_date) return showToast('Name,
department, designation & joining date required','error')`. No probation check. -
Employees.jsx:373 — `<p className="text-[11px] font-black mb-2" ...>Probation *</p>`, inside a
`{!editingId && (...)}` block that renders the policy `<select>` (no `required` attribute,
placeholder `<option value="">Choose a probation policy…</option>`) or, when `skip_probation`
is ticked, a plain reason `<input>` (also no `required`). - Employees.jsx:169 — `catch (e) {
showToast(e.response?.data?.message||'Failed','error') }`. -
backend/app/Http/Requests/Hr/StoreEmployeeRequest.php:45-46 and the `messages()` override at
:55-56 are exactly as quoted. Refutation attempts that failed: 1. No hidden client-side guard.
There is no `<form>` wrapper — `components/ui/Modal.jsx` renders a bare portal `<div>`, and
the submit button is `<button onClick={handleSave} disabled={saving}>` (line 428), so native
HTML validation never runs. `grep` for `errors|apiError|fieldError` in Employees.jsx returns
nothing: the component has no field-error state at all, so no highlight is possible.
`EMPTY_FORM` (line 16-21) initialises `probation_policy_id:''`, `skip_probation:false`,
`probation_skip_reason:''`, so a blank submit is the default state. 2. No interceptor rescue.
`hrApi` (services/hrApi.js:13-31) has one response interceptor that only calls
`clearAuth()`/redirect on `isSessionFailure`, then `Promise.reject(err)` — the 422 passes
through untouched, so `e.response.data.message` is what reaches the toast. 3. The global
handler really does destroy the messages(). `bootstrap/app.php:56-62` renders every
`ValidationException` on `api/*` as `['status'=>'error','message'=>'Validation
failed','errors'=>$e->errors()]`. The custom sentences land only in
`errors.probation_policy_id[0]`, which the frontend never reads. Toast text is literally the
two words "Validation failed". 4. The rule genuinely fires. Route registered at
backend/routes/hr.php:239 (`Route::post('/employees', [EmployeeController::class,'store'])`),
and EmployeeController::store type-hints `StoreEmployeeRequest`. `skip_probation` carries a
`boolean` rule, so Laravel's `parseDependentRuleParameters` converts `['true','1']` to
`[true,'1']`; with `$other === false`, `required_unless` applies and `probation_policy_id:''`
fails required. The mirror branch (`skip_probation:true`, blank reason) fails `required_if`
the same way. `nullable` does not suppress this — `required_if`/`required_unless` are implicit
rules. 5. Page is live, not dead code: frontend/src/app/routes.jsx:54 lazy-imports it and :470
mounts `<Route path="employees" .../>` under the HR area, matching the repro path. One scoping
note that does not refute the finding: the probation block only renders on create
(`!editingId`), and the same `handleSave` is reused for edit — but the create path is exactly
the path the claim describes.

</details>

### Record Payment amount input has no min or max — the balance cap exists only on the server and the rejection is invisible

- **Owner:** Zafar · **Module:** sales · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/sales/pages/Invoices.jsx:536`
- **What it does:**
  `RecordPaymentRequest` computes `min:0.01` and `max:{invoice balance}` from the bound
  invoice. The UI's amount box is a bare `type="number"` whose only hint is a placeholder; it
  has no `min`, no `max`, and `handlePay` tests only `!payForm.amount` — so `-500` passes the
  client check (truthy string) while `0` is wrongly rejected. Because `handlePay` also has no
  try/catch, an over-balance or negative amount produces no message whatsoever.
- **Reproduce:**
  Sales → Invoices → pick an invoice with a balance of 4,500 → Record Payment → type 5000 →
  click Record Payment. Nothing happens: no toast, no error, the modal stays open, and the
  payment is not recorded. The placeholder said "Max: 4500" but the field let the number be
  typed and no validation message ever appears.

```
frontend Invoices.jsx:536-537 —
```
<input type="number" className="input-3d text-sm" placeholder={`Max: ${selectedInv.balance}`}
  value={payForm.amount} onChange={e => setPayForm(p => ({...p, amount: e.target.value}))} />
```
frontend Invoices.jsx:124-128 —
```
const handlePay = async () => {
  if(!payForm.amount) return showToast('Amount required','error')
  await salesApi.invoices.recordPayment(selectedInv.id, payForm)
  showToast('Payment recorded!'); ...
}
```
backend RecordPaymentRequest.php:29 — `'amount' => ['required', 'numeric', 'min:0.01', $max !== null ? "max:{$max}" : 'max:0'],`
```

<details><summary>What the refutation attempt checked</summary>

CONFIRMED. I opened every file and tried each refutation path; all of them failed to rescue
the behavior. 1. Quoted code exists verbatim. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Invoices.jsx:536-537 is
exactly the bare `<input type="number" className="input-3d text-sm" placeholder={`Max:
${selectedInv.balance}`} ...>` — no `min`, no `max`, no `step`, no pattern. The balance is
only interpolated into the placeholder string. handlePay at lines 124-128 is exactly as
quoted: single `if(!payForm.amount)` guard, `await salesApi.invoices.recordPayment(...)`, no
try/catch. 2. The server cap is real and wired.
backend/app/Http/Requests/Sales/RecordPaymentRequest.php:29 has `'amount' =>
['required','numeric','min:0.01', $max !== null ? "max:{$max}" : 'max:0']`, and
app/Http/Controllers/Api/Sales/InvoiceController.php:75 type-hints
`recordPayment(RecordPaymentRequest $request, SalesInvoice $invoice)`, so the 422 genuinely
fires on an over-balance amount. 3. No generic rescue exists — this was the most promising
refutation and it collapsed. `salesApi.invoices` resolves through services/salesApi.js to
`invoiceApi` (services/invoiceApi.js:24), whose recordPayment ends in `.catch(handleErr)`. I
read services/apiError.js: `handleErr` is documented "Always throws" and its last statement is
`throw error`. So the rejection propagates back into handlePay, which has no catch. The axios
response interceptor in lib/api.js only calls `clearAuth()` on `isSessionFailure` and
otherwise does `return Promise.reject(error)` — it never toasts. components/ErrorBoundary.jsx
is a standard render-phase class boundary (getDerivedStateFromError/componentDidCatch); React
error boundaries do not catch async promise rejections, so it cannot fire here. I grepped the
whole frontend src/ and index.html for `unhandledrejection` — zero hits. Nothing turns the 422
into user-visible feedback. 4. The page is reachable, not dead code. app/routes.jsx:87 lazy-
imports it and line 504 mounts it: `<Route path="invoices" element={<S><SalesInvoices /></S>}
/>`. 5. No earlier guard blocks the state. The submit button (line 602) is `<button
onClick={handlePay}>` with no `disabled` prop or validity condition. EMPTY_PAY (line 36)
initializes `amount:''`, so the only thing the client guard catches is a completely empty
field; any typed over-balance number sails through. Net effect matches the repro: typing 5000
against a 4500 balance produces a 422, handleErr throws, the rejection is unhandled,
`showToast('Payment recorded!')` never runs, and the modal stays open with no message. One
inaccuracy worth flagging to whoever fixes this, which does not change the verdict: the
claim's aside that "`0` is wrongly rejected" is wrong. A `type="number"` input yields a
string, so `"0"` is truthy and `!payForm.amount` is false — 0 passes the client check and is
rejected silently by the server's `min:0.01` like every other bad value. The real client-side
gap is uniformly "no feedback," not a false rejection. Also noted as scope, not as a separate
finding: the identical pattern exists at modules/sales/pages/InvoiceDetail.jsx:62-65 (same
one-line guard, same missing try/catch), so a fix should cover both call sites.

</details>

### Task form deletes `rel_id` when blank while still sending a linked `rel_type`, tripping the server's `required_if` with an unreadable field name

- **Owner:** Shivam · **Module:** tasks · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/tasks/components/TaskFormDrawer.jsx:280`
- **What it does:**
  `StoreTaskRequest` makes `rel_id`
  `required_if:rel_type,project,ticket,customer,contract,...`. The drawer's submit strips
  `rel_id` when it is empty but leaves `rel_type` as the user picked it, and the submit button
  is disabled only for a missing name or milestone. The "Related To" picker carries no
  required marker. The resulting 422 is surfaced through `prettyField`, which strips the `_id`
  suffix and prints the field as "Rel" — a label that appears nowhere on the form.
- **Reproduce:**
  Tasks → New Task. Type a name, change "Related To" from Standalone to Project, then don't
  open the project picker. Click Create. Toast: "Please check 1 field / • Rel: The rel id
  field is required when rel type is project." There is no field called "Rel" on screen, and
  the un-chosen project picker is not highlighted.

```
frontend tasks/components/TaskFormDrawer.jsx:280 — `if (p.rel_type === 'standalone' || !p.rel_id) delete p.rel_id`
frontend tasks/components/TaskFormDrawer.jsx:560 — `<button type="submit" disabled={!form.name.trim() || busy || missingMilestone}`  (no rel_id term)
backend StoreTaskRequest.php:53 — `'rel_id' => 'nullable|integer|min:1|required_if:rel_type,project,ticket,customer,contract,tpv_vendor,purchase_vendor,lead,meeting',`
frontend services/apiError.js:24 — `.map((seg) => ... seg.replace(/_id$/, '').replace(/_/g, ' '))` → "Rel"
```

<details><summary>What the refutation attempt checked</summary>

Tried hard to refute it and could not. Verified the whole chain end to end. QUOTED CODE — all
three snippets exist verbatim at the stated paths: -
frontend/src/modules/tasks/components/TaskFormDrawer.jsx:280 — `if (p.rel_type ===
'standalone' || !p.rel_id) delete p.rel_id` (rel_type is NOT stripped alongside it). -
backend/app/Http/Requests/Task/StoreTaskRequest.php:53 — `'rel_id' => 'nullable|integer|min:1|
required_if:rel_type,project,ticket,customer,contract,tpv_vendor,purchase_vendor,lead,meeting'
`. - frontend/src/services/apiError.js:27 — `seg.replace(/_id$/, '').replace(/_/g, ' ')`;
`rel_id` → `rel` → capitalized → "Rel". REACHABILITY — not dead code. TaskFormDrawer is
imported by 6 screens; the repro path is frontend/src/modules/tasks/pages/TaskBoard.jsx:239,
which passes `defaults={{ rel_type: relType || 'standalone', rel_id: relId || '' }}` and does
NOT pass `lockRel`, so `relLocked` (line 297) is false and the rel_type `<Select>` at line 431
is freely editable. Backend route exists: routes/api_tasks.php:49 `Route::post('/',
[TaskController::class, 'store'])`, and TaskController.php:55 is `store(StoreTaskRequest
$request)`, so the FormRequest really runs. NO EARLIER GUARD — this was the strongest
refutation candidate and it fails. Line 431's onChange sets `rel_id: ''` when the type
changes, so rel_id is genuinely empty. The target picker (lines 440-447) is an optional
`<button type="button" onClick={() => setPicker('rel')}>` reading "Choose project…" — nothing
auto-opens or forces it. `missingMilestone` (line 300) is `requireMilestone && isProject &&
!!form.rel_id && !form.milestone_id` — the `!!form.rel_id` term means it is false precisely in
this state, so the submit button at line 560 (`disabled={!form.name.trim() || busy ||
missingMilestone}`) is enabled. No required marker: the drawer's own `Field` component (line
634) does render a red `*` for `required`, and it is passed for Milestone (line 449
`required={requireMilestone}`) but not for "Related To" (416) or the target picker (440) —
confirming the asymmetry the claim describes. NO SERVER-SIDE FRIENDLY NAME — searched all of
app/, lang/ and resources/lang/ for `function attributes`, a `messages()` override, and any
validation.php lang file: none exist. StoreTaskRequest extends
`Illuminate\Foundation\Http\FormRequest` directly and defines only
authorize/prepareForValidation/rules (prepareForValidation only defaults start_date, it does
not coerce rel_type to standalone). So Laravel's default ":attribute" = "rel id". NO GENERIC
HANDLING — checked both candidate interceptors. bootstrap/app.php:56 short-circuits
`ValidationException` BEFORE ApiErrorMapper, returning `'message' => 'Validation failed'` plus
raw `$e->errors()` keys — and "validation failed" is in apiError.js's GENERIC_TITLES (line
73), so the title collapses to "Please check 1 field" and the field line is rendered from the
raw `rel_id` key. frontend/src/lib/api.js:29 only handles session-failure 401s and re-rejects
everything else. taskApi.js:10 `create` ends in `.catch(handleErr)`, so the "Rel" label
reaches the user. TWO COSMETIC INACCURACIES IN THE REPRO (they do not affect the defect): the
error surfaces as an inline red banner inside the drawer (onError → `setErr(e.message)` → the
`<p>` at lines 550-552), not a toast — the drawer imports no toast at all; and the button
reads "Submit", not "Create". The message text is identical either way because it is
`e.message`.

</details>

### Task board kanban has neither an error state nor a board-level empty state — a failed load shows five "Drop here" columns

- **Owner:** Shivam · **Module:** tasks · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/tasks/pages/TaskBoard.jsx:82`
- **What it does:**
  The board's task query reads only `data` and `isLoading`; `isError` is never consumed, so a
  failure yields the `[]` default. The kanban view then renders each status column with the
  drag placeholder "Drop here" and nothing else. There is no board-level empty state either,
  so a brand-new workspace and a broken API look identical. With `refetchInterval: 30_000` a
  board left open silently empties itself on the first failed poll.
- **Reproduce:**
  Open /app/tasks in kanban view with /api/tasks failing. After the skeleton clears you see
  five empty columns each reading "Drop here" — no error, no retry, no "no tasks yet" copy.
  Leave a populated board open and kill the API: within 30s every card disappears the same
  way.

```
TaskBoard.jsx:82-87
  const { data: rawTasks = [], isLoading, refetch: refetchTasks } = useQuery({
    queryKey: ['tasks', filters],
    queryFn: () => taskApi.list(filters),
    // Auto-refresh every 30s so a board left open stays current.
    refetchInterval: 30_000,
  })

TaskBoard.jsx:481-483
  {col.length === 0 && !isOverCol && (
    <p className="text-[10px] text-center py-4" style={{ color: 'var(--text-muted)', opacity: 0.6 }}>Drop here</p>
  )}

TaskBoard.jsx:224-226 — the only other branch is the skeleton and the two views; no isError branch exists in the file.
```

<details><summary>What the refutation attempt checked</summary>

Could not refute the core claim. Opened /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/tasks/pages/TaskBoard.jsx: lines 82,
224-226 and 481-483 match the evidence verbatim, and grep for
isError/error/Error/catch/onError over all 728 lines returns zero hits — there is no error
handling anywhere in the file. Ruled out every escape hatch: (1) taskApi.list does
.catch(handleErr) and services/apiError.js handleErr ALWAYS rethrows (documented "Always
throws"), so the query truly rejects and the `= []` destructuring default supplies the empty
board; (2) lib/api.js response interceptor only clears auth on isSessionFailure and otherwise
rejects — no global toast for failed GETs; (3) main.jsx QueryClient defaults are only
retry:1/staleTime/refetchOnWindowFocus, with no throwOnError and no QueryCache.onError, so
with @tanstack/react-query ^5.56.0 the real ErrorBoundary in App.jsx never receives the query
error; (4) the route is live — routes.jsx:184 lazy import, :736 `<Route path="tasks"
element={<S><TaskBoard/></S>}>` where S is bare Suspense (routes.jsx:392); (5) TASK_STATUS has
exactly 5 keys (taskApi.js:131-137) so 5 "Drop here" columns render; (6) default view state is
'kanban'; (7) TaskTable (line 571) does have an empty state ("No tasks match these filters.")
but Kanban (396-490) has none. isLoading clears on error in v5 so the skeleton is replaced by
the empty board. Two overstatements in the report that should be trimmed but do not invalidate
it: the refetchInterval scenario is wrong — React Query preserves the last successful data on
a failed background refetch, so a populated board does NOT empty itself after 30s (only the
initial load failure produces the empty board); and "no retry" is imprecise since ListControls
onRefresh={refetchTasks} at line 197 is a generic refresh button, though not an error-state
retry.

</details>

### Eight Accounts tables fall through to DataTable's generic "No data / Nothing to show yet." for search misses, empty ledgers and failed fetches alike

- **Owner:** Zafar · **Module:** accounts · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/components/ui/DataTable.jsx:33`
- **What it does:**
  DataTable renders a hard-coded fallback when `emptyState` is not supplied. Every Accounts
  call site omits it, so three completely different situations — a search that matched
  nothing, a book with no entries yet, and a query that errored (react-query default `[]`) —
  all produce the same two lines of text with no action button and no way to clear the filter.
- **Reproduce:**
  On /app/accounts/vouchers type "zzzz" into the search box: the table is replaced by "No data
  / Nothing to show yet." with no "clear search" affordance. Post no vouchers at all and you
  get the identical screen. Make the request fail and, because `isError` is not read either,
  you get the identical screen a third time.

```
DataTable.jsx:32-34
  if (!rows || rows.length === 0) {
    return emptyState || <EmptyState title="No data" description="Nothing to show yet." />
  }

Call sites that pass no emptyState (8 of the 10 in the app, all in accounts/):
  Vouchers.jsx:125       <DataTable columns={columns} rows={vouchers} onRowClick={…} />
  Registers.jsx:129      <DataTable columns={columns} rows={rows} onRowClick={…} />
  ChartOfAccounts.jsx:110<DataTable columns={columns} rows={ledgers} />
  Budgets.jsx:57, BankAccounts.jsx:179/253/287, Transfer.jsx:217

Vouchers.jsx:22 confirms a live search field feeds the same table: const [filters, setFilters] = useState({ type: '', status: '', search: '' })
```

<details><summary>What the refutation attempt checked</summary>

Tried and failed to refute. Verified: (1) The quoted code exists verbatim at
frontend/src/components/ui/DataTable.jsx:33-34 (finding says 32-34, off by one) — `if (!rows
|| rows.length === 0) return emptyState || <EmptyState title="No data" description="Nothing to
show yet." />`. (2) No wrapper supplies emptyState: all pages import the same component
directly via `@/components/ui/DataTable` (vite alias '@' -> /src); no DataTable.defaultProps,
no re-export, no shared list wrapper. Only Bills.jsx:284 and Cheques.jsx:298 pass emptyState.
(3) Reachable, not dead code: app/routes.jsx:544 maps `accounts/vouchers` to <Vouchers/>, and
Sidebar.jsx:138 links /app/accounts/vouchers. (4) isError is genuinely never read — grepping
all six pages for isError/isSuccess/error (excluding mutation onError and color literals)
returned zero hits; Vouchers.jsx:30-34 destructures only {data: page, isLoading} and does
`const vouchers = page?.data ?? []`. (5) No generic handling prevents the outcome:
main.jsx:12-20 QueryClient sets only retry:1/staleTime/refetchOnWindowFocus with no queryCache
onError and no throwOnError; lib/api.js:29-40 response interceptor only redirects on auth-
shaped 401 via isSessionFailure and re-rejects everything else with no toast;
services/apiError.js:114 handleErr always throws so data is undefined -> [];
components/ErrorBoundary.jsx wraps the app (App.jsx:10) but react-query defaults
throwOnError:false and it is never overridden, so query errors never reach it. (6) The search
repro is real, not hypothetical: Vouchers.jsx:22 has search in filters, lines 114-115 bind a
live input to it, search is in the queryKey, and backend
app/Services/Accounts/VoucherService.php:32-37 genuinely applies where
number/narration/reference_no LIKE %term%, so "zzzz" returns an empty page and the table
collapses to the generic empty state with no clear-search affordance. ONE CORRECTION: the
count is 7, not 8. BankAccounts.jsx:253 is already guarded by an earlier `rows.length === 0`
check at line 251 that renders a specific message ("No statement lines imported for this
account. Use the Import button..."), so it never reaches the generic fallback. The genuinely
unguarded sites are Vouchers.jsx:125, Registers.jsx:129, ChartOfAccounts.jsx:110,
Budgets.jsx:57, BankAccounts.jsx:179, BankAccounts.jsx:287, Transfer.jsx:217. The three-way
conflation of search-miss / empty-book / failed-fetch holds most sharply on the three pages
that actually have search boxes (Vouchers, Registers, ChartOfAccounts); the error-vs-empty
conflation holds on all seven.

</details>

### Invoices list offers "+ Create first invoice" when a search or status filter matched nothing

- **Owner:** Zafar · **Module:** sales · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/sales/pages/Invoices.jsx:289`
- **What it does:**
  The single empty branch is keyed on `visible.length===0`, where `visible` is the result of
  the server status filter plus the client-side search. A search miss therefore shows the
  never-created copy and its create-first-invoice call to action instead of telling the user
  their filter is too narrow and offering to clear it.
- **Reproduce:**
  Go to /app/sales/invoices with 200 invoices in the system, click the "Overdue" tab, and type
  "zzz" into the search box. The table is replaced by a receipt icon, "No invoices found" and
  a "+ Create first invoice" link — on an account that already has 200 invoices. Nothing tells
  you a filter is active or lets you clear it from there.

```
Invoices.jsx:289-295
  {visible.length===0 && <tr><td colSpan="8" className="py-16 text-center">
    <div className="flex flex-col items-center gap-3">
      <div className="w-14 h-14 rounded-2xl …">🧾</div>
      <p className="text-sm font-semibold" style={{color:'var(--text-muted)'}}>No invoices found</p>
      <button onClick={()=>navigate('/app/sales/invoices/new')} className="text-xs font-bold" style={{color:'#a78bfa'}}>+ Create first invoice</button>
    </div>
  </td></tr>}

Invoices.jsx:44 and :185 show both filters feeding `visible`:
  const [filter, setFilter] = useState('All')
  const { search, setSearch, pageSize, setPageSize, visible, matched } = …

Same single-copy empty branch in CreditNotes.jsx:190 ("No credit notes found."), Proposals.jsx:267, DeliveryNotes.jsx:212, Payments.jsx:203.
```

<details><summary>What the refutation attempt checked</summary>

Verified in source, though the repro overstates the harm. What I checked: 1. Code exists as
quoted. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Invoices.jsx:289-295 is byte-
for-byte the quoted block: a single `{visible.length===0 && ...}` row rendering the receipt
icon, "No invoices found", and a `+ Create first invoice` button. The branch references
neither `search`, nor `filter`, nor `matched`, so it cannot distinguish "no invoices exist"
from "filter matched nothing". 2. `visible` really is post-filter. Line 64 sends the status
tab to the server (`status: filter!=='All'?filter:undefined`); line 185 feeds that result
through `useListView(data, ['number','client','status','reference'])`. I read /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/hooks/useListView.js — `visible` = search-
filtered AND page-size-sliced. So a search miss, and also a pageSize of 0 rows... no, pageSize
is never 0-length, but a search miss does yield `visible.length===0` with `data.length` large.
3. Reachable, not dead code. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/app/routes.jsx:504 routes `invoices` to this
component and :503 routes `invoices/new`, so the CTA target is valid and the page renders. 4.
Looked for a generic mitigation and found a partial one that refutes part of the repro, not
the copy bug. /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/components/ui/ListToolbar.jsx renders directly
above the table and does: keep the typed query in the box, render a "Clear search" X whenever
`search` is non-empty, highlight the active status tab (Invoices.jsx:231-234 paints it with a
purple gradient, 'All' one click away), and print `count`/`total` as "0 of N records" — the
range form renders only when `count !== total`, i.e. exactly when a filter is narrowing. So
the repro's claim that "nothing tells you a filter is active or lets you clear it from there"
is wrong at page level: the user is not stranded and clearing is one click away in the
toolbar. That lowers severity to microcopy, but nothing anywhere swaps the empty-cell text, so
the substantive claim stands: an account with 200 invoices that searches "zzz" is told "No
invoices found" and offered "+ Create first invoice". 5. Sibling claims are mixed.
Proposals.jsx:267-274 ("+ Create your first proposal") and DeliveryNotes.jsx:212-219 ("+
Create first delivery note") match. Payments.jsx:203 keys on `filtered.length===0` rather than
`visible` but has the same single branch and "+ Record first payment". CreditNotes.jsx:190 is
a bare "No credit notes found." with no CTA at all — the "create first" complaint does not
apply there, so that one cite is inaccurate. Verdict: the defect as described in the code is
real and unmitigated; treat it as cosmetic empty-state copy (add a `search || filter!=='All'`
variant), not as a dead-end state.

</details>

### Customers list tells you to import a customer list when your search matched nothing

- **Owner:** Zafar · **Module:** customer · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/customer/pages/Customers.jsx:401`
- **What it does:**
  Search is a server parameter on the same `load()` that fills `rows`, and the only empty
  branch is `rows.length === 0`. Searching for a name that does not exist produces the first-
  run onboarding copy. The same branch is also what a failed load lands on, since the catch
  only toasts and leaves `rows` at `[]`.
- **Reproduce:**
  With hundreds of customers on file, type "qqqq" in the customer search box. After the 250ms
  debounce the table body reads "No customers yet. Click "New Customer" or import a list." —
  no indication that a search is filtering, no clear-search action. Separately, break
  /api/customers: a toast flashes and you land on exactly the same sentence.

```
Customers.jsx:76-84
  const load = () => {
    setLoading(true)
    customerApi.list({ search: search || undefined, per_page: pageSize || 1000 })
      .then(res => { setRows(res.data ?? []); setMeta(res) })
      .catch(e => toast.error(e.message))
      .finally(() => setLoading(false))
  }

Customers.jsx:88
  useEffect(() => { const t = setTimeout(load, 250); return () => clearTimeout(t) }, [search, pageSize])

Customers.jsx:400-401
  ) : rows.length === 0 ? (
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute it and could not. Opened /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/customer/pages/Customers.jsx in full (773
lines): the quoted code is there verbatim — load() at 76-84 passes `search: search ||
undefined` to customerApi.list and sets rows only inside .then; the debounce effect at 88 re-
runs it on [search, pageSize]; the tbody at 398-402 has exactly two branches, loading ?
skeleton : rows.length === 0 ? "No customers yet. Click “New Customer” or import a list." :
rows.map(...). No separate no-results branch exists anywhere in the file. Checked the places a
fix could be hiding: (1) Search is genuinely a server parameter, not client-side filtering —
services/customerApi.js:61-62 does api.get('/customers', { params }), and backend
app/Http/Controllers/Api/Customer/ClientController.php:44 whitelists 'search' into the query,
so a non-matching term returns an empty data array and rows becomes []. (2) The page is routed
and live — app/routes.jsx:531-532 renders <Customers /> as the index of /app/customers, and
modules/customer/CustomerLayout.jsx is a bare <Outlet />, so no parent supplies an empty
state. (3) Nothing handles it generically — lib/api.js's response interceptor only force-logs-
out on session-shaped 401s and rethrows everything else; services/apiError.js handleErr always
throws; there is no error boundary or shared list wrapper rendering the table body. (4) The
failure path is as described: the catch only calls toast.error and never touches rows, so on a
first or failed load rows stays [] and finally clears loading, landing on the identical
sentence. One detail of the repro is wrong but does not save the finding:
components/ui/ListToolbar.jsx does render a "Clear search" X button inside the search box
whenever search is non-empty, so a clear-search affordance exists. The count beside it still
reads a plain "0 customers" (showRange is false because the paginator total is also 0 on a
filtered miss), so nothing indicates a filter is active, and the empty-state copy still tells
a tenant with hundreds of customers to import a list. The fix is the missing branch:
distinguish search ? "No customers match …" from the onboarding copy, plus an error flag so a
failed load does not read as an empty database.

</details>

### Vendor detail replaces the entire page — header, status bar and tabs — with a bare spinner after every action

- **Owner:** Harshal · **Module:** tpv · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/tpv/pages/TpvVendorDetail.jsx:165`
- **What it does:**
  `load()` sets `loading` true and the component returns only a centred spinner while it is
  true. Because `load()` is re-invoked after every write (suspend, reinstate, offboard, admin
  decision, resend activation), the whole 1000-line page — vendor header, status chips, tab
  bar and the open tab's contents — vanishes and remounts on each action, discarding the
  collapsed-section state and forcing every tab panel to refetch.
- **Reproduce:**
  Open /app/tpv/vendors/42, expand a few document groups on the Documents tab, then click
  Suspend and confirm. The entire page blanks to a single purple spinner, then reassembles:
  the header flashes, the layout jumps, and every group you expanded is collapsed again. Same
  on Reinstate, Offboard, and the approve/reject/hold decision modal.

```
TpvVendorDetail.jsx:158-165
  const load = useCallback(() => {
    setLoad(true)
    cfg.api.vendors.get(id).then(r => { setV(r?.data ?? r); setLoad(false) }).catch(() => setLoad(false))
  }, [id, cfg.api])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={wrap}><style>{KIT3D_STYLE}</style><Loader2 size={22} className="rfq-spin" style={{ color: '#a78bfa' }} /></div>

Callers that re-trigger the full-page spinner:
  :130 load()   (admin decision)
  :142 try { await cfg.api.vendors.suspend(id, reason.trim()); load() }
  :147 try { await cfg.api.vendors.reinstate(id); load() }
  :155 try { await cfg.api.vendors.offboard(id, reason.trim()); load() }
```

<details><summary>What the refutation attempt checked</summary>

Opened TpvVendorDetail.jsx and confirmed the core mechanism verbatim. Lines 159-163 are
exactly as quoted (setLoad(true) inside load(), useEffect(() => load(), [load])), and the
full-page early return `if (loading) return <div style={wrap}>...<Loader2/></div>` is at
exactly :165. All five cited load() call sites exist at exactly :130, :142, :147, :155, :201,
plus a sixth the auditor missed: onReload={load} (:385) is handed to ProfilePanel as onSaved
(:446), so a profile save blanks the page too. Tried to refute on five fronts, all failed: (1)
Reachability - app/routes.jsx:298 lazy-imports the component and :667 mounts it at view/:id,
so it is live, not dead code. (2) Guard preventing the state - the action buttons are really
wired (:248 resend, :254 suspend, :260 reinstate, :266 offboard) behind canManageTpv = role
'admin' or 'staff' (modules/tpv/constants.js:139), an ordinary path. (3) Memoization saving
the subtree - no React.memo anywhere in the file; the early return yields a completely
different tree, so every child unmounts. (4) Children not actually refetching - SectionContent
(:432) renders VendorOverview/VendorCustomers/VendorMeetingsPanel etc. as children, so they do
remount and re-run their fetch effects. (5) An unstable-dep escape hatch - checked
modules/tpv/useVendorModule.js: cfg() returns a fresh object per render but `api` is a module-
level singleton import (tpvApi), so cfg.api is referentially stable and load does not loop;
that confirms rather than prevents the behaviour. Two supporting details in the finding are
WRONG and must not reach the developer as written. (a) "Discarding the collapsed-section
state" is false: `collapsed` (:98) is useState on TpvVendorDetail ITSELF, and the early return
is a render-time branch, so React keeps the instance mounted and the hook state intact.
collapsed, v, notice, remarks and showTimeline all survive the spinner. The auditor cited the
one piece of state that is not lost. (b) "Document groups on the Documents tab" is false:
collapsed is read only at :335/:339 and drives the LEFT SIDEBAR nav-group accordion (5 groups
/ 35 sections); default {} means groups start expanded, so the repro's "expand a few groups,
they collapse again" describes an unreachable state. The repro URL is also wrong - viewPath is
/app/tpv/view/${id}, not /app/tpv/vendors/42. Minor: the :130 and :201 labels are swapped
(:130 is resendActivation, :201 is handleAdminDecision). Marked real because the headline
outcome - every write action replaces header, status chips, sidebar nav and tab content with a
bare centred spinner, and every tab panel refetches - is exactly what the code does, with
nothing preventing it and a genuine fix available (skip setLoad(true) on refresh, or use a
separate refreshing flag). The trip is not wasted, but the fabricated "collapsed state is
lost" symptom should be dropped from the ticket.

</details>

### Kickoff meeting registry swallows its load error and shows the first-run empty state; quick-view filters reuse the same copy

- **Owner:** Shivam · **Module:** meetings/kickoff · **Dimension:** States: loading, empty, error
- **Where:** `frontend/src/modules/shared/pages/KickoffMeetings.jsx:48`
- **What it does:**
  Two defects on one screen. (1) `Promise.all(...).catch(() => setLoad(false))` discards the
  error, so a failed load leaves `data` at `[]` and `stats` at null and the page renders "No
  kickoff meetings yet / Schedule a pre-onboarding meeting…" plus a Schedule button. (2) The
  empty state only varies on the server `filter`; the client-side quick views (my / upcoming /
  pending_mom / open_actions) and the project rollup are applied after the fetch, so narrowing
  by those and matching nothing also shows the never-created copy.
- **Reproduce:**
  (1) Open /app/tpv/kickoff with /api/kickoff failing — the skeleton clears and you get "No
  kickoff meetings yet" with a Schedule meeting button, on a tenant that has dozens of
  meetings. (2) With meetings loaded, switch the quick view to "Open actions" when none have
  open actions: you get "No kickoff meetings yet — Schedule a pre-onboarding meeting with a
  vendor to get started" instead of "no meetings have open actions".

```
KickoffMeetings.jsx:42-49
  const load = () => {
    setLoad(true)
    Promise.all([
      kickoffApi.list(filter === 'All' ? {} : { status: filter }),
      kickoffApi.dashboard(),
    ]).then(([rows, s]) => { setData(rows?.data ?? rows); setStats(s); setLoad(false) })
      .catch(() => setLoad(false))
  }

KickoffMeetings.jsx:70-72 — quick views are applied client-side, after the fetch:
  const rows = data
    .filter(m => projectF === 'All' || String(m.project_id) === String(projectF))
    .filter(quickMatch)
```

<details><summary>What the refutation attempt checked</summary>

Opened /home/zafar-
farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/shared/pages/KickoffMeetings.jsx (1171
lines) and every quoted fragment is present verbatim at the cited lines. What I verified: -
Lines 41-48: `load()` is exactly as quoted, ending `.catch(() => setLoad(false))`. Nothing
else is set in the catch — `data` stays `[]`, `stats` stays `null`. - Lines 70-72: `rows` is
`data` narrowed client-side by `projectF` then `quickMatch`, after the fetch. Quoted
correctly. - Line 247-255: the render ladder is `templates ? … : loading ? skeleton : calendar
? … : rows.length === 0 ? <EmptyState filter={filter} onNew={…}/>`. Only `filter` is passed —
`quickView` and `projectF` are not. - Lines 428-443: `EmptyState` keys solely on `filter` for
the heading, the body copy "Schedule a pre-onboarding meeting with a vendor to get started."
is unconditional, and the "Schedule meeting" button renders whenever `filter === 'All'` (the
default, and the state a user is in when they change only the quick view). Confirms both
halves of the repro. Refutation attempts that failed: - Local error UI exists but is unused by
the load path. The component already has `banner`/`setBanner` (line 29) and renders a red
AlertTriangle error banner at lines 160-166. `grep -n banner` shows only three hits: the
state, and the banner JSX. It is set by `handlePdf` (line 94) but never by `load`'s catch. So
the display mechanism is present on the same screen and simply not wired to the failing fetch
— this is a gap, not an intentional design with an alternative surface. - No global toast or
axios error surface. `kickoffApi` uses `@/lib/api`; its response interceptor
(lib/api.js:29-39) only calls `isSessionFailure` to clear auth and redirect to /auth/login,
then re-rejects. A 500/timeout on /api/kickoff is passed straight to the caller with no user-
visible output. Searched for `interceptors.response` across src — the other three are separate
axios instances (purchaseVendorApi, clientPortalApi, hrApi) not used by this page. No
`Toaster`/`ToastProvider`/`unhandledrejection` in App.jsx or main.jsx. - The React
ErrorBoundary (App.jsx:10, components/ErrorBoundary.jsx) is a render-error boundary; a caught
promise rejection never reaches `componentDidCatch`, so it does not cover this. - The file is
live, not dead code. routes.jsx:340 lazy-imports `@/modules/shared/pages/KickoffMeetings` and
line 668 mounts it as `<Route path="kickoff">` under the `path="tpv"` parent, i.e.
/app/tpv/kickoff exactly as the repro states. (The Purchase rail uses a separate component,
PurchaseKickoffMeetings, so this is not a mix-up.) - No earlier guard blocks the state. The
quick-view tabs at lines 169-187 are ordinary buttons calling `setQuickView`, reachable by any
user, and `setLoad(false)` in the catch guarantees the skeleton clears into the empty branch.
One partial mitigation worth passing on, which does not refute the finding: each quick-view
tab renders a live count badge (lines 170-175), so "Open Actions" visibly shows `0`. That
gives a hint but leaves the misleading heading, the "Schedule a pre-onboarding meeting…" body
copy, and the Schedule button in place. Defect (1) — the silently swallowed load error — is
the substantive half; defect (2) is a real but lower-severity copy issue.

</details>

## Low (3)

### "Explore" call-to-action at the bottom of every auth-page marketing panel has no handler

- **Owner:** mixed · **Module:** auth · **Dimension:** Dead and broken interactions
- **Where:** `frontend/src/pages/auth/LoginPage.jsx:170`
- **What it does:**
  The What's-new / product panel on the login screen and on all three registration screens
  ends with a coloured, underlined "Explore …" button. None of the four has an onClick, a
  type, or an enclosing form — they are text styled to look like links.
- **Reproduce:**
  Open /auth/login → the "What's new in Sangoe" card header shows "Explore →" in purple →
  click it, nothing happens. Repeat on /auth/register/vendor ("Explore Updates —"),
  /auth/register/tpv ("Explore Solutions —") and /auth/register/client ("Explore Updates →").
  Four occurrences, same defect.

```
LoginPage.jsx:170 `<button className="text-xs font-semibold" style={{ color: '#a78bfa' }}>Explore →</button>`
pages/auth/VendorRegisterPage.jsx:139 `<button className="mt-4 text-xs text-blue-400 hover:underline text-center">Explore Updates —</button>`
pages/auth/TPVRegisterPage.jsx:134 `<button className="mt-4 text-xs text-blue-400 hover:underline">Explore Solutions —</button>`
pages/auth/ClientRegisterPage.jsx:139 `<button className="mt-4 text-xs text-teal-400 hover:underline">Explore Updates →</button>`
All four scanned with a balanced-tag parse: no onClick, no onMouseDown, no type="submit", no spread props, and none sits inside a <form>.
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute on six angles; all failed. (1) Code exists verbatim. `grep -n "Explore"`
returns exactly the four quoted lines at the exact line numbers claimed: LoginPage.jsx:170
`<button className="text-xs font-semibold" style={{ color: '#a78bfa' }}>Explore →</button>`,
VendorRegisterPage.jsx:139, TPVRegisterPage.jsx:134, ClientRegisterPage.jsx:139 — each a bare
`<button>` with only className/style, no onClick, no type, no spread props. (2) Not dead code.
All four are routed in frontend/src/app/routes.jsx (login:414 wrapped in GuestRoute,
register/vendor:416, register/tpv:417, register/client:418), imported from '@/pages/auth/...'
at lines 8/10/11/12, and `find` shows only one LoginPage.jsx in the tree — no superseding
duplicate. (3) Not inside a form. Each page's only `<form>` opens well after the button
(LoginPage form at 213 vs button at 170; Vendor 186 vs 139; TPV 178 vs 134; Client 180 vs
139), so the buttons are in the left marketing panel, outside the form — confirming no
implicit-submit behavior either. (4) No global click delegation. Repo-wide grep for
`addEventListener('click'` / `e.target.closest` finds only components/ui/MediaLightbox.jsx:75
and components/editor/InlineMentions.jsx:111, neither mounted on auth pages nor generic. (5)
No wrapping anchor/Link — read the raw surrounding lines; parents are plain `<div>`s. (6)
Reachable by a real user: panels are `hidden md:flex` (LoginPage:115) and `hidden lg:flex`
(the three register pages), so they render on desktop, which is the primary auth viewport.
Corroborating intent signal: every genuinely interactive button in these same files explicitly
carries `type="button"` and `onClick` (e.g. LoginPage:219,236,277), so the four Explore
buttons are a consistent omission, not a house style. Scope note for the fixer: this is a
cosmetic dead-affordance (element looks clickable via purple/teal color and `hover:underline`,
does nothing) — no data loss or broken flow — but the claim as written is accurate on all four
counts.

</details>

### `after_or_equal` date ordering rules exist only on the server — the date inputs carry no `min`, so a backwards range is a round-trip 422

- **Owner:** Shivam · **Module:** projects / tasks · **Dimension:** Validation, both sides
- **Where:** `frontend/src/modules/projects/components/ProjectFormDrawer.jsx:396`
- **What it does:**
  `StoreProjectRequest` enforces `deadline => after_or_equal:start_date` and
  `StoreTaskRequest` enforces `due_date => after_or_equal:start_date`, but both date pickers
  are plain `<input type="date">` with no `min` bound to the start date and no client
  comparison before submit. The user only learns the order is wrong after the round trip. (The
  HR interview drawer does this correctly — ScheduleInterviewDrawer.jsx:229 — so the pattern
  is known in the codebase.)
- **Reproduce:**
  Projects → New Project. Set Start date to 2026-09-01 and Deadline to 2026-08-01 (the picker
  offers both freely). Click Create Project. The request round-trips and comes back as a
  validation toast instead of the deadline picker simply refusing dates before the start date.
  Same on Tasks → New Task with Start date and Due date.

```
frontend projects/components/ProjectFormDrawer.jsx:395-396 —
```
<Field label="Deadline">
  <input type="date" value={form.deadline} onChange={e => sf('deadline', e.target.value)} className={INPUT} style={INPUT_S} />
```
backend StoreProjectRequest.php:36 — `'deadline'            => 'nullable|date|after_or_equal:start_date',`
frontend tasks/components/TaskFormDrawer.jsx:386 — `<input type="date" value={form.due_date || ''} onChange={e => sf('due_date', e.target.value)} className={INPUT} style={INPUT_S} />`
backend StoreTaskRequest.php:47 — `'due_date'          => 'nullable|date|after_or_equal:start_date',`
```

<details><summary>What the refutation attempt checked</summary>

Verified, could not refute. (1) Quoted code is exact: ProjectFormDrawer.jsx:393/396 and
TaskFormDrawer.jsx:383/386 are bare <input type=\"date\"> with only
value/onChange/className/style — no `min`. Backend rules confirmed at
StoreProjectRequest.php:37 ('deadline' => 'nullable|date|after_or_equal:start_date'; the audit
cited :36, which is start_date — a harmless off-by-one) and StoreTaskRequest.php:47
(due_date). (2) Searched for the guard elsewhere and found none: both submit handlers
(ProjectFormDrawer.jsx:224-245, TaskFormDrawer.jsx:271-291) only strip/normalize payload keys
before save.mutate — no date comparison; the shared `Field` component
(ProjectFormDrawer.jsx:558) is a plain <label> wrapper rendering {children} with no
cloneElement, so it cannot inject min; I enumerated every useEffect in both drawers
(Project:99, Task:109/192) and they are open-hydration and a relations loader, none clamps
deadline/due_date when start_date changes. (3) Not dead code — ProjectFormDrawer is rendered
from projects/pages/ProjectList.jsx:293, projects/pages/ProjectDetail.jsx:306,
tpv/components/VendorProjectsPanel.jsx:81, purchase/pages/vendor-
detail/vendorDetailTabs.jsx:716; TaskFormDrawer from tasks/pages/TaskBoard.jsx:239,
tasks/pages/TaskDetail.jsx:746, projects/pages/ProjectDetail.jsx:311,
tpv/pages/TpvVendorDetail.jsx:534, sales/pages/SalesTasks.jsx:113. (4) No generic mechanism
prevents it — each drawer's own onError sets an inline red banner
(ProjectFormDrawer.jsx:520-522), which is the round-trip failure being described, not a
prevention. (5) Evidence the auditor missed that strengthens the finding: the correct pattern
already exists inside the projects module itself — the milestone drawer at
projects/pages/ProjectDetail.jsx:773 uses min={f.start_date || undefined} plus an inline
dateError message, so the same module handles the identical date-pair correctly in one drawer
and not the other (repo-wide only 7 of 222 type=\"date\" inputs carry min). Notably
TaskFormDrawer.jsx:300 already pre-empts a different server rule client-side
(`missingMilestone`, commented \"the same rule the server enforces, surfaced before the round-
trip\"), showing pre-flight validation was applied selectively and skipped for the date
ordering. Real but low severity: a UX/polish gap, not a correctness or security bug — the
server still rejects the bad range.

</details>

### TPV worker email is validated as a free string on the server and only by an inert `type="email"` on the client

- **Owner:** Harshal · **Module:** tpv · **Dimension:** Validation, both sides
- **Where:** `backend/app/Http/Requests/Tpv/StoreTpvWorkerRequest.php:31`
- **What it does:**
  `StoreTpvWorkerRequest` and `UpdateTpvWorkerRequest` are the only two requests in the
  codebase that type an `email` field as `nullable|string` instead of `nullable|email` — every
  other module uses `email`. The form's only guard is `type="email"`, which the browser checks
  on native form submission; the worker drawer saves via an onClick handler, not a form
  submit, so nothing checks it on either side and malformed addresses land in the workforce
  register.
- **Reproduce:**
  TPV → Workers → Add Worker. Type `raju@` (or `not an email`) into the Email field, fill the
  name, save. The worker is created and the register shows the broken address as if it were
  valid; nothing rejects it at any layer.

```
backend StoreTpvWorkerRequest.php:31 — `'email'             => 'nullable|string',`  (identical at UpdateTpvWorkerRequest.php:31; contrast StorePurchaseWorkerRequest.php:104 — `'email'           => 'nullable|email|max:150',`)
frontend tpv/pages/TpvWorkers.jsx:814 — `<Field label="Email"><TextInput type="email" value={f.email} onChange={set('email')} placeholder="worker@email.com" /></Field>`
```

<details><summary>What the refutation attempt checked</summary>

Tried to refute at every layer and failed; all three primary evidence citations are exact.
BACKEND — quoted code exists verbatim. `StoreTpvWorkerRequest.php:31` and
`UpdateTpvWorkerRequest.php:31` both read `'email' => 'nullable|string',`. I read both files
in full: they contain only `authorize()` (returns true) and `rules()` — no
`prepareForValidation`, no `withValidator`, no `after` hook that could add an email rule. Not
dead code. `StoreTpvWorkerRequest` is type-hinted in `TpvWorkerController::store` (line 32)
and `VendorPortalController::storeWorker` (line 475); `UpdateTpvWorkerRequest` in
`TpvWorkerController::update` (line 76) and `VendorPortalController::updateWorker` (line 506).
Routes are live: `routes/tpv.php:170` (`POST /workers`) and `:174` (`PUT /workers/{worker}`).
The controller passes `$request->validated()` straight to the service. No downstream guard.
`TpvWorkerService::create` only calls `assertVendor()` and `assertAadharUnique()` before
`TpvWorker::create([...$data])`; `update()` only checks `isEditable()`, vendor, and Aadhaar
uniqueness. Grepping `email` across `TpvWorkerService.php` and `TpvWorker.php` returns exactly
one hit — the `$fillable` entry. The model's `booted()` hook only auto-generates
`worker_code`. No observer is registered for `TpvWorker` in any provider, and there is no
`Validator::extend`, sanitizing middleware, or `FILTER_VALIDATE_EMAIL` anywhere in
`app/Providers` or `app/Http/Middleware`. Migration
`2026_07_24_100000_enhance_tpv_workers_for_full_ref.php:12` makes it a plain nullable `string`
column — no DB-level constraint either. The "only two requests" claim holds. Grepping every
FormRequest for an `email` key whose rule string lacks the `email` rule returns exactly those
two lines and nothing else. FRONTEND — `type="email"` is genuinely inert here.
`TpvWorkers.jsx:814` matches the quote character-for-character. The file contains no `<form>`,
no `type="submit"`, no `checkValidity()`/`reportValidity()` anywhere (grep returns zero hits),
so native constraint validation never fires. `Overlay` (kit3d.jsx:68) renders plain `<div>`s
via a portal; `ModalFooter` (kit3d.jsx:93) renders bare `<button onClick={onConfirm}>`;
`TextInput` (kit3d.jsx:116) is a bare `<input {...props}>` that adds nothing. The save path is
`CreateModal.create` (TpvWorkers.jsx ~line 753), a plain async function whose manual guard
list covers vendor_id, name, gender, dob, age_reason, designation, and skill_category — email
is absent. `ModalFooter`'s `disabled` prop likewise omits email. `tpvApi.workers.create`
(tpvApi.js:240) posts the payload with no validation. So `raju@` passes client and server and
is persisted. One minor citation nit that does not affect the finding: the contrast rule is at
`StorePurchaseWorkerRequest.php:21`, not :104, though the quoted text `'email' =>
'nullable|email|max:150',` is exact. Worth noting the TPV rule also lacks a `max:` bound that
every comparable request has.

</details>

---

## Refuted (3) — do not re-report

### Purchase Workforce vendor filter can never list more than 200 vendors
- `frontend/src/modules/purchase/pages/PurchaseWorkforce.jsx`
  REFUTED — the frontend line exists as quoted, but the `per_page: 200` it sends is silently
  ignored by the endpoint it actually hits, which never paginates at all. What I checked: 1.
  `frontend/src/modules/purchase/pages/PurchaseWorkforce.jsx:37-41` — the quoted code is
  verbatim: `purchaseApi.vendors.list?.({ per_page: 200 }).then(d => setVendors(d?.data ?? d
  ?? []))`. So the quote is accurate. 2. `frontend/src/services/purchaseApi.js:162` —
  `vendors.list: (params = {}) => api.get('/purchase/vendors', { params })`. Note the path:
  `/purchase/vendors`, NOT the shared `/vendors`. The file's own comment at line 160 says
  "Fully independent of the shared /vendors table and of TPV." 3. The auditor's backend
  evidence, `backend/app/Repositories/Vendor/VendorRepository.php:71-76` (the `min(..., 200)`
  ceiling), belongs to the **shared** `Vendor` model / `vendors` table, routed in
  `backend/routes/vendors.php` under the `vendors` prefix. That repository is never reached by
  this page. Wrong file. 4. The real path: `backend/routes/purchase.php:161` →
  `PurchaseVendorController::index`. Read
  `backend/app/Http/Controllers/Api/Purchase/PurchaseVendorController.php:56-61`: return
  response()->json( $this->vendors->list($request->user()->tenant_id,
  $request->only(['status', 'category', 'vendor_type', 'search'])) ); `$request->only([...])`
  whitelists four keys — `per_page` is **discarded before it ever reaches the service**. 5.
  `backend/app/Services/Purchase/PurchaseVendorService.php:30` — `public function list(int
  $tenantId, array $filters): Collection` — return type is `Collection`, so pagination is
  structurally impossible here. 6.
  `backend/app/Repositories/Purchase/PurchaseVendorRepository.php:16-40` — `filtered():
  Collection` ends with an unconditional `return $query->latest()->get();`. There is no
  `per_page` branch, no `paginate()`, no cap. 7. `grep -rn "per_page"` across all three
  purchase-vendor files (controller, service, repository) returns **zero hits** (exit 1). The
  string does not exist in the stack. 8. Checked `frontend/src/lib/api.js` for an axios
  interceptor that might inject/rewrite params — no `params` handling at all. Consequence for
  the REPRO scenario: with 350 purchase vendors, `GET /api/purchase/vendors?per_page=200`
  returns a bare JSON array of all 350 tenant vendors. The frontend's `d?.data ?? d ?? []`
  correctly unwraps a bare array (no `.data` key → falls through to `d`), so `setVendors`
  receives all 350 and the `<select>` renders 350 `<option>`s. The described 200-entry
  truncation does not occur. The `per_page: 200` argument is dead/harmless — arguably a
  leftover, but it causes no defect.

### Customer billing address is mandatory in the UI only — an imported customer without one can never be edited or re-saved
- `frontend/src/modules/customer/pages/Customers.jsx`
  Opened every file cited. The quoted code is real and verbatim: /home/zafar-
  farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/customer/pages/Customers.jsx:155-156
  has the billing stepError, :181-187 has the save() loop re-running non-optional steps in
  edit mode (only 'Customer Admins' skipped), STEPS at :22-27 marks 'Billing & Shipping' non-
  optional, and StoreClientRequest.php:41-43 / UpdateClientRequest.php declare
  billing_street/city/state nullable. The premise is also real:
  ClientImportExportService::import()
  (backend/app/Services/Customer/ClientImportExportService.php:63) only rejects rows missing
  `company`, so billing-less clients do get created, and LeadService.php:312 copies a
  possibly-null lead address into billing_street. What refutes the finding is the second,
  primary edit path the auditor did not open. CustomerDetail.jsx has its own full editable
  profile: TAB_GROUPS lists ['Profile', Building2] under the 'Customer Admin' group
  (CustomerDetail.jsx:80-81), line 349 renders `<ProfileTab client={client}
  reload={loadClient} toast={toast} />`, and ProfileTab (line 406) seeds every field including
  phone and all billing/shipping fields (seed() at 421, with `client.billing_street || ''`
  coercion) and its save() at line 477 validates exactly one thing: ``` const save = async ()
  => { if (!form.company.trim()) { setSub('Customer Details'); return toast.error('Company
  name is required') } ``` then posts straight to customerApi.update. No billing check
  anywhere in that path. So an imported/lead-converted client with no billing address can be
  opened in Customer Detail, have its phone changed, and be saved — the claim "can never be
  edited or re-saved" is false, and the stated repro ("Customer Detail → Edit → change phone →
  Save → toast 'Billing address … is required'") does not reproduce, because Customer Detail's
  Save never emits that toast. I also grepped Customers.jsx for `?edit=`/useSearchParams and
  found none, so Customer Detail does not deep-link back into the strict list drawer; the only
  way to reach the blocking code is the pencil icon in the customer list (Customers.jsx:426 →
  openEdit). Two further inaccuracies in the evidence: for a truly billing-less client the API
  returns raw model nulls (ClientController::show returns
  `$this->clients->show(...)->toArray()`, and Customers.jsx:116 spreads `...EMPTY, ...full` so
  null overwrites ''), meaning stepError would throw a TypeError on `null.trim()` rather than
  produce the described toast — so the symptom as written is not what the code does either.
  Net: the list-drawer Edit is stricter than the API, which is a defensible UX nit worth at
  most a polish ticket, but the filed defect (uneditable customer, phone change impossible,
  specific repro and toast) is wrong — a supported, routed edit path saves the record without
  a billing address.

### Sales Item form rejects a rate of 0 that the server explicitly allows, so free / zero-priced items cannot be created
- `frontend/src/modules/sales/pages/Items.jsx`
  REFUTED — the described repro does not reproduce; the guard never fires for a rate of 0.
  What I opened and verified: - /home/zafar-
  farooque/Desktop/sangoe_crm/CRM/frontend/src/modules/sales/pages/Items.jsx:70-71 — the
  quoted code exists verbatim: `if(!form.name||!form.rate) return showToast('Name & rate
  required','error')`. - /home/zafar-
  farooque/Desktop/sangoe_crm/CRM/backend/app/Http/Requests/Sales/StoreItemRequest.php:20 —
  `'rate' => 'required|numeric|min:0',` exists verbatim. - The page is live, not dead code:
  frontend/src/app/routes.jsx:91 lazy-imports this exact file and line 508 mounts it at
  `sales/items`. Why the finding is wrong — the rate value is a STRING, never the number 0: -
  Items.jsx:257 is the rate input: `<input type="number" min="0" value={form.rate} onChange={e
  => sf('rate', e.target.value)} />`. It writes `e.target.value`, and `HTMLInputElement.value`
  is always a DOM string — `type="number"` does not hand a JS number to onChange (that would
  be `valueAsNumber`, which is not used here). Typing 0 therefore stores the string `'0'`, and
  `!'0'` is `false`. I confirmed with node: for `form = {name:'Free onboarding session',
  rate:'0'}` the guard expression evaluates to `false`, so the toast is not shown and
  `salesApi.items.create(form)` runs. - The auditor's own DEFECT text concedes this ("the
  string '0' … is truthy as a string") and then asserts, without basis, that "the field is
  cleared to 0 by the number input in practice". That premise is false on two counts:
  `min="0"` makes 0 a valid value so the browser does not clear it, and even a cleared field
  yields `''` (a genuinely blank rate), not the number 0. I also checked the edit path, the
  only other way `form.rate` gets populated: - Items.jsx:66 `openEdit` copies `rate:
  item.rate` straight from the API response. backend/app/Models/Sales/SalesItem.php casts
  `'rate' => 'decimal:2'`, and Laravel's decimal cast serializes to a JSON string — a zero-
  rate item arrives as `"0.00"`, which is also truthy. So re-saving an existing zero-rate item
  is not blocked either. - No wrapper intercepts save: frontend/src/services/itemApi.js is a
  thin axios POST/PUT to `/sales/items` with no coercion, and Items.jsx:42 `sf` does no
  Number() conversion for `rate` (it does for `tax_rate`/`tax_rate_2`, lines 269/275, but not
  rate). The only input that trips this guard is an empty rate field, which is the intended
  behavior and matches the server's `required` rule. A zero-priced catalogue item can be
  created from the UI today.

