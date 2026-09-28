# Start here

You have a ZIP and a Laravel CRM. This page gets you from one to the other.

**You do not need to read the SIRE codebase.** Fourteen steps, each one a command
or a check. Two of them ask you a question that only you can answer — those two
are the ones to slow down for.

Budget: **half a day**, most of it waiting for commands.

---

## Before you start

```bash
php -v          # 8.2 or newer
php artisan --version   # Laravel 10, 11 or 12
```

If either is below that, stop — [COMPATIBILITY.md](COMPATIBILITY.md) explains
why those are the floors.

**Take a database backup.** SIRE only ever creates its own tables, but you are
about to run migrations on a real database and this is not the day to find out
your backups do not work.

---

## 1. Extract the package

```bash
unzip SIRE-v1.1-PLUG-AND-PLAY-COMPLETE.zip -d /tmp
```

## 2. Install it

**Composer (preferred)** — add a path repository and require it:

```bash
composer config repositories.sire path /tmp/SIRE-v1.1-PLUG-AND-PLAY
composer require sangoe/sire:*
```

Laravel's package discovery registers the service provider automatically. Skip
to step 4.

**Or copy the files** if you would rather vendor the source:

```bash
cp -r /tmp/SIRE-v1.1-PLUG-AND-PLAY packages/sire
```

Then add the autoload entry to your `composer.json`:

```json
"autoload": { "psr-4": { "Sire\\": "packages/sire/src/" } }
```

```bash
composer dump-autoload
```

## 3. Register the provider (copy-install only)

```php
// bootstrap/providers.php   (Laravel 11 / 12)
return [
    App\Providers\AppServiceProvider::class,
    Sire\SireServiceProvider::class,          // add this
];
```

Laravel 10: add the same line to `config/app.php` under `providers`.

## 4. Check compatibility

```bash
php artisan sire:compatibility
```

Reads your application and prints four kinds of verdict.

- **SUPPORTED** — nothing to do.
- **CONDITIONAL** — works, with a caveat it names.
- **MANUAL** — works once you write one small adapter. **Not a failure.**
- **UNSUPPORTED** — stop and fix this first. Rare.

## 5. Discover the host

```bash
php artisan sire:discover
```

Read-only. It inspects your schema, auth config, roles and frontend, and writes
`storage/app/sire/host-profile.json`. Nothing is modified.

## 6. Read what it found

```bash
php artisan sire:host-profile
```

Look at three things:

| | What to check |
|---|---|
| **Tenant column** | Is it the column that identifies the TENANT? |
| **Roles** | Are all your role names listed? |
| **User model** | Is it the model people actually log in as? |

If the tenant column looks wrong, or your roles are missing, that is normal —
the installer asks about both, and you get to correct them.

## 7. Install

```bash
php artisan sire:install
```

It applies what it is sure about and **asks about what it is not**. Expect these
questions.

## 8. Answer the tenancy question — **slow down here**

```
How does this application decide which tenant a request belongs to?
  Strategy [user_attribute]:
  Is 'tenant_id' the column that identifies the TENANT? (yes/no)
```

**This is the one that matters.** Every other setting fails loudly if it is
wrong. This one fails silently: SIRE would show one customer another customer's
issues, on a page that looks completely normal.

Watch for a column like `company_id` that identifies **the customer a user works
for** rather than the tenant they belong to. From the database those look
identical. From your side of the desk they are opposites.

If your application is **not** multi-tenant — one company, one set of users —
answer `single_tenant`.

## 9. Answer the login-type question — **slow down here too**

```
Which roles are ADMIN?         (these GET SIRE access)
Which roles are INTERNAL USER? (these GET SIRE access)
Which roles are CUSTOMER?      (these get NO SIRE access)
Which roles are VENDOR?        (these get NO SIRE access)
```

SIRE issues contain reproduction steps for bugs that are **not yet fixed**. Put
a customer-facing role in the wrong list and your customers can read them.

