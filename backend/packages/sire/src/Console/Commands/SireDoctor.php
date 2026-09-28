<?php

namespace Sire\Console\Commands;

use Sire\Models\Report;
use Sire\Contracts\SireSettingsProvider;
use Sire\AI\SireAiGateway;
use Sire\Support\SireWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * SIRE — read-only installation check.
 *
 * WRITES NOTHING. No migration, no seed, no setting, no file. Safe to run on
 * production at any time, including before a deploy and immediately after one.
 *
 * It exists because SIRE's migrations are deliberately guarded with
 * `Schema::hasTable()` — the right call for a live database that has repeatedly
 * been found with migrations pending — but that guard makes a MISSING migration
 * silent. `php artisan migrate` reports success, the ALTERs no-op, and the module
 * is broken with nothing in any log. This command is what turns that into an
 * answer.
 */
class SireDoctor extends Command
{
    protected $signature = 'sire:doctor {--json : Machine-readable output for a deploy script}';

    protected $description = 'SIRE: verify the installation. Read-only — changes nothing.';

    /** Every table SIRE owns. Anything missing here means a migration did not run. */
    private const TABLES = [
        'sire_report_categories', 'sire_severities', 'sire_reports', 'sire_approvals',
        'sire_report_contexts', 'sire_work_cycles', 'sire_releases', 'sire_root_causes',
        'sire_recurrence_groups', 'sire_report_links', 'sire_kb_links', 'sire_release_notes',
        'sire_actions', 'sire_release_overrides', 'sire_ai_suggestions', 'sire_issue_tokens',
        'sire_test_cases',
        // The SDK fallbacks. Present even when a host provider is bound: SIRE
        // owns them, and a bound provider simply leaves them empty.
        'sire_settings', 'sire_notes', 'sire_audit_events',
    ];

    /** Columns later migrations add. Present tables with absent columns means a partial run. */
    private const COLUMNS = [
        'sire_reports' => [
            'workflow_track', 'priority', 'fix_summary', 'sla_started_at',
            'release_class', 'requires_regression_test', 'module',
        ],
    ];

