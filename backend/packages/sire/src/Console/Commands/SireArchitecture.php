<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Sire\Installation\ArchitectureChecker;

/**
 * SIRE — prove that SIRE core contains no host-specific hardcoding.
 *
 * The claim "SIRE is CRM-agnostic" is worth exactly as much as the check that
 * enforces it. This is that check, runnable on demand rather than asserted in a
 * document.
 *
 * The rules live in ArchitectureChecker, which is plain PHP with no Laravel in
 * it — so the same five checks run here, in the test suite, and in CI, against
 * this package or a fork of it. A rule that only runs inside an artisan command
 * is a rule that stops running the moment someone vendors the code.
 */
class SireArchitecture extends Command
{
    protected $signature = 'sire:architecture
                            {--json : Machine-readable output}
                            {--show-all : List findings even for checks that pass}';

    protected $description = 'SIRE: check that SIRE core has no host-specific hardcoding.';

    public function handle(): int
    {
        $checker = new ArchitectureChecker(dirname(__DIR__, 3));
        $checks = $checker->run();

        if ($this->option('json')) {
            $this->line(json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $checker->passed($checks) ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info('SIRE architecture check');
        $this->line('  Does SIRE core depend on anything specific to one CRM?');
        $this->newLine();

        foreach ($checks as $check) {
            $badge = $check['status'] === 'PASS' ? '<fg=green>PASS</>' : '<fg=red>FAIL</>';

            $this->line("  {$badge}  {$check['name']}");
            $this->line("        <fg=gray>{$check['detail']}</>");

            $show = $check['status'] === 'FAIL' || $this->option('show-all');

            if ($show && $check['findings'] !== []) {
                foreach (array_slice($check['findings'], 0, 20) as $finding) {
                    $this->line("          {$finding}");
                }

                if (count($check['findings']) > 20) {
                    $this->line('          … '.(count($check['findings']) - 20).' more');
                }
            }

            $this->newLine();
        }

        if ($checker->passed($checks)) {
            $this->line('  <fg=green>PASS</> — SIRE core is free of host-specific hardcoding.');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->line('  <fg=red>FAIL</> — host-specific code found in SIRE core.');
        $this->line('  Move the dependency behind an SDK contract: <comment>docs/ADAPTERS.md</comment>');
        $this->newLine();

        return self::FAILURE;
    }
}
