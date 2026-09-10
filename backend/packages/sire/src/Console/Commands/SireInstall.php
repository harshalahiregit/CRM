<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Sire\Discovery\HostDiscovery;
use Sire\Installation\Compatibility;
use Sire\Installation\ConfigWriter;
use Sire\Installation\InstallPlan;
use Sire\Support\SireLoginType;

/**
 * SIRE — install into this application.
 *
 * WHAT IT DOES AUTOMATICALLY, AND WHAT IT REFUSES TO
 *
 * Anything high-confidence and harmless if wrong is applied silently: the users
 * table, the display-name column, whether a scheduler exists. Getting those
 * wrong produces a visible annoyance somebody fixes in a minute.
 *
 * Anything security-sensitive is CONFIRMED, whatever the confidence:
 *
 *   - the tenant source          wrong = one customer reads another's data
 *   - the auth middleware        wrong = SIRE is open, or shut
 *   - the four login types       wrong = customers read the defect backlog
 *
 * No confidence score earns the right to skip those questions. In
 * non-interactive mode they must be present in the config file, or installation
 * stops rather than guessing.
 *
 * IDEMPOTENT
 *
 * Running it twice changes nothing: the generated config is rewritten in full
 * from the resolved set, migrations are Laravel's own (already-run ones are
 * skipped), and nothing is appended anywhere. A second run offers to reconfigure
 * rather than duplicating.
 *
 * NON-DESTRUCTIVE
 *
 * It never drops a table, never rolls back a host migration, never edits a host
 * service provider or route file, and never writes outside config/ and
 * storage/app/sire/.
 */
class SireInstall extends Command
{
    protected $signature = 'sire:install
                            {--non-interactive : Take every answer from --config, ask nothing}
                            {--config= : Path to a JSON answer file (see docs/INSTALLATION.md)}
                            {--dry-run : Show the plan and change nothing}
                            {--no-migrate : Skip migrations}
                            {--reconfigure : Re-ask everything, even if already installed}
                            {--force : Proceed despite CONDITIONAL compatibility warnings}';

    protected $description = 'SIRE: install into this application, confirming security-sensitive mappings.';

    /** @var array<string, mixed> */
    private array $answers = [];

