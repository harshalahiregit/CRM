# Backend integration

## Layout

```

  src/Contracts/                    13 SDK contracts — the entire host seam
  src/Adapters/Defaults/            13 shipped implementations, so SIRE runs standalone
  src/Dto/                          8 value objects
  src/Support/                      constants, workflow, capability vocabulary, route map
  src/Contracts/Extension/          TimelineContributor
  src/Contracts/Ai/                 AiProvider
  src/Services/                     core + quality + knowledge + release
  src/AI/                           the AI layer (deletable)
  src/Http/Controllers/             18 controllers
  src/Http/Controllers/Concerns/    envelope, ownership guard, identity resolution
  src/Http/Requests/                FormRequest per write action
  src/Models/                       21 models
  src/Models/Concerns/              tenant scoping + audit recording traits
  src/Database/Factories/           9 model factories, for the test suite
  src/Console/Commands/             11 commands
  src/Exceptions/                   SireException
  src/SireServiceProvider.php       the one file that wires it all
  config/sire.php                   every host assumption, in one file
  routes/sire.php                   76 endpoints in one middleware group
```

**Two ways to install, and the paths above are the second one.**

SIRE originally shipped to be copied file-by-file into `app/`, under
`App\Services\Sire\…`. It is now a package: the tree above is what
`backend/packages/sire/` actually contains, and everything in it lives under the
`Sire\` namespace. Read `App\Services\Sire\Foo` in older pages as
`Sire\Services\Foo`.

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
group. All 76 endpoints sit inside one `Route::middleware([...])` group with the
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

Throw `Sire\Exceptions\SireException` for every rule violation — a request
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