A role you leave out of every list gets **no access**, which is the safe way to
be wrong.

## 10. Check the installation

```bash
php artisan sire:doctor
```

Every check says WHAT it looked at, WHY it matters and HOW to fix it. Work down
the FAILs. WARNs are usually fine — read them and decide.

## 11. Prove tenant isolation — **do not skip this**

The doctor cannot check this. Only you can.

1. Log in as a user in **tenant A**. Create an issue. Note the id.
2. Log in as a user in **tenant B**.
3. Request `/api/sire/reports/<that id>`.

**You want a 404.**

| You got | Meaning |
|---|---|
| **404** | Correct. Isolation works. |
| **200** | The tenant strategy is wrong. Stop and fix it. |
| **403** | Nearly right, but it confirms the issue exists. Fix it to 404. |

If your app is `single_tenant`, skip this — there is nothing to isolate.

## 12. Test the four login types

Log in as one account of each kind and open SIRE.

| Account | Expected |
|---|---|
| Admin | Full access |
| Internal user | Access, with fewer buttons |
| Customer | **403 — blocked** |
| Vendor | **403 — blocked** |

If a customer gets in, your login-type mapping is wrong. Fix
`config/sire-host.php` and re-run the doctor.

## 13. Publish the frontend (React hosts)

```bash
php artisan vendor:publish --tag=sire-frontend
```

Then three additive edits — none of them changes existing behaviour. Full detail
in [FRONTEND-INTEGRATION.md](FRONTEND-INTEGRATION.md):

```jsx
// once, at bootstrap — point SIRE at your building blocks
SireHost.configure({ api, ui: { Button, Modal }, toast: useToast });

// in your authenticated layout — the global Report Issue button
<ReportIssueRoot />

// wrap your routes
<SireContextProvider><AppRoutes /></SireContextProvider>
```

**Every one of those is optional.** SIRE ships fallbacks, so it works before you
register anything — it just looks less like your product.

No React? Skip this. The API and the Blade widget both work.

## 14. Walk one issue end to end

Report → triage → assign → develop → QA → release. If it completes, you are
done.

```bash
php artisan sire:doctor        # once more, after real data exists
```

---

## When something is wrong

```bash
php artisan sire:doctor           # what is broken and how to fix it
php artisan sire:architecture     # is SIRE core still CRM-agnostic?
php artisan sire:install --dry-run  # what would change?
```

[TROUBLESHOOTING.md](TROUBLESHOOTING.md) covers the failures people actually
hit.

## If you need to back out

```bash
php artisan sire:uninstall        # disables SIRE, KEEPS every issue
```

Data is deleted only by `--purge`, and only after you type a confirmation
phrase. Your CRM's own tables are never touched by any path.

---

## The five things worth knowing

1. **SIRE runs before you integrate anything.** Every one of the fourteen
   contracts ships with a working implementation, so you can click through the
   whole workflow on day one.
2. **Six integrations are permanently optional** — audit, notes, settings,
   numbering, SLA, knowledge base. SIRE owns tables and engines for all six.
3. **SIRE never touches your tables.** It creates 20 of its own, all prefixed
   `sire_`, and reads yours only through providers you configure.
4. **Two settings are dangerous, and both are confirmed by a human**: the tenant
   source and the login-type mapping.
5. **AI is off by default**, computes locally, and SIRE is fully functional
   without it.

## Where to go next

| You want to | Read |
|---|---|
| Connect your audit / notes / KB | [ADAPTERS.md](ADAPTERS.md) |
| Understand tenancy | [TENANCY.md](TENANCY.md) |
| Understand permissions | [AUTHORIZATION.md](AUTHORIZATION.md) |
| Understand the shape | [ARCHITECTURE.md](ARCHITECTURE.md) |
| Ship it | [DEPLOYMENT.md](DEPLOYMENT.md) |