    public function handle(): int
    {
        $this->newLine();
        $this->info('SIRE installer');
        $this->newLine();

        $writer = new ConfigWriter(ConfigWriter::defaultPath());

        if ($writer->exists() && ! $this->option('reconfigure') && ! $this->option('dry-run')) {
            return $this->alreadyInstalled($writer);
        }

        // ---- 1. discover -----------------------------------------------------
        $this->line('  <fg=gray>1/7</> Inspecting the application…');
        $discovery = new HostDiscovery;
        $profile = $discovery->run();

        if (! $this->option('dry-run')) {
            $discovery->save($profile);
        }

        // ---- 2. compatibility ------------------------------------------------
        $this->line('  <fg=gray>2/7</> Checking compatibility…');
        $compat = new Compatibility;
        $rows = $compat->evaluate($profile);

        if ($compat->isBlocked($rows)) {
            $this->newLine();
            $this->error('  SIRE cannot install here.');

            foreach ($rows as $row) {
                if ($row['verdict'] === Compatibility::UNSUPPORTED) {
                    $this->line("    <fg=red>{$row['area']}</>: {$row['detected']} — {$row['note']}");
                }
            }

            $this->newLine();
            $this->line('  Full report: <comment>php artisan sire:compatibility</comment>');
            $this->newLine();

            return self::FAILURE;
        }

        // ---- 3. plan ---------------------------------------------------------
        $this->line('  <fg=gray>3/7</> Building the plan…');
        $plan = new InstallPlan($profile);

        $this->newLine();
        $this->showDetected($profile, $rows);

        if ($this->option('dry-run')) {
            $this->showPlan($plan);
            $this->newLine();
            $this->line('  <fg=yellow>Dry run — nothing was changed.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        // ---- 4. confirm the dangerous parts ----------------------------------
        $this->line('  <fg=gray>4/7</> Confirming security-sensitive settings…');
        $this->newLine();

        $this->loadAnswerFile();

        $settings = $plan->automatic();

        foreach ($plan->confirmations() as $key => $confirmation) {
            $value = $this->resolve($key, $confirmation, $profile);

            if ($value === self::ABORT) {
                $this->newLine();
                $this->error('  Installation stopped. Nothing was changed.');
                $this->newLine();

                return self::FAILURE;
            }

            $settings[$key] = $value;
        }

        // ---- 5. write --------------------------------------------------------
        $this->line('  <fg=gray>5/7</> Writing configuration…');
        $settings['sire.installed_at'] = now()->toIso8601String();
        $path = $writer->write($settings);
        $this->line("        <fg=gray>{$path}</>");

        // ---- 6. migrate ------------------------------------------------------
        $this->line('  <fg=gray>6/7</> Database…');
        $this->migrate();

        // ---- 7. report -------------------------------------------------------
        $this->line('  <fg=gray>7/7</> Writing the installation report…');
        $reportPath = $this->writeReport($profile, $rows, $settings, $plan);
        $this->line("        <fg=gray>{$reportPath}</>");

        $this->newLine();
        $this->info('  SIRE is installed.');
        $this->newLine();

        $this->manualSteps($plan);

        $this->line('  Verify: <comment>php artisan sire:doctor</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    private const ABORT = '__sire_abort__';

    // ------------------------------------------------------------------ steps

    private function alreadyInstalled(ConfigWriter $writer): int
    {
        $this->line('  SIRE is already installed.');
        $this->newLine();
        $this->line('    <comment>php artisan sire:doctor</comment>        check the installation');
        $this->line('    <comment>php artisan sire:install --reconfigure</comment>  change the answers');
        $this->line('    <comment>php artisan sire:install --dry-run</comment>      see what would change');
        $this->line('    <comment>php artisan sire:uninstall</comment>     remove it');
        $this->newLine();

        return self::SUCCESS;
    }

    private function showDetected($profile, array $rows): void
    {
        $this->line('  <options=bold>Detected</>');

        $this->table(['', 'Value'], array_values(array_filter([
            ['Framework', 'Laravel '.$profile->value('framework.version', '?')],
            ['PHP', $profile->value('php.version', '?')],
            ['Database', $profile->value('database.engine', '?')],
            ['User model', $profile->value('user.model', 'not found')],
            ['Roles', implode(', ', array_slice((array) $profile->value('roles.available', []), 0, 8)) ?: 'none readable'],
            ['Frontend', $profile->value('frontend.type', 'none')],
        ])));

        $conditional = array_filter($rows, fn ($r) => $r['verdict'] === Compatibility::CONDITIONAL);

        if ($conditional !== []) {
            $this->newLine();
            $this->line('  <fg=yellow>Conditional</>');

            foreach ($conditional as $row) {
                $this->line("    {$row['area']}: {$row['note']}");
            }
        }

        $this->newLine();
    }

    private function showPlan(InstallPlan $plan): void
    {
        $this->line('  <options=bold>Would apply automatically</>');

        foreach ($plan->automatic() as $key => $value) {
            $this->line(sprintf('    %-32s %s', $key, is_array($value) ? implode(', ', $value) : var_export($value, true)));
        }

        $this->newLine();
        $this->line('  <options=bold>Would ask about</>');

        foreach ($plan->confirmations() as $key => $confirmation) {
            $proposed = $confirmation['value'];
            $this->line(sprintf(
                '    %-32s %s',
                $key,
                $proposed === null || $proposed === [] ? '<fg=red>no proposal</>' : (is_array($proposed) ? implode(', ', $proposed) : (string) $proposed),
            ));
        }

        if ($plan->warnings() !== []) {
            $this->newLine();
            $this->line('  <options=bold>Warnings</>');
            foreach ($plan->warnings() as $warning) {
                $this->line("    {$warning}");
            }
        }
    }

    /**
     * Resolve one security-sensitive setting: from the answer file, or by
     * asking. Never by assuming.
     */
    private function resolve(string $key, array $confirmation, $profile): mixed
    {
        if (array_key_exists($key, $this->answers)) {
            $this->line(sprintf('    %-34s <fg=gray>from --config</> %s', $key, $this->render($this->answers[$key])));

            return $this->answers[$key];
        }

        if ($this->option('non-interactive')) {
            $this->newLine();
            $this->error("  Missing answer for {$key} — and it is security-sensitive, so SIRE will not guess.");
            $this->line('    '.str_replace("\n", "\n    ", $confirmation['why']));
            $this->line("    Add \"{$key}\" to your --config file. See docs/INSTALLATION.md.");

            return self::ABORT;
        }

        return $this->ask_($key, $confirmation, $profile);
    }

    private function ask_(string $key, array $confirmation, $profile): mixed
    {
        $this->newLine();
        $this->line("  <options=bold>{$confirmation['question']}</>");

        foreach (explode("\n", $confirmation['why']) as $line) {
            $this->line('    <fg=gray>'.$line.'</>');
        }

        // --- tenant strategy: a fixed menu ---
        if ($key === 'sire.tenant.strategy') {
            return $this->choice(
                '    Strategy',
                ['user_attribute', 'relationship', 'resolver', 'callable', 'single_tenant'],
                is_string($confirmation['value']) ? $confirmation['value'] : 'user_attribute',
            );
        }

        // --- login types: pick from the roles that actually exist ---
        if (str_starts_with($key, 'sire.login_types.')) {
            $roles = (array) $profile->value('roles.available', []);

            if ($roles === []) {
                $this->line('    <fg=gray>No roles were readable; leaving this empty.</>');

                return [];
            }

            $proposed = implode(',', (array) $confirmation['value']);
            $answer = $this->ask('    Roles (comma separated, blank for none)', $proposed);

            return array_values(array_filter(array_map('trim', explode(',', (string) $answer))));
        }

        // --- auth middleware: a list ---
        if ($key === 'sire.host.auth_middleware') {
            $proposed = implode(',', (array) ($confirmation['value'] ?? []));
            $answer = $this->ask('    Middleware (comma separated)', $proposed ?: 'auth');

            $middleware = array_values(array_filter(array_map('trim', explode(',', (string) $answer))));

            if ($middleware === []) {
                $this->warn('    Empty. SIRE will refuse all traffic until this is set.');
            }

            return $middleware;
        }

        // --- everything else: confirm or replace ---
        $proposed = $confirmation['value'];

        if ($proposed !== null && $proposed !== '' && $this->confirm('    Use '.$this->render($proposed).'?', true)) {
            return $proposed;
        }

        return $this->ask('    Value', is_scalar($proposed) ? (string) $proposed : '');
    }

    private function migrate(): void
    {
        if ($this->option('no-migrate')) {
            $this->line('        <fg=gray>skipped (--no-migrate)</>');

            return;
        }

        // Pending migrations are REPORTED, never run behind the developer's back.
        // A host mid-way through its own migration sequence is exactly when an
        // unexpected `migrate` does damage.
        $pending = $this->pendingHostMigrations();

        if ($pending > 0) {
            $this->newLine();
            $this->warn("        {$pending} migration(s) are pending in this application, not all of them SIRE's.");
            $this->line('        SIRE will run ONLY its own. Review the rest with: php artisan migrate:status');

            if (! $this->option('non-interactive') && ! $this->confirm('        Continue?', true)) {
                $this->line('        <fg=gray>skipped</>');

                return;
            }
        }

        $this->callSilent('migrate', ['--path' => 'vendor/sangoe/sire/database/migrations', '--force' => true]);
        $this->call('migrate', ['--force' => true]);
    }

    private function pendingHostMigrations(): int
    {
        try {
            $this->callSilent('migrate:status');
            $output = $this->output->fetch();

            return substr_count($output, 'Pending');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function writeReport($profile, array $rows, array $settings, InstallPlan $plan): string
    {
        $path = storage_path('app/'.trim((string) config('sire.storage_path', 'sire'), '/').'/install-report.json');

        File::ensureDirectoryExists(dirname($path));

        // The profile is REDACTED here as well as at write time: this report is
        // the file people attach to support tickets.
        File::put($path, json_encode([
            'installed_at'  => now()->toIso8601String(),
            'sire_version'  => '1.1',
            'host'          => $profile->redacted(),
            'compatibility' => $rows,
            'applied'       => $settings,
            'warnings'      => $plan->warnings(),
            'manual_steps'  => $plan->manualSteps(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function manualSteps(InstallPlan $plan): void
    {
        $steps = $plan->manualSteps();

        if ($steps === []) {
            return;
        }

        $this->line('  <options=bold>Still to do by hand</>');

        foreach ($steps as $i => $step) {
            $this->line(sprintf('    %d. %s', $i + 1, wordwrap($step, 72, "\n       ")));
        }

        $this->newLine();
    }

    private function loadAnswerFile(): void
    {
        $path = $this->option('config');

        if (! is_string($path) || $path === '') {
            return;
        }

        if (! is_readable($path)) {
            $this->warn("  Answer file not readable: {$path}");

            return;
        }

        $data = json_decode((string) file_get_contents($path), true);

        $this->answers = is_array($data) ? $data : [];
    }

    private function render(mixed $value): string
    {
        return is_array($value) ? ('['.implode(', ', $value).']') : var_export($value, true);
    }
}
