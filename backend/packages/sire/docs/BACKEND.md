# Backend integration

## Layout

```

  app/Contracts/Sire/Sdk/           13 SDK contracts — the entire host seam
  app/Services/Sire/Sdk/            13 shipped implementations, so SIRE runs standalone
  app/Support/Sire/Sdk/             9 value objects + the capability vocabulary
  app/Contracts/Sire/               TimelineContributor
  app/Contracts/Sire/Ai/            AiProvider
  app/Services/Sire/                core + quality + knowledge + release
  app/Services/Sire/Ai/             the AI layer (deletable)
  app/Http/Controllers/Api/Sire/    17 controllers
  app/Http/Controllers/Api/Sire/Concerns/  envelope, ownership guard, identity resolution
  app/Http/Requests/Sire/           FormRequest per write action
  app/Models/Sire/                  21 models
  app/Models/Sire/Concerns/         tenant scoping + audit recording traits
  app/Console/Commands/Sire/        4 commands
  app/Exceptions/Sire/              SireException
  app/Providers/                    SireServiceProvider — the one file that wires it all
  app/Support/Sire/                 constants, workflow, module map, route map
  config/sire.php                   every host assumption, in one file
  routes/sire.php                   73 endpoints in one middleware group
```

**COPY AS-IS**, all of it, except two files: `config/sire.php` (confirm the users
and tenants table names) and `routes/sire.php` (one line — your role middleware).

No file anywhere under `app/` names a host class except the base `Controller`
that SIRE controllers extend. That is enforced by `tests/sdk.test.mjs`, and it is
what makes the rest copyable without reading it.

## The controller pattern

Every SIRE controller looks like this. Controllers **do not** check legality, stamp
timestamps or send notifications — doing any of that in a controller is how a
workflow ends up with two sets of rules that disagree.

```php
public function show(Request $request, Report $report): JsonResponse
{
    $this->assertTenantOwnership($report);   // 404, not 403

    return $this->success($this->reports->detail($report, $request->user()));
}
```

And every read inside a service:

```php
Report::query()
    ->forTenant($tenantId)          // opt-in. Miss it and you get every tenant.
    ->where('status', SireStatus::QA_FAILED)
    ->get();
```

## Routes

Copy `routes/sire.php` into `routes/`. Nothing else — `SireServiceProvider`
loads it:

```php
// SireServiceProvider::boot()
$this->loadRoutesFrom(__DIR__.'/../../routes/sire.php');
```

It is a complete, working route file, not a fragment to paste into an existing
group. All 73 endpoints sit inside one `Route::middleware([...])` group with the
`api/sire` prefix.

**The one line to change** is the role middleware:

```php
Route::middleware(['auth:sanctum', 'role:admin,staff'])   // <HOST_ROLE_MIDDLEWARE>
```

SIRE is for internal engineering staff; client, vendor and company roles must not
reach it.

> **Both middleware stay in ONE array.** A second chained `->middleware()` call
> replaces the first rather than adding to it, silently dropping `auth:sanctum`.
> The discovery report attributes a live cross-vendor leak in this CRM to exactly
> that mistake. `tests/routes-resolve.test.mjs` asserts there is exactly one
> middleware group in the file.

## Commands

| Command | Purpose | Destructive |
|---|---|---|
| `sire:doctor` | Verify the installation | **No — reads only** |
| `sire:export-workflow` | Regenerate the frontend workflow mirror | Writes one JS file |
| `sire:run-schedule` | SLA sweep and reminders | Writes notifications |
| `sire:index-issues` | Refresh the duplicate-detection index | Writes `sire_issue_tokens` |

Run `sire:doctor` after every deploy. It exists because SIRE's migrations are
guarded with `hasTable()` — right for a database with pending migrations, but it
makes a *missing* migration silent.

## Business rules live in services

| Service | Owns |
|---|---|
| `SireWorkflowService` | **The state machine.** The only class that writes `status`. |
| `SireReportService` | Create, detail, paginate |
| `SireAccessService` | **The only place authorization is decided** |
| `SireSlaService` | Computed SLA — never stored |
| `SireTimelineService` | Merges audit + notes + contributors |
| `SireNotifier` | Who hears about what |
| `SireTestCaseService` | Test cases — **the only writer of `result`** |
| `SireDuplicateService` … | Quality layer |
| `SireReleaseGovernanceService` … | Release layer |
| `Ai/*` | The AI layer. Deletable. |

## Errors

Throw `App\Exceptions\Sire\SireException` for every rule violation — a request
that is well-formed but not allowed right now. It renders itself as a 409 with a
message written FOR THE USER and safe to display verbatim.

```php
throw new SireException(
    "This case still has {$openCount} open action(s). Complete or cancel them before verification."
);
```

Deliberately distinct from validation (422, per-field) and authorization (403,
"not yours"). This is 409: the state of the world is wrong, not the input and not
the identity.

SIRE ships this class rather than importing the host's equivalent, because a
signature nobody could verify is a compile error waiting to happen. If your CRM
already has one — most do — the cheapest change is to make `SireException` extend
it, in one file. Nothing in SIRE catches this type by name except the handler.

## Adding a workflow state later

1. Edit `Support/Sire/SireWorkflow.php` — the authority.
2. `php artisan sire:export-workflow`.
3. `node --test tests/workflow-parity.test.mjs`.

Never hand-edit the generated mirror.
