# Sangoe CRM Architecture Primer — Roles, Tenancy, Module Wiring

Read this before building any new module. It's the ground truth for how auth roles, multi-tenancy, and module registration actually work today (verified by reading the code, not assumed) — plus the real gaps you need to work around, not repeat.

---

## 1. Roles / User types

There is **one `User` model, one table, one Sanctum auth guard** for everyone — staff, admin, vendor, third-party-vendor, and client/customer all log in through the same `/api/auth/login` endpoint. There is no separate Customer/Client model, no separate auth guard.

- `User` has a plain **string** `role` column (`admin | staff | vendor | third_party_vendor | client`) and a secondary `internal_role` (e.g. `hr_executive`, `hiring_manager`) used only when `role = staff`.
- **This is an unenforced string, not a real enum or permission system.** No Spatie `laravel-permission`, no `roles`/`permissions` tables. A frontend-only permission matrix exists (`frontend/src/components/admin/StaffModal.jsx` → `PERMISSION_MODULES`) but nothing on the backend currently enforces those granular permissions — only the coarse `role`/`internal_role` string is checked.
- **There is no distinct "super admin" role.** Registering a new company (`POST /api/auth/register`) creates a `Tenant` row **and** a `User` with `role='admin'` in one transaction — `role='admin'` effectively *is* the tenant owner. More `admin`/`staff` users can be added later; there's no flag distinguishing the founding admin from later ones.
- **Customer/vendor/TPV registration** (`/api/auth/register/client`, `/register/vendor`, `/register/tpv`) all just create a normal `User` row with the matching `role` value, `status` typically pending admin approval. Same table, same guard, same login endpoint as staff.
- **Role-check middleware**: `EnsureUserHasRole` (alias `role:`), the *only* middleware in `app/Http/Middleware/`. Currently only `routes/admin.php` uses it (`role:admin`). **`routes/hr.php` and `routes/sales.php` apply zero role restriction** — any authenticated user of any role can currently call those endpoints if they have a session.
- **Frontend has almost no role gating.** `ProtectedRoute` supports a `roles` prop, but no route in `frontend/src/app/routes.jsx` actually passes one — the only role check anywhere in the UI is a single sidebar link gated on `role === 'admin'`. A `client` or `vendor` user can navigate the SPA into `/app/hr/...` etc. today; only backend API calls that happen to have `role:` middleware will 403 them.

### ⚠️ What this means for you, concretely
When you build a new module (Purchase, TPV, Inventory, Project&Task, etc.), **do not assume role protection exists by default** — it doesn't, unless you add it yourself:
- Add `role:admin` (or the appropriate role list) to your `routes/<module>.php` group wherever staff-only or admin-only actions live, following the pattern in `routes/admin.php`.
- Don't rely on the frontend to hide something from a role that shouldn't see it — always enforce on the backend too.
- If you need finer-grained permissions (e.g. "HR executive can view but not delete payroll"), that plumbing doesn't exist yet — you'd be building it, not reusing something existing. Flag this as a team decision before each of you builds a different ad-hoc permission scheme.

---

## 2. Multi-tenancy

- `Tenant` model — one row per company/organization (`name, slug, subdomain, plan, status, branding, settings`).
- `BelongsToTenant` trait (`app/Models/Traits/BelongsToTenant.php`) — **NOT a global scope**. It only:
  1. Auto-stamps `tenant_id` from `auth()->user()->tenant_id` when a new record is created.
  2. Provides an **opt-in** `->forTenant($tenantId)` query scope.
- **There is no tenant middleware and no automatic read-filtering.** Every controller/service/repository must manually call `->forTenant($tenantId)` on every query, or it will silently return rows from every tenant. This is confirmed — `grep -rn "addGlobalScope"` across the whole backend returns zero hits.
- Tenant is resolved purely from the logged-in user's `tenant_id` column (not subdomain, not header, not custom domain — those `Tenant` columns exist but nothing reads them yet). The one exception is the public Helpdesk widget, which resolves tenant from a public widget key in the URL for unauthenticated requests.

### ⚠️ What this means for you, concretely
This is the single easiest way to introduce a cross-tenant data leak. For every new model/migration you add:
- `use BelongsToTenant;` on the model.
- In **every** repository/service method that queries that model, explicitly chain `->forTenant($tenantId)` — pull `$tenantId` from `auth()->user()->tenant_id` (or the value already passed into your service layer, matching the existing Sales/HR pattern).
- Never assume "it'll just work because the trait is on the model" — reads are not auto-scoped.
- If you spot a query in Sales/HR/Helpdesk that's missing `->forTenant()`, flag it — it's a real bug, not intentional.

---

## 3. How a module actually gets wired in

### Backend
- `backend/routes/api.php` just `require`s one file per module in sequence (`auth.php`, `admin.php`, `hr.php`, `sales.php`, `api_helpdesk.php`, `public.php`).
- Each module file follows: `Route::middleware('auth:sanctum')->prefix('<module>')->group(fn () => ...)`. Add `role:` middleware yourself if the module needs it (see section 1 — most existing modules don't have it and probably should).
- No service-provider registration, no module config, no auto-discovery. To add a module: create `routes/<module>.php`, `require` it from `api.php`, put controllers in `app/Http/Controllers/Api/<Module>/`, models in `app/Models/<Module>/`.
- Follow the existing service-layer pattern: thin Controller → Service (business logic + logging) → Model/Repository, with FormRequests for input validation.

### Frontend
- `frontend/src/app/routes.jsx` is the **single central route registry** — every module's routes are declared here explicitly, no auto-discovery. Pattern: a lazy-loaded `<Module>Layout` mounted as a parent route, with nested lazy page routes inside, wrapped in the shared `<Suspense>` helper `<S>`.
- The whole `/app/*` tree sits behind one `<ProtectedRoute>` (auth-only, no role check passed) wrapping `<AppShell/>`. Nested module routes add no further client-side role restriction today — if your module needs one, you'll need to pass a `roles` prop to `ProtectedRoute` (the prop already exists, it's just unused everywhere right now).
- **`frontend/src/modules/registry.js` (`ALL_MODULES`, install/uninstall via localStorage) is cosmetic only** — it powers a "marketplace" UI page (`ModulesPage.jsx`) but does **not** gate the actual routes. Installing/uninstalling there has no effect on whether `/app/hr/...` etc. is reachable. Don't rely on it as a real feature flag; if you want an actual "module enabled/disabled per tenant" toggle, that needs to be built for real (backend-driven), it doesn't exist yet.
- Sidebar nav (`components/layout/Sidebar.jsx`) is hardcoded per module with only one role check total. Add your module's nav entries there directly, following the existing pattern.

---

## Summary — 3 real gaps every new module should account for, not repeat

1. **No enforced role/permission system** beyond a loose string + one coarse middleware. Add `role:` middleware to your own routes where it matters; don't assume it's handled.
2. **Tenant scoping is opt-in, not automatic.** Chain `->forTenant($tenantId)` on every query yourself; there's no global scope or middleware safety net.
3. **The "Modules" marketplace UI is decorative.** Don't build against `registry.js` expecting it to control access — actual routing/gating happens in `routes.jsx` (frontend) and `routes/<module>.php` (backend) directly.

These are current, real weaknesses in the shared foundation (not specific to any one owner) — worth a quick team conversation on whether to harden them (e.g. convert `BelongsToTenant` to a real global scope, add default role middleware per module file) before all three of you are building on top of it in parallel.
