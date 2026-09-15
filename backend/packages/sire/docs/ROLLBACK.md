# Rollback

## Recommended: revert the code, leave the schema

```
1  Re-upload the previous zip via Plesk
2  php artisan route:clear && php artisan config:clear
3  Confirm the CRM loads
```

**Do not roll the migrations back.**

SIRE's 21 tables are additive, prefixed, and referenced by nothing outside SIRE.
Left in place they occupy about a megabyte and affect nothing — no CRM module reads
them, no CRM query joins them, and no foreign key points at them.

Dropping tables on a production database that may be shared by two deployments, to
undo a UI problem, is a far larger risk than the tables are.

## If the schema genuinely must go back

```bash
php artisan migrate:rollback --pretend --step=20     # inspect first
php artisan migrate:rollback --step=20
```

> **Check the batch first.** `migrate:rollback` reverses a **batch**. If non-SIRE
> migrations ran in the same batch they are reversed too. Confirm with
> `migrate:status` that the batch contains only SIRE migrations.
>
> This is the second reason step 2 of the deployment order matters: running
> `migrate` with unrelated migrations pending puts them in SIRE's batch.

## One imperfect reversal

Migration `000010` drops `sla_notified_state`, which `000003` created. It has never
held data, so nothing is lost — but a rollback re-creates it **empty** rather than
restoring it.

Every other `down()` is a clean reversal: drop the table, or drop the columns the
migration added.

## Partial rollback

The migrations are ordered so each phase's schema arrives with the code that uses
it. To roll back one phase, count its migrations:

| Phase | Migrations | Rolls back |
|---|---|---|
| 3 — AI | `000020`–`000022` | AI suggestions, the token index, test cases |
| 3 — release governance | `000019` | Gate state, override register |
| 2 — quality | `000011`–`000018` | Releases, RCA, recurrence, links, KB, notes, CAPA |
| 1 — workflow + SLA | `000007`–`000010` | Context, work cycles, engineering columns, SLA |
| Base | `000001`–`000004` | The register itself |

A partial rollback leaves code referencing dropped columns. **Revert the code to
match**, or the first request touching a missing column errors.

## Disabling without rolling back

Often better than either:

| To stop | Do |
|---|---|
| All AI | `sire.ai.enabled = false` |
| One AI capability | Remove its key from `sire.ai.capabilities` |
| SLA notifications | Remove `sire:run-schedule` from the scheduler |
| Duplicate detection | Remove `sire:index-issues` |
| SIRE entirely, keeping data | Remove the sidebar entry and the `require` in `routes/api.php` |

That last one leaves the schema and the data intact and takes SIRE out of the UI
and the API in two edits — reversible in two more.
