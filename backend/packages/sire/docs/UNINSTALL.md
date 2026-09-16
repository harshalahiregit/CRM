# Uninstall

```bash
php artisan sire:uninstall
```

**Two stages, and only the second touches data.**

## Stage 1 — disable (the default)

Removes generated configuration and discovery artefacts:

```
config/sire-host.php
config/sire.php                      (if published)
storage/app/sire/
```

**No data is touched.** Every issue, comment, audit entry and release survives.
Re-installing brings all of it back.

Then remove the provider by hand:

```php
// bootstrap/providers.php — delete this line
Sire\SireServiceProvider::class,
```

or, if you installed with Composer:

```bash
composer remove sangoe/sire
```

### Why disabling is the default

Most "uninstalls" are really "turn it off while we decide". A command that
deleted a year of engineering history because somebody wanted to quieten a menu
item would be indefensible.

## Stage 2 — purge (explicit, irreversible)

```bash
php artisan sire:uninstall --purge
```

Drops SIRE's 20 tables. Then asks you to type a phrase:

```
PURGE will permanently delete all SIRE data.
20 table(s) will be dropped. This cannot be undone.

Type DELETE SIRE DATA to confirm:
```

**`--force` does not bypass this.** The typed confirmation is checked after
`--force` has taken effect, so a runbook someone copied cannot destroy your
history.

## What it can never do

It cannot drop, alter or empty a table SIRE does not own.

The purge list is a **hardcoded set of 20 `sire_`-prefixed names** — not a
pattern match, not a "tables SIRE thinks it created" heuristic — and the drop
loop re-checks the `sire_` prefix at runtime even so. A test asserts that the
list and the migrations name exactly the same tables in both directions.

Your users, tenants, tickets, notes, audit trail, attachments and settings are
not reachable from this command by any code path.

## Preview first

```bash
php artisan sire:uninstall --dry-run
php artisan sire:uninstall --purge --dry-run
```

Prints exactly what each stage would do, and changes nothing.

## The tables SIRE owns

```
sire_report_categories   sire_severities        sire_reports
sire_approvals           sire_report_contexts   sire_work_cycles
sire_releases            sire_root_causes       sire_recurrence_groups
sire_report_links        sire_kb_links          sire_release_notes
sire_actions             sire_release_overrides sire_ai_suggestions
sire_issue_tokens        sire_test_cases        sire_settings
sire_notes               sire_audit_events
```

Three of those — `sire_settings`, `sire_notes`, `sire_audit_events` — are the
SDK fallbacks, and are empty if you connected your own providers.

## Rolling back migrations instead

If you would rather use Laravel's own mechanism:

```bash
php artisan migrate:rollback --path=vendor/sangoe/sire/database/migrations
```

Every SIRE migration is reversible and drops only its own table.

## Reinstalling

```bash
php artisan sire:install
```

If you only disabled, your data is still there and the installer picks up where
it left off. If you purged, it starts clean.
