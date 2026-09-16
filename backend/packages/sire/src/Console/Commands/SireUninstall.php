<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — remove SIRE, in two clearly separated stages.
 *
 * THE DISTINCTION THIS COMMAND EXISTS TO ENFORCE
 *
 *   DISABLE   Stop SIRE running. Removes generated config and discovery
 *             artefacts. Touches NO data — every issue, comment and audit entry
 *             survives, and re-installing brings them all back.
 *
 *   PURGE     Drop SIRE's own tables. Irreversible. Requires a second, typed
 *             confirmation, and is never the default.
 *
 * Defaulting to disable is not timidity. Most "uninstalls" are really "turn it
 * off while we decide", and a command that deleted a year of engineering history
 * because someone wanted to quieten a menu item would be indefensible.
 *
 * WHAT IT WILL NEVER DO
 *
 * It never drops, alters or empties a table SIRE does not own. The purge list is
 * a hardcoded set of `sire_`-prefixed names, checked against a prefix at runtime
 * — not a pattern match, not a "tables SIRE thinks it created" heuristic. Your
 * users, tenants, tickets, notes, audit trail and attachments are not reachable
 * from this command by any code path.
 */
class SireUninstall extends Command
{
    protected $signature = 'sire:uninstall
                            {--purge : ALSO drop SIRE tables and delete all SIRE data}
                            {--force : Skip confirmations (still requires --purge for data)}
                            {--dry-run : Show what would happen and change nothing}';

    protected $description = 'SIRE: disable SIRE. Data is kept unless you explicitly --purge.';

    /**
     * Every table SIRE owns. Hardcoded and exhaustive: a heuristic here could
     * name a host table that merely looks like SIRE's.
     */
    private const SIRE_TABLES = [
        'sire_audit_events', 'sire_notes', 'sire_settings',
        'sire_test_cases', 'sire_issue_tokens', 'sire_ai_suggestions',
        'sire_release_overrides', 'sire_actions', 'sire_release_notes',
        'sire_kb_links', 'sire_report_links', 'sire_recurrence_groups',
        'sire_root_causes', 'sire_releases', 'sire_work_cycles',
        'sire_report_contexts', 'sire_approvals', 'sire_report_watchers',
        'sire_report_assignees', 'sire_reports',
        'sire_severities', 'sire_report_categories',
    ];

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $this->newLine();
        $this->info('SIRE uninstall');
        $this->newLine();

        // ---- stage 1: disable -------------------------------------------------
        $artefacts = array_filter([
            config_path('sire-host.php'),
            config_path('sire.php'),
            storage_path('app/'.trim((string) config('sire.storage_path', 'sire'), '/')),
        ], 'file_exists');

        $this->line('  <options=bold>Stage 1 — disable</>');
        $this->line('    Removes generated configuration and discovery artefacts.');
        $this->line('    <fg=green>No data is touched.</>');
        $this->newLine();

        foreach ($artefacts as $path) {
            $this->line('      '.$path);
        }

        if ($artefacts === []) {
            $this->line('      <fg=gray>nothing to remove</>');
        }

        $this->newLine();
        $this->line('    Also remove the provider registration by hand:');
        $this->line('      <fg=gray>bootstrap/providers.php → Sire\\SireServiceProvider::class</>');
        $this->line('      <fg=gray>(or composer remove sangoe/sire)</>');
        $this->newLine();

        // ---- stage 2: purge ---------------------------------------------------
        $existing = array_values(array_filter(self::SIRE_TABLES, fn (string $t) => $this->tableExists($t)));

        $this->line('  <options=bold>Stage 2 — purge data</> '.($this->option('purge') ? '<fg=red>REQUESTED</>' : '<fg=green>not requested</>'));

        if ($existing === []) {
            $this->line('    <fg=gray>No SIRE tables found.</>');
        } else {
            $this->line(sprintf('    %d SIRE table(s): %s', count($existing), implode(', ', array_slice($existing, 0, 5)).(count($existing) > 5 ? ', …' : '')));
            $this->line($this->option('purge')
                ? '    <fg=red>These will be DROPPED. Every issue, comment and audit entry is deleted.</>'
                : '    <fg=green>These will be KEPT. Re-installing restores everything.</>');
        }

        $this->newLine();

        if ($dry) {
            $this->line('  <fg=yellow>Dry run — nothing was changed.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        // ---- execute ----------------------------------------------------------
        if (! $this->option('force') && ! $this->confirm('  Disable SIRE now?', false)) {
            $this->line('  <fg=gray>Cancelled. Nothing was changed.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($artefacts as $path) {
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
            $this->line("    removed {$path}");
        }

        if (! $this->option('purge')) {
            $this->newLine();
            $this->info('  SIRE is disabled. All SIRE data was kept.');
            $this->line('  To delete the data as well: <comment>php artisan sire:uninstall --purge</comment>');
            $this->newLine();

            return self::SUCCESS;
        }

        // A typed confirmation, not a y/n. --force does not cover this: the whole
        // point is that destroying a year of engineering history should take a
        // deliberate act, not a flag someone copied from a runbook.
        $this->newLine();
        $this->error('  PURGE will permanently delete all SIRE data.');
        $this->line('  '.count($existing).' table(s) will be dropped. This cannot be undone.');
        $this->newLine();

        $typed = $this->ask('  Type DELETE SIRE DATA to confirm');

        if ($typed !== 'DELETE SIRE DATA') {
            $this->line('  <fg=green>Not confirmed. Tables were kept; SIRE is disabled.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($existing as $table) {
            // Belt and braces: even here, nothing without the sire_ prefix can
            // be dropped, whatever the constant list says.
            if (! str_starts_with($table, 'sire_')) {
                continue;
            }

            Schema::dropIfExists($table);
            $this->line("    dropped {$table}");
        }

        $this->newLine();
        $this->info('  SIRE removed and its data deleted. No host table was touched.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
