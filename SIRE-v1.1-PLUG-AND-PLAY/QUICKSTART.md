# Quick start

The shortest path from this package to a working SIRE.

**Never done this before?** Use
[docs/JUNIOR-DEVELOPER-START-HERE.md](docs/JUNIOR-DEVELOPER-START-HERE.md)
instead — same route, more hand-holding. This page assumes you know Laravel.

---

## 0. Check the package is intact

```bash
node --test tests/*.test.mjs
```

407 executable specifications. No database, no browser, no `npm install`. They
verify SIRE's own logic and the SDK's internal consistency — **not** its fit
with your application, which is what the rest of this page is about.

## 1. Install

```bash
composer config repositories.sire path /path/to/SIRE-v1.1-PLUG-AND-PLAY
composer require sangoe/sire:*
```

Package discovery registers the provider. Copy-installing instead? Add
`"Sire\\": "packages/sire/src/"` to your PSR-4 autoload and register
`Sire\SireServiceProvider::class` by hand.

## 2. Will it work here?

```bash
php artisan sire:compatibility
```

**SUPPORTED** · **CONDITIONAL** · **MANUAL** (one small adapter — not a failure)
· **UNSUPPORTED** (rare; only this one blocks you).

## 3. Look at the host

```bash
php artisan sire:discover      # read-only
php artisan sire:host-profile  # what it found, redacted
```

Check three things: the **tenant column**, your **role names**, and the **user
model**. If the tenant column looks wrong, that is expected — the installer asks.

## 4. Install

```bash
php artisan sire:install
```

Applies what it is sure about. **Asks about two things it must not guess:**

**Tenancy.** Which column identifies the *tenant* — not the customer a user
works for. Get this wrong and one customer sees another's issues, on a page that
looks entirely normal. Not multi-tenant? Answer `single_tenant`.

**Login types.** Which of *your* role names are ADMIN, INTERNAL_USER, CUSTOMER
and VENDOR. The first two get access; the last two are blocked. A role in no
list gets nothing, which is the safe way to be wrong.

```bash
php artisan sire:install --dry-run   # preview, changes nothing
```

## 5. Check it

```bash
php artisan sire:doctor
```

Every check says what it looked at, why it matters and how to fix it. Work down
the FAILs; read the WARNs and decide.

## 6. Prove tenant isolation — **do not skip**

The doctor cannot check this. Only you can.

1. Tenant A: create an issue, note the id
2. Tenant B: `GET /api/sire/reports/<id>`
3. **You want a 404.** A 200 means the tenant strategy is wrong — stop and fix it.

Skip only if you answered `single_tenant`.

## 7. Check the four login types

Admin → in · Internal user → in · **Customer → 403** · **Vendor → 403**

A customer getting in means the mapping is wrong.

## 8. Frontend (React hosts)

```bash
php artisan vendor:publish --tag=sire-frontend
```

Three additive edits, all optional — SIRE ships fallbacks and works before any
of them:

```jsx
SireHost.configure({ api, ui: { Button, Modal }, toast: useToast });  // bootstrap
<ReportIssueRoot />                                                   // authed layout
<SireContextProvider><AppRoutes /></SireContextProvider>              // around routes
```

[docs/FRONTEND-INTEGRATION.md](docs/FRONTEND-INTEGRATION.md). No React? Skip —
the Blade widget and the API both work.

## 9. Walk one issue end to end

Report → triage → assign → develop → QA → release. If it completes, you are
done.

---

## Then, whenever you like

Connect your own subsystems one at a time — copy a stub from
`examples/host-adapters/`, implement it, name it in `config/sire-host.php`.
Nothing else changes.

**Six are permanently optional**: audit, notes, settings, numbering, SLA,
knowledge base. SIRE owns tables and engines for all six.

[docs/ADAPTERS.md](docs/ADAPTERS.md)

## If something is wrong

```bash
php artisan sire:doctor
php artisan sire:architecture
```

[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)

## If you need to back out

```bash
php artisan sire:uninstall     # disables SIRE, keeps every issue
```

Data is deleted only by `--purge`, after a typed confirmation that `--force`
does not bypass. Your CRM's tables are never touched.
