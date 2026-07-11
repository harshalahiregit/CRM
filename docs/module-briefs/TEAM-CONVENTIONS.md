# Team Conventions — Working in Parallel Without Breaking Each Other

Applies to all three of us (Zafar, Harshal, Shivam). Read alongside `ARCHITECTURE-PRIMER.md`. The goal: three people building nine modules in parallel on one repo, with zero rework from stepping on each other.

---

## 1. Code structure — every module looks the same

### Backend (Laravel)
```
backend/
  app/Models/<Module>/            ← e.g. App\Models\Purchase\PurchaseOrder
  app/Http/Controllers/Api/<Module>/
  app/Http/Requests/<Module>/     ← FormRequest per create/update action
  app/Services/<Module>/          ← ALL business logic + logging lives here
  app/Repositories/<Module>/      ← query layer (optional for simple modules)
  routes/<module>.php             ← one file per module, required from api.php
  database/migrations/            ← prefix table names per module (see §4)
```
Rules:
- **Thin controllers.** A controller method: validate (FormRequest) → call service → return via the shared `ApiResponse` trait. No inline business logic, no inline queries.
- **`use BelongsToTenant`** on every business model, and **every read query chains `->forTenant($tenantId)`** — scoping is NOT automatic (see primer). This is the #1 silent-bug risk in this codebase.
- **Role middleware on your route groups.** `auth:sanctum` alone is not protection — add `role:admin` / `role:admin,staff` per group (pattern in `routes/admin.php`). Decide per endpoint: staff-only, admin-only, or client/vendor-visible.
- **Custom exceptions** (`BusinessException`, `ResourceNotFoundException`, `UnauthorizedTenantException`) instead of returning ad-hoc error arrays.
- **Structured logging** to your module's channel for state-changing operations.

### Frontend (React)
```
frontend/src/
  modules/<module>/<Module>Layout.jsx   ← module shell w/ its sub-nav
  modules/<module>/pages/
  modules/<module>/components/
  services/<module>Api.js               ← ALL fetch calls for the module live here
```
Rules:
- Register routes as lazy imports in `app/routes.jsx` (see §3 for how to avoid conflicts there).
- `useToast()` + `ConfirmDialog` for destructive actions. **Never** a fake `showToast('Deleted!')` without a real API call. If backend doesn't exist yet, leave the button visibly disabled with a "coming soon" state — honest stubs only.
- Field names in the UI must match the **actual API response** — verify with a real request, don't guess (we found six pages reading `amount`/`items` where the API returns `total`/`line_items`; don't add a seventh).

### "No excess code" — what that means concretely
- No scaffolding for features not in your current scope (no empty service methods "for later", no unused props/params).
- No copy-pasting a whole page/controller then editing 10% — extract the shared part or write it fresh and small.
- No new dependency without posting it in the group first (one `composer require`/`npm install` can conflict with everyone's lockfile).
- Delete dead code you replace; don't comment it out "just in case" — git history keeps it.

---

## 2. Shared entities — the contracts we code against

These are the FK columns and owners. If you need one that doesn't exist yet, **use the agreed column name now** (nullable FK, no constraint yet) so wiring it later is a one-line migration, not a rename across modules.

| Entity | Owner | Status | FK column everyone uses |
|---|---|---|---|
| `User` (staff/admin/client/vendor login) | shared — **nobody redesigns it alone** | exists | `user_id`, or role-specific like `assigned_to` |
| `Tenant` | shared — nobody touches alone | exists | `tenant_id` (via `BelongsToTenant`) |
| `Client` (customer business entity) | **Zafar** | building first | `client_id` |
| `Vendor` (unified TPV + Purchase vendor) | **Harshal** | to be designed | `vendor_id` |
| Item/catalog (billing items vs stock items) | **Zafar (SalesItem) + Shivam (inventory)** | needs a 30-min sync before Shivam builds inventory items | — |

Process for shared-entity changes: post the proposed schema in the group **before** migrating; 24h / no-objection = go. Changing a shared entity after others FK into it = coordinate a migration plan, never just push.

---

## 3. Git & parallel-work rules (this is where conflicts actually happen)

- **Branches**: `<name>/<module>-<feature>` (e.g. `harshal/purchase-po-crud`). Small, focused PRs into `master` via GitHub — no direct pushes to master. Rebase/merge master into your branch at least daily; stale branches are where the painful conflicts come from.
- **Stay inside your module's folders.** Your PR should only touch `app/*/<YourModule>/`, `routes/<yourmodule>.php`, `frontend/src/modules/<yourmodule>/`, your own migrations, and the append-only shared files below. If you think you need to edit another module's file or a shared service, message the owner first — don't "quick fix" someone else's module.
- **Append-only shared files** — these are the known conflict hot-spots because all three of us add lines to them:
  - `backend/routes/api.php` (one `require` line per module)
  - `frontend/src/app/routes.jsx` (route registrations)
  - `frontend/src/components/layout/Sidebar.jsx` (nav entries)
  - `frontend/src/modules/registry.js` (cosmetic, but same pattern)

  Rule: **add your lines at the end of the relevant block, one line per concern, never reorder or reformat existing lines.** When you hit a merge conflict there, the resolution is almost always "keep both sides" (we already had exactly this conflict in `routes/api.php` — both branches added a `require`; keep-both was correct).
- **Never edit another person's migration file** (even to "fix" it) — write a new migration. Never rename another module's columns/tables.
- **Bug in someone else's module?** File it / message them with repro steps. Don't fix silently — you may not know why it's shaped that way, and the fix will conflict with their in-flight work.

---

## 4. Database conventions

- **Table prefix per module** so names never collide and ownership is obvious: `sales_*` (exists), `hr_*` (exists), `helpdesk_*`/existing helpdesk names (exists), and new: `clients`/`client_*` (Zafar), `acc_*` for Accounts extras like expenses/currencies (Zafar), `vendors`/`vendor_*` (Harshal), `purchase_*` (Harshal), `projects`/`project_*`, `tasks`/`task_*` (Shivam), `inv_*` for inventory (Shivam).
- Every business table: `tenant_id` (indexed), timestamps, soft deletes where records are user-facing/recoverable.
- Money = `decimal(15,2)`, never float. Dates as `date`/`datetime`, status fields as short strings backed by a PHP enum class (don't add more loose magic strings — the `User.role` situation is the cautionary tale).
- Foreign keys to shared entities use the exact column names from §2.

---

## 5. Definition of done (per PR)

1. Endpoint(s) verified with **real HTTP calls** against the dev server (auth token, real route, real middleware) — not just `php artisan test` or tinker. Test data cleaned up **by specific ID** (never blanket-delete, e.g. never `User::tokens()->delete()` — it kills real sessions).
2. `->forTenant()` present on every query; role middleware on the route group; spot-check as a second tenant/role that you get 403/404, not data.
3. Frontend page reads actual response field names (checked against a real response, not assumed).
4. No fake success toasts; destructive actions behind `ConfirmDialog`.
5. PR description says what was built, what's intentionally stubbed, and any shared file touched.

---

## 6. Communication triggers — message the group BEFORE doing it

- Adding/upgrading any composer/npm dependency
- Creating or altering a shared entity (§2) or any table another module reads
- Adding middleware, traits, or helpers intended for shared use
- Anything touching auth, `User`, `Tenant`, or `BelongsToTenant`
- Descoping something from your brief that another module depends on (e.g. Vendor model delay blocks Shivam's Kickoff)
