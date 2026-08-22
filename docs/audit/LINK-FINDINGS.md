# Whole-app audit — broken links and dead destinations

From the first batch (link contracts / API contracts / tenancy). Every
entry below was adversarially verified before being recorded. The run was
cut short, so this is a partial result — the tenancy lens did not finish.

**Same class as the four already fixed in Customer:** a path is produced on
one side and nothing valid sits on the other. A route rendering `ComingSoon`
counts as broken — it tells the user the feature does not exist.


## CRITICAL

### Global user-menu "My Profile" navigates to a route that does not exist — hard 404

- **Owner:** mixed  ·  **Module:** settings / shared layout
- **Where:** `frontend/src/components/layout/Header.jsx:198`
- **What the user sees:** Every authenticated user, on every page. Clicking the avatar → "My Profile" replaces the entire app (sidebar, header and all) with the bare full-screen 404 page. The only way back is the "Go to Dashboard" link, so the user loses their place.
- **Fix:** Either add `<Route path="profile" element={<S><ProfileSettings /></S>} />` under the settings layout (a self-service profile screen does not exist yet anywhere in the app), or repoint Header.jsx:198 at an existing screen — `/app/settings/security` is the closest, since it already owns password change. Do not leave it pointing at a non-route.

```
Header.jsx:198 — `action: () => { navigate('/app/settings/profile'); ... }`. The settings branch in app/routes.jsx:771-787 declares index→general, general, mail, custom-fields, company, tax-rates, expense-categories, account-groups, localization, currency, numbering, email-templates, upload, security, notification-preferences, statuses. There is no `profile` child and no `path="*"` anywhere inside /app, so the only match is the top-level catch-all at routes.jsx:916. Browser-verified with a seeded staff token: GET /app/settings/profile renders body text "404 Page not found Go to Dashboard".
```


## HIGH

### TPV vendor activation and approval emails link to /vendor-portal/login, which 404s

- **Owner:** Harshal  ·  **Module:** tpv / notifications
- **Where:** `backend/app/Services/Tpv/TpvActivationNotifier.php:173`
- **What the user sees:** An external third-party vendor who has just been activated or approved receives an email whose one call-to-action ("Access Portal" / portalUrl) lands on a 404 page. The vendor cannot reach the portal from the email at all — they have to be told out-of-band that the real login is /auth/login. This is the first thing a brand-new vendor sees.
- **Fix:** Change all four call sites to FrontendUrl::to('/auth/login') (the actual TPV sign-in screen), or add `<Route path="/vendor-portal/login" element={<Navigate to="/auth/login" replace />} />` as a top-level sibling so the URL already in the wild keeps working. Note this is different from /purchase-portal/login, which is a real declared route (routes.jsx:847) — the two portals were wired inconsistently.

```
Four emitters: TpvActivationNotifier.php:173 `'portalUrl' => FrontendUrl::to('/vendor-portal/login')`; TpvOnboardingService.php:566; Vendor/VendorService.php:218 ("Access Portal: " . FrontendUrl::to('/vendor-portal/login')); and the docblock example in Support/FrontendUrl.php:42. In app/routes.jsx:808-845 `/vendor-portal` is a layout route guarded by ProtectedRoute roles=['vendor','third_party_vendor'] whose children are index, dashboard, registration, support, onboarding, onboarding/:id, workforce/*, documents, orders/:id, invoices/:id — there is no `login` child, and React Router does not partially match a layout route, so the branch fails and the top-level `*` renders. LoginPage.jsx:86 confirms the real entry point: a third_party_vendor signs in at /auth/login and is then pushed to /vendor-portal/dashboard. Browser-verified: /vendor-portal/login renders "404 Page not found".
```

### Mobile bottom navigation: two of four primary tabs and one More item open ComingSoon stubs