    public function handle(SireSettingsProvider $settings, SireAiGateway $ai): int
    {
        $checks = [];

        $checks[] = $this->checkInstalled();
        $checks[] = $this->checkPlatform();
        $checks[] = $this->checkProviders();
        $checks[] = $this->checkAuthMiddleware();
        $checks[] = $this->checkTenantStrategy();
        $checks[] = $this->checkLoginTypes();
        $checks[] = $this->checkTables();
        $checks[] = $this->checkColumns();
        $checks[] = $this->checkRoutes();
        $checks[] = $this->checkStorage();
        $checks[] = $this->checkScheduler();
        $checks[] = $this->checkQueue();
        $checks[] = $this->checkWorkflow();
        $checks[] = $this->checkTenantIsolation();
        $checks[] = $this->checkAi($ai);
        $checks[] = $this->checkArchitecture();
        $checks[] = $this->checkFrontend();
        $checks[] = $this->checkBuildParity();

        $failed = array_filter($checks, fn (array $c) => $c['status'] === 'fail');
        $warned = array_filter($checks, fn (array $c) => $c['status'] === 'warn');

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $failed === [],
                'checks' => $checks,
            ], JSON_PRETTY_PRINT));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info('SIRE doctor');
        $this->newLine();

        foreach ($checks as $check) {
            // Defaulted rather than indexed: the doctor is what you run WHEN
            // something is wrong, so an unrecognised status must degrade to a
            // readable line, never take down the one command that explains the
            // problem.
            $badge = [
                'pass' => '<fg=green>PASS</>',
                'info' => '<fg=cyan>INFO</>',
                'warn' => '<fg=yellow>WARN</>',
                'fail' => '<fg=red>FAIL</>',
            ][$check['status']] ?? '<fg=gray>'.strtoupper((string) $check['status']).'</>';

            $this->line("  {$badge}  <options=bold>{$check['name']}</>");
            $this->line("        {$check['detail']}");

            // WHY and HOW appear only when something is wrong: a healthy report
            // should be scannable, and forty lines of explanation about things
            // that are fine trains people not to read it.
            if ($check['status'] !== 'pass') {
                if (($check['why'] ?? '') !== '') {
                    foreach (explode("\n", $check['why']) as $line) {
                        $this->line("        <fg=gray>{$line}</>");
                    }
                }

                if (($check['fix'] ?? '') !== '') {
                    $this->line("        <fg=cyan>→ {$check['fix']}</>");
                }
            }
        }

        $this->newLine();

        if ($failed !== []) {
            $this->error(sprintf('%d check(s) failed. SIRE is not ready.', count($failed)));

            return self::FAILURE;
        }

        $this->info($warned === []
            ? 'SIRE looks healthy.'
            : sprintf('SIRE is installed. %d warning(s) — see above.', count($warned)));

        return self::SUCCESS;
    }

    /**
     * Every result carries WHAT, WHY and HOW TO FIX.
     *
     * A diagnostic that says "FAIL: tenant provider" has told you nothing you
     * could act on. Naming what was checked, why it matters and the exact next
     * command is the difference between a useful doctor and a status light.
     */
    private function result(string $name, string $status, string $detail, string $why = '', string $fix = ''): array
    {
        return compact('name', 'status', 'detail', 'why', 'fix');
    }

    /**
     * Which implementation is behind each of the thirteen SDK seams.
     *
     * The most useful line in the whole report, because "SIRE is installed" and
     * "SIRE is connected to this host" are different states that look identical
     * from the UI. A provider still on SIRE's own implementation is not a fault
     * -- SIRE is designed to run that way -- but somebody should be able to SEE
     * it rather than discover it.
     *
     * Resolution failures are caught per provider: a mistyped class name in
     * config should be reported by the diagnostic that exists to find it, not
     * thrown out of it.
     */
    private function checkProviders(): array
    {
        $bound = [];
        $shipped = 0;
        $broken = [];

        foreach (array_keys((array) config('sire.providers', [])) as $key) {
            $interface = 'Sire\\Contracts\\Sire'
                .str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $key)))
                .'Provider';

            try {
                $class = get_class(app($interface));
                $isShipped = str_starts_with(class_basename($class), 'SireLocal');
                $shipped += $isShipped ? 1 : 0;
                $bound[$key] = class_basename($class).($isShipped ? '  (SIRE default)' : '  (host)');
            } catch (Throwable $e) {
                $broken[] = $key.': '.$e->getMessage();
            }
        }

        if (! $this->option('json')) {
            $this->line('');
            foreach ($bound as $key => $class) {
                $this->line(sprintf('    %-14s %s', $key, $class));
            }
        }

        if ($broken !== []) {
            return $this->result('providers', 'fail', count($broken).' could not be resolved: '.implode('; ', $broken));
        }

        if ($bound === []) {
            return $this->result('providers', 'fail', "config('sire.providers') is empty — is SireServiceProvider registered?");
        }

        $connected = count($bound) - $shipped;
        $detail    = sprintf('%d bound, %d on SIRE defaults, %d connected to the host', count($bound), $shipped, $connected);

        // Running entirely on SIRE's own implementations is a SUPPORTED end state
        // and the correct one for a fresh install -- six of the thirteen are meant
        // never to be connected. So this is information, not a fault.
        //
        // On PRODUCTION it means something else. Nothing host-connected there is
        // not a configuration in progress; it is a feature quietly switched off on
        // a system people are relying on -- notifications going to a log file
        // while everyone assumes an assignment reaches someone. That state is
        // worth interrupting a person about, and the only page that can tell them
        // is this one.
        //
        // It stays a WARN rather than a FAIL: SIRE genuinely runs this way, and
        // refusing the whole report over it would hide the checks that matter.
        if ($connected === 0 && app()->environment('production')) {
            return $this->result(
                'providers',
                'warn',
                $detail,
                "Every seam is still on SIRE's own implementation. On production that usually\n"
                ."means a host provider was written but never reached the box -- most often a\n"
                .'cached config, or a deploy that predates it.',
                'php artisan config:clear, then check the providers block in config/sire-host.php.',
            );
        }

        return $this->result('providers', 'info', $detail);
    }


    // ------------------------------------------------------------ new checks

    /** Has anyone actually run the installer? Everything else assumes so. */
    private function checkInstalled(): array
    {
        $path = function_exists('config_path') ? config_path('sire-host.php') : null;

        if ($path !== null && is_file($path)) {
            return $this->result('Installation', 'pass', 'Host configuration found at config/sire-host.php.');
        }

        return $this->result(
            'Installation',
            'warn',
            'No generated host configuration.',
            "SIRE is running entirely on its documented defaults. That works, but nothing\n"
            ."has been confirmed for THIS application -- including tenancy.",
            'php artisan sire:install',
        );
    }

    /** PHP and Laravel, against the floors SIRE's own code sets. */
    private function checkPlatform(): array
    {
        $php = PHP_VERSION;
        $laravel = app()->version();

        if (version_compare($php, \Sire\Installation\Compatibility::PHP_MIN, '<')) {
            return $this->result(
                'Platform',
                'fail',
                "PHP {$php} is below SIRE's minimum of ".\Sire\Installation\Compatibility::PHP_MIN.'.',
                'SIRE uses readonly promoted properties and DNF types that earlier versions reject.',
                'Upgrade PHP, or install SIRE on a host that meets the floor.',
            );
        }

        $major = (int) $laravel;

        if ($major < \Sire\Installation\Compatibility::LARAVEL_MIN) {
            return $this->result(
                'Platform',
                'fail',
                "Laravel {$laravel} is below SIRE's minimum of ".\Sire\Installation\Compatibility::LARAVEL_MIN.'.',
                'SIRE\'s migrations and service provider use APIs added in Laravel 10.',
                'Upgrade Laravel.',
            );
        }

        return $this->result('Platform', 'pass', "PHP {$php} · Laravel {$laravel}.");
    }

    /**
     * The gate on every SIRE route.
     *
     * Empty middleware is a FAIL, not a warning: SIRE substitutes a deny-all
     * guard so nothing leaks, but an installation nobody can reach is broken
     * and should say so in red.
     */
    private function checkAuthMiddleware(): array
    {
        $auth = (array) config('sire.host.auth_middleware', []);
        $auth = array_values(array_filter($auth, static fn ($m) => is_string($m) && trim($m) !== ''));

        if ($auth === []) {
            return $this->result(
                'Authentication',
                'fail',
                'No authentication middleware configured. SIRE is refusing all traffic.',
                "SIRE fails closed rather than serving an engineering backlog to anonymous\n"
                .'requests. Nothing is exposed -- but nothing works either.',
                "Set config('sire.host.auth_middleware'), e.g. ['auth:sanctum'] -- or run: php artisan sire:install",
            );
        }

        $prefix = config('sire.host.route_prefix', 'api/sire');

        return $this->result(
            'Authentication',
            'pass',
            'Routes at /'.$prefix.' gated by: '.implode(', ', $auth).' + engineering login-type check.',
        );
    }

    /**
     * The one that fails silently, so the doctor asks about it every run.
     *
     * A tenant strategy that resolves to nothing is not merely broken -- if it
     * resolved to the WRONG thing it would look perfectly healthy, which is why
     * this check reports what is configured rather than only whether it works.
     */
    private function checkTenantStrategy(): array
    {
        $strategy = (string) config('sire.tenant.strategy', '');

        $known = ['user_attribute', 'relationship', 'resolver', 'callable', 'single_tenant'];

        if (! in_array($strategy, $known, true)) {
            return $this->result(
                'Tenancy',
                'fail',
                "Unknown tenant strategy: '{$strategy}'.",
                'Every SIRE query is scoped by tenant. With no valid strategy, no request can resolve one.',
                "Set config('sire.tenant.strategy') to one of: ".implode(', ', $known),
            );
        }

        $detail = match ($strategy) {
            'user_attribute' => "user_attribute → \$user->".config('sire.tenant.attribute', 'tenant_id'),
            'relationship'   => 'relationship → $user->'.config('sire.tenant.relation', 'tenant').'->'.config('sire.tenant.key', 'id'),
            'resolver'       => 'resolver → '.(config('sire.tenant.resolver') ?: 'NOT SET'),
            'callable'       => 'callable → '.(config('sire.tenant.callable') ?: 'NOT SET'),
            'single_tenant'  => 'single_tenant → tenant '.config('sire.tenant.tenant_id'),
        };

        if ($strategy === 'resolver' && ! config('sire.tenant.resolver')) {
            return $this->result('Tenancy', 'fail', $detail,
                'The strategy is resolver but no resolver class is configured.',
                "Set config('sire.tenant.resolver').");
        }

        if ($strategy === 'callable' && ! config('sire.tenant.callable')) {
            return $this->result('Tenancy', 'fail', $detail,
                'The strategy is callable but nothing is configured to call.',
                "Set config('sire.tenant.callable') to a 'Class@method'.");
        }

        if ($strategy === 'single_tenant') {
            return $this->result('Tenancy', 'warn', $detail,
                "SIRE is treating this as a SINGLE-TENANT application. Correct for a\n"
                ."single-company CRM; catastrophic for a shared one -- every issue would be\n"
                .'visible to every user.',
                'If this application serves multiple customers, change the strategy now.');
        }

        return $this->result('Tenancy', 'pass', $detail.'  (verify by hand: see docs/TENANCY.md)');
    }

    /** Who can reach SIRE at all. Unmapped means nobody. */
    private function checkLoginTypes(): array
    {
        if (! \Sire\Support\SireLoginType::isConfigured()) {
            return $this->result(
                'Login types',
                'fail',
                'No roles are mapped, so NO account can access SIRE.',
                "SIRE classifies host roles into ADMIN / INTERNAL_USER / CUSTOMER / VENDOR.\n"
                ."Only the first two get access, and a role in no list is denied -- which is\n"
                .'currently every role.',
                "Set config('sire.login_types'), or run: php artisan sire:install",
            );
        }

        $counts = [];

        foreach (\Sire\Support\SireLoginType::ALL as $type) {
            $counts[] = $type.'='.count((array) config("sire.login_types.{$type}", []));
        }

        $engineering = count((array) config('sire.login_types.admin', []))
            + count((array) config('sire.login_types.internal_user', []));

        if ($engineering === 0) {
            return $this->result('Login types', 'fail', implode(' · ', $counts),
                'Roles are mapped, but none to ADMIN or INTERNAL_USER — so nobody has access.',
                "Add at least one role to config('sire.login_types.admin').");
        }

        return $this->result('Login types', 'pass', implode(' · ', $counts).'  (customers and vendors are blocked)');
    }

    /** SIRE core must not have grown a host dependency. */
    private function checkArchitecture(): array
    {
        $checker = new \Sire\Installation\ArchitectureChecker(dirname(__DIR__, 3));
        $checks = $checker->run();

        if ($checker->passed($checks)) {
            return $this->result('Architecture', 'pass', 'No host-specific hardcoding in SIRE core.');
        }

        $failed = array_values(array_filter($checks, static fn (array $c) => $c['status'] === 'FAIL'));

        return $this->result(
            'Architecture',
            'warn',
            count($failed).' architecture check(s) failing.',
            'SIRE core has picked up a dependency on something specific to one host.',
            'php artisan sire:architecture',
        );
    }

    /**
     * Is the deployed SPA the same build as the deployed backend?
     *
     * This deployment ships in TWO independent rsyncs -- backend/ and then
     * frontend/dist/ into public/. Nothing forces them to happen together, and a
     * backend-only deploy leaves the browser running an older bundle against
     * newer PHP.
     *
     * That failure is invisible from every other angle. The API is healthy, the
     * tables are there, the routes resolve, the doctor is green -- and a field
     * the backend offers simply never renders, because the JavaScript that draws
     * it was built before the field existed. It cost a live afternoon: triage had
     * no Category picker while /dashboard/options was returning all eight.
     *
     * So both halves carry the same stamp and this compares them:
     *
     *   build-id.txt         written next to artisan, ships with backend/
     *   public/build-id.txt  built into the SPA, ships with frontend/dist/
     *
     * Absent on a dev box, which is not a fault -- the check only speaks when it
     * can actually tell, and never fails the run: a version skew is a warning to
     * act on, not a reason to refuse to report everything else.
     */
    private function checkBuildParity(): array
    {
        $backendFile  = base_path('build-id.txt');
        $frontendFile = public_path('build-id.txt');

        if (! is_file($backendFile) || ! is_file($frontendFile)) {
            return $this->result(
                'Build parity',
                'info',
                'not stamped — cannot compare backend and SPA builds',
                "This deployment ships the backend and the built SPA as two separate rsyncs,\n"
                ."so they can silently drift a version apart.",
                'See DEPLOY-NEXFORE.md §9 — write build-id.txt on both sides at deploy time.',
            );
        }

        $backend  = trim((string) file_get_contents($backendFile));
        $frontend = trim((string) file_get_contents($frontendFile));

        if ($backend === '' || $frontend === '') {
            return $this->result('Build parity', 'warn', 'a build-id.txt is empty');
        }

        if ($backend === $frontend) {
            return $this->result('Build parity', 'pass', "backend and SPA are both {$backend}");
        }

        return $this->result(
            'Build parity',
            'warn',
            "backend is {$backend}, the SPA is {$frontend}",
            "The browser is running a different build from the API. Fields the backend\n"
            ."offers may not render at all, and nothing else in this report will show it.",
            'cd frontend && npm run build, then rsync frontend/dist/ into public/ — and hard-refresh.',
        );
    }

    /** Whether the SPA has been published, and whether the host bridge is wired. */
    private function checkFrontend(): array
    {
        // Two shapes are valid. A Blade host publishes into resources/js/sire;
        // a split repo like this one (backend/ + frontend/ side by side) has its
        // SPA in the sibling frontend, which is where checkWorkflow already
        // looks for the generated mirror. Accept either, so the doctor reports
        // where the SPA IS rather than where one kind of host would put it.
        $published = (function_exists('resource_path') && is_dir(resource_path('js/sire')))
            || is_dir(base_path('../frontend/src/modules/sire'));

        if (! $published) {
            return $this->result(
                'Frontend',
                'warn',
                'SIRE\'s SPA has not been published.',
                "The API is fully functional without it -- this affects only the SIRE screens\n"
                .'and the global Report Issue button.',
                'php artisan vendor:publish --tag=sire-frontend',
            );
        }

        return $this->result('Frontend', 'pass', 'SPA published.');
    }

    private function checkTables(): array
    {
        $missing = array_values(array_filter(self::TABLES, fn (string $t) => ! Schema::hasTable($t)));

        return $missing === []
            ? $this->result('Tables', 'pass', count(self::TABLES).' present')
            : $this->result('Tables', 'fail', 'missing: '.implode(', ', $missing).' — run php artisan migrate');
    }

    private function checkColumns(): array
    {
        $missing = [];

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        // The silent failure this whole command exists for: the table is there, so
        // the guard passed, but a later ALTER never ran.
        return $missing === []
            ? $this->result('Columns', 'pass', 'later migrations applied')
            : $this->result('Columns', 'fail', 'missing: '.implode(', ', $missing).' — a migration was skipped, not failed');
    }

    private function checkRoutes(): array
    {
        $count = collect(Route::getRoutes())->filter(
            fn ($r) => str_starts_with($r->uri(), 'api/sire'),
        )->count();

        return $count > 0
            ? $this->result('Routes', 'pass', "{$count} SIRE routes registered")
            : $this->result('Routes', 'fail', 'no api/sire routes — is routes/sire.php required from routes/api.php?');
    }

    private function checkStorage(): array
    {
        try {
            // Existence and writability only. Writes nothing.
            $disk = Storage::disk('attachments');
            $writable = is_writable($disk->path(''));

            return $writable
                ? $this->result('Storage', 'pass', 'attachments disk present and writable')
                : $this->result('Storage', 'fail', 'attachments disk is not writable');
        } catch (Throwable $e) {
            return $this->result('Storage', 'fail', "attachments disk unavailable: {$e->getMessage()}");
        }
    }

    private function checkScheduler(): array
    {
        // SIRE's own commands must be registered. Whether cron actually calls
        // schedule:run is a server question this cannot answer — see the deploy doc.
        $registered = collect(\Illuminate\Support\Facades\Artisan::all())->keys()
            ->filter(fn (string $c) => str_starts_with($c, 'sire:'))
            ->values();

        $expected = ['sire:run-schedule', 'sire:index-issues'];
        $missing = array_diff($expected, $registered->all());

        return $missing === []
            ? $this->result('Scheduled commands', 'pass', 'registered — confirm cron calls schedule:run')
            : $this->result('Scheduled commands', 'warn', 'not registered: '.implode(', ', $missing));
    }

    private function checkQueue(): array
    {
        $connection = config('queue.default');

        // SIRE dispatches nothing to a queue. This reports the setting so a deploy
        // does not silently rely on a worker that does not exist.
        return $connection === 'sync'
            ? $this->result('Queue', 'pass', 'sync — SIRE requires no worker')
            : $this->result('Queue', 'warn', "queue.default is '{$connection}'; SIRE needs no worker, but the notification engine may");
    }

    private function checkWorkflow(): array
    {
        $states = count(SireWorkflow::STATES);
        $mirror = base_path('../frontend/src/lib/sire/workflow.generated.js');

        if (! is_file($mirror)) {
            return $this->result('Workflow', 'warn', "{$states} states; frontend mirror not found at the expected path");
        }

        $stale = ! str_contains(file_get_contents($mirror), '"production_validated"');

        return $stale
            ? $this->result('Workflow', 'warn', 'frontend mirror looks stale — run php artisan sire:export-workflow')
            : $this->result('Workflow', 'pass', "{$states} states, mirror present");
    }

    private function checkTenantIsolation(): array
    {
        if (! Schema::hasTable('sire_reports')) {
            return $this->result('Tenant isolation', 'warn', 'no register yet');
        }

        // The one data-integrity question worth asking on every deploy: scoping in
        // this codebase is opt-in, and a NULL tenant_id row belongs to nobody and
        // matches no forTenant() query.
        $orphans = DB::table('sire_reports')->whereNull('tenant_id')->count();

        return $orphans === 0
            ? $this->result('Tenant isolation', 'pass', 'no rows without a tenant')
            : $this->result('Tenant isolation', 'fail', "{$orphans} sire_reports row(s) have no tenant_id");
    }

    private function checkAi(SireAiGateway $ai): array
    {
        $tenants = Schema::hasTable('sire_reports')
            ? DB::table('sire_reports')->distinct()->pluck('tenant_id')->filter()
            : collect();

        $enabled = $tenants->filter(fn ($t) => $ai->isEnabled((int) $t))->count();

        // AI is off by default and every capability is local. Reported so a deploy
        // knows what is on, not because anything needs provisioning.
        return $this->result('AI', 'pass', $enabled === 0
            ? 'disabled for all tenants (default) — no provider required'
            : "{$enabled} tenant(s) have AI enabled; all capabilities run locally");
    }
}