- **Owner:** mixed  ·  **Module:** shared layout / navigation
- **Where:** `frontend/src/components/layout/MobileBottomNav.jsx:11`
- **What the user sees:** On any phone-width viewport the persistent bottom tab bar offers Dashboard / Contacts / Deals / Tasks / More. Half the primary tabs (Contacts, Deals) and Tickets inside More land on "🚧 … This module is under construction." A mobile user's main navigation is half dead.
- **Fix:** Repoint the tabs at the screens that exist: Contacts → /app/customers, Tickets → /app/helpdesk/tickets. Deals has no built equivalent — replace that tab with /app/sales/leads (the live pipeline screen) or drop it. Then delete the /app/contacts, /app/deals and /app/tickets ComingSoon routes (routes.jsx:724-729, :771) so nothing else can point at them; with MobileBottomNav fixed, nothing in the codebase emits those paths.

```
MobileBottomNav.jsx:11 `{ label: 'Contacts', path: '/app/contacts' }`, :12 `{ label: 'Deals', path: '/app/deals' }`, :20 `{ label: 'Tickets', path: '/app/tickets' }`. Those three routes are declared as ComingSoon stubs at app/routes.jsx:724, :727 and :771. AppShell.jsx:68 renders <MobileBottomNav /> unconditionally (the component hides itself with `md:hidden`). Browser-verified at 390x800: the bar computes to display=flex with text "Dashboard Contacts Deals Tasks More"; programmatically tapping Contacts navigates to /app/contacts and main renders "🚧 Contacts This module is under construction." Note the app already has real equivalents — /app/customers (Customer Directory) and /app/helpdesk/tickets — so this is mis-wiring, not a missing feature.
```

### Task detail "related to" link for TPV-vendor tasks uses /app/tpv/vendors/{id}, which 404s

- **Owner:** Shivam  ·  **Module:** tasks (emitter) / tpv (destination)
- **Where:** `backend/app/Services/Task/TaskService.php:1409`
- **What the user sees:** Open any task whose rel_type is tpv_vendor and click the vendor chip under "Related to" — the whole app is replaced by the 404 page. Tasks raised against a third-party vendor are the normal way HSSE follow-ups are tracked, so this is on a routine path.
- **Fix:** Change both occurrences to "/app/tpv/view/{$id}". Better: stop hard-coding TPV URLs inside the Tasks module — expose a link resolver on the TPV service seam and call it, so a route rename in TPV cannot silently break Tasks again.

```
TaskService.php:1409 `'tpv_vendor' => [$tpvVendors[$id] ?? "TPV Vendor #{$id}", "/app/tpv/vendors/{$id}"]` and the same string again in resolveRelLabel() at TaskService.php:1450. The TPV detail route is `/app/tpv/view/:id` (app/routes.jsx:661) — `/app/tpv/vendors` exists only as the LIST route (routes.jsx, TpvVendors), so `/app/tpv/vendors/123` matches nothing. The frontend's own module config agrees: modules/tpv/useVendorModule.js:63 defines `viewPath: (id) => '/app/tpv/view/' + id`. The value is consumed as a navigation target at modules/tasks/pages/TaskDetail.jsx:382-383 `task.rel_url && <button onClick={() => navigate(task.rel_url)}>`. Browser-verified: /app/tpv/vendors/1 renders "404 Page not found". Refutation checked: the sibling purchase_vendor line (:1410, :1453) is fine — `/app/purchase/vendors/:id/*` is a splat route with an index redirect to /overview at PurchaseVendorDetailL
```

### HR "Open Loans" links point at /app/hr/loans, a route and page that were never built

- **Owner:** Harshal  ·  **Module:** hr
- **Where:** `frontend/src/modules/hr/components/EmployeeLoanCard.jsx:49`
- **What the user sees:** Two live links dump the user on the full-screen 404 and out of the app shell: the "Open Loans" link on the Loans & Advances card of any employee's detail page, and "Open Loans" on the Loan Recovery rollup inside a payroll run. Both appear only when there IS loan data, so they fire exactly when a payroll officer is chasing a recovery figure.
- **Fix:** Short term, remove both links (the loan figures are already summarised in place, so nothing is lost). If a loans register is planned, build modules/hr/pages/Loans.jsx, register `<Route path="loans" ... />` under /app/hr, and have it read `employee` and `run` via useSearchParams — otherwise the two params on these links will be dropped even once the route lands.

```
EmployeeLoanCard.jsx:49 `<Link to={`/app/hr/loans?employee=${employeeId}`}>Open Loans</Link>`; modules/hr/pages/Payroll.jsx:1153 `<Link to={`/app/hr/loans?run=${runId}`}>Open Loans</Link>`. `grep -n "loans" frontend/src/app/routes.jsx` returns nothing — there is no /app/hr/loans route, and `ls frontend/src/modules/hr/pages/ | grep -i loan` is empty, so no Loans page component exists to mount. Browser-verified: /app/hr/loans renders "404 Page not found Go to Dashboard".
```

### Modules marketplace "Launch" 404s for four modules and renders an error page for a fifth

- **Owner:** mixed  ·  **Module:** modules marketplace
- **Where:** `frontend/src/pages/modules/ModulesPage.jsx:195`
- **What the user sees:** On /app/modules, installing a module and clicking its purple "Launch" button (or its chip in the Installed Modules strip) is broken for five of the nine modules with a basePath: Inventory, Payroll, AI Assistant and WhatsApp CRM go to the full-screen 404; Project Management goes to a page reading "Something went wrong on our side. The team has been notified." Inventory is a fully built, shipping module — the marketplace is the advertised way in, and it does not work.
- **Fix:** Give each registry entry an explicit `launchPath` (inventory '/app/inventory/analytics', projects '/app/projects', hr/accounts/purchase/tpv keep their /dashboard) and have ModulesPage navigate to `module.launchPath ?? module.basePath` instead of blindly appending '/dashboard'. For payroll/ai/whatsapp — which have no screens at all — set basePath to null so the Launch button is suppressed (the `module.basePath &&` guard at :193 already handles that) rather than shipping a button that 404s.

```
ModulesPage.jsx:195 and :85 both do `navigate(module.basePath + '/dashboard')`. registry.js basePaths: :59 '/app/inventory' — the inventory branch has index→Navigate to "analytics" and children analytics/assets/counts/…; there is no `dashboard` child, so /app/inventory/dashboard 404s. :82 '/app/payroll', :96 '/app/ai', :110 '/app/whatsapp' — no routes declared at all for those prefixes. :124 '/app/projects' — /app/projects/dashboard matches `/app/projects/:id` (routes.jsx:735), so ProjectDetail loads with id="dashboard" and the API call 500s. Browser-verified: /app/payroll/dashboard and /app/inventory/dashboard → "404 Page not found"; /app/projects/dashboard → "Something went wrong on our side… Back to projects".
```


## MEDIUM

### Low-stock alert email and bell notification deep-link with ?alert=, but the product list reads ?filter=

- **Owner:** Shivam  ·  **Module:** inventory
- **Where:** `backend/app/Services/Inventory/InventoryNotifier.php:757`
- **What the user sees:** A storekeeper gets "N products crossed the reorder level", clicks through from the email or the notification bell, and lands on the complete unfiltered product catalogue with no indication of which items triggered the alert. They have to hunt manually. The `out` (out-of-stock) variant has no filter implementation at all, so it can never work.
- **Fix:** Change the two backend emitters to `?filter=low` / `?filter=out`, and extend ProductList.jsx:56 to `const alertFilter = params.get('filter')` handling both 'low' and 'out'. Also seed the existing `alert` state from that param so the filter chip shows and the user can see why rows are missing.

```
InventoryNotifier.php:757 links `'/app/inventory/products?alert=low'` (only when more than one product crossed; the single-product case correctly deep-links to /app/inventory/products/{id}). resources/views/emails/inventory/stock-alert.blade.php:4 builds `…'/app/inventory/products?alert='.($critical ? 'out' : 'low')`. The destination is modules/inventory/pages/ProductList.jsx (routes.jsx:750); at ProductList.jsx:56 the only param it reads is `const lowOnly = params.get('filter') === 'low'`. `alert` is never read, and there is no branch for an `out` value under either name.
```

### Employee asset "Open in Inventory" passes ?asset={id} to a page that reads no query params

- **Owner:** Shivam  ·  **Module:** inventory (destination) / hr (consumer)
- **Where:** `backend/app/Services/Inventory/AssetService.php:306`
- **What the user sees:** From an employee's Assets panel, "Open in Inventory" is supposed to open the specific laptop/phone that employee holds. It opens the whole asset register instead, unfiltered and unscrolled — the user has to search for the asset by hand, which defeats the button.
- **Fix:** Add `const [params] = useSearchParams()` to InventoryAssets.jsx and use `params.get('asset')` to pre-filter/highlight (and scroll to) that row, showing a removable chip so the user knows the list is scoped. Alternatively add an `/app/inventory/assets/:id` detail route and emit that instead — but then fix the emitter too, don't leave the param dangling.

```
AssetService.php:306 `'inventory_url' => "/app/inventory/assets?asset={$a->id}"`. Consumed at modules/hr/components/EmployeeAssetsPanel.jsx:254 `<button onClick={() => navigate(asset.inventory_url)}>Open in Inventory</button>`. The route /app/inventory/assets (routes.jsx:766) mounts modules/inventory/pages/InventoryAssets.jsx, which contains no `useSearchParams`, no `URLSearchParams` and no `location.search` reference anywhere in its 263 lines.
```

### Employee 360 knowledge links open the KB home page instead of the article

- **Owner:** Harshal  ·  **Module:** hr (emitter) / helpdesk (destination)
- **Where:** `backend/app/Services/Hr/EmployeeLifecycleService.php:246`
- **What the user sees:** In the Employee Lifecycle panel, each listed knowledge-base article is a link showing its title. Clicking any of them opens the generic Knowledge Base landing page — same destination for every article — so the user has to find the article again through search. Every row in that list is effectively the same link.
- **Fix:** Change the emitter to `"/app/helpdesk/knowledge-base/{$a->id}"`. The section-level link on the same block (:248, bare /app/helpdesk/knowledge-base) is correct and should stay.

```
EmployeeLifecycleService.php:246 `'link' => "/app/helpdesk/knowledge-base?article={$a->id}"`. Rendered as a router link at modules/hr/components/EmployeeLifecyclePanel.jsx:93 `<Link key={item.id} to={item.link} …>`. The route /app/helpdesk/knowledge-base (routes.jsx:571) mounts modules/helpdesk/pages/KnowledgeBaseHome.jsx, which has no useSearchParams / URLSearchParams / location.search in its 190 lines. A correct per-article route already exists: /app/helpdesk/knowledge-base/:id.
```

### Employee 360 task links open the whole task board instead of the task

- **Owner:** Harshal  ·  **Module:** hr (emitter) / tasks (destination)
- **Where:** `backend/app/Services/Hr/EmployeeLifecycleService.php:177`
- **What the user sees:** Same panel, same shape of bug: every task row in an employee's lifecycle timeline links to the unfiltered Kanban board rather than to that task. The manager who clicked "Task due — Prepare handover" has to re-find it among every task in the tenant.
- **Fix:** Change EmployeeLifecycleService.php:177 to `"/app/tasks/{$t->id}"`, matching every other task deep-link in the backend. The sibling fallback at :179 (bare /app/tasks) is correct.

```
EmployeeLifecycleService.php:177 `'link' => "/app/tasks?task={$t->id}"`, surfaced through the timeline at EmployeeLifecyclePanel.jsx:93/:161. modules/tasks/pages/TaskBoard.jsx (routes.jsx:730) reads exactly two params — :36 `params.get('rel_type')` and :37 `params.get('rel_id')` — and nothing named `task`. The correct route exists and the rest of the codebase uses it: TaskService.php:418, :580, :646, :892 and TicketAssignmentService.php:290 all emit "/app/tasks/{id}", which resolves to TaskDetail (routes.jsx:731).
```

### Purchase module sidebar "Goods Received" is a ComingSoon stub

- **Owner:** Harshal  ·  **Module:** purchase
- **Where:** `frontend/src/modules/purchase/PurchaseLayout.jsx:27`
- **What the user sees:** Goods Received sits between Orders and Invoices in the Purchase sidebar — the middle step of the advertised procure-to-pay flow, and one of the module's headline features in the marketplace listing. Clicking it shows "🚧 Goods Received — This module is under construction." There is no other way to record a receipt, so the flow has a hole in the middle rather than a missing extra.
- **Fix:** Build the screen, or remove the entry from PurchaseLayout.jsx:27, registry.js:144 and the feature list at registry.js:138 until it exists. Shipping a sidebar item that opens a construction notice is worse than not listing it — the user cannot tell whether they have the wrong permissions or the wrong screen.

```
PurchaseLayout.jsx:27 `{ label: 'Goods Received', path: '/app/purchase/goods-received' }` and registry.js:144 list it as a nav item; registry.js:138 also advertises 'Goods Received' in the module's `features` array. app/routes.jsx:585 declares `<Route path="goods-received" element={<ComingSoon name="Goods Received" />} />`. Browser-verified: the page renders the construction stub inside the Purchase shell.
```

### Purchase vendor workspace: 8 of 29 sidebar tabs fall through to a ComingSoon tab

- **Owner:** Harshal  ·  **Module:** purchase
- **Where:** `frontend/src/modules/purchase/pages/vendor-detail/vendorDetailNav.jsx:58`
- **What the user sees:** Inside any purchase vendor's workspace the left rail lists 29 sections; eight of them (Todo Item, KB, Vault, and the whole four-item Performance group plus Referral) render a placeholder. The Performance group in particular is a complete dead section — every one of its five entries is a stub, so the user opens a heading that contains nothing.
- **Fix:** Filter the sidebar to `VENDOR_NAV_GROUPS` items whose key is in TAB_ELEMENTS (the `implemented` flag already tracks this and is currently documentation-only — make the layout read it, or derive it from TAB_ELEMENTS so the two cannot drift). Drop the Performance group entirely until it has a data model. Keep the routes so any bookmarked URL still resolves via the existing `*` → /overview redirect at PurchaseVendorDetailLayout.jsx:232.

```
vendorDetailNav.jsx VENDOR_NAV_GROUPS declares keys todo (:58), kb (:63), vault (:65), risk-score (:77), award (:80), penalty (:81), feedback (:82), referral (:83). vendorDetailTabs.jsx TAB_ELEMENTS contains 21 keys and omits exactly those 8. PurchaseVendorDetailLayout.jsx:230 `element={TAB_ELEMENTS[it.key] || <ComingSoonTab label={it.label} />}` renders the fallback, and :215 renders a NavLink for every item regardless. The file's own header comment (:20-22) documents this as unbuilt features with no backing table — i.e. it is known, but the links are still live in the UI.
```


## LOW

### In-app links written as plain <a href>, forcing a full browser reload of the SPA

- **Owner:** Zafar  ·  **Module:** accounts / shared
- **Where:** `frontend/src/modules/accounts/pages/Bills.jsx:465`
- **What the user sees:** Clicking a voucher number from a bill, or a linked task from a kickoff meeting, tears down and re-boots the whole application: white flash, re-auth, every cached query refetched, and any unsaved drawer state lost. Everywhere else in the app the same kind of link is instant.
- **Fix:** Replace with `<Link to={...}>` from react-router-dom in all four places (both files already navigate with the router elsewhere). Add an ESLint rule banning `<a href="/app` / `<a href="/portal` in src/ so the pattern cannot come back.

```
Bills.jsx:465 `<a href={`/app/accounts/vouchers/${bill.voucher?.id}`}>` and :466 (paid voucher); modules/shared/pages/KickoffMeetingDetail.jsx:365 `<a href={`/app/tasks/${item.task.id}`}>` and :583 `<a href={`/app/tasks/${it.converted_id}`}>`. All four target paths resolve correctly — the defect is the transport, not the destination. These are the only four in-app `<a href="/app…">` occurrences in the frontend; the fifth hit is the deliberate hard reload on the 404 page (routes.jsx:922), which is fine.
```

