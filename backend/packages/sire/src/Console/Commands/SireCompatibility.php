<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Sire\Discovery\HostDiscovery;
use Sire\Installation\Compatibility;

/**
 * SIRE — can this application run SIRE, and what will it take?
 *
 * The first command to run. It answers honestly, in four verdicts, and MANUAL
 * is not a failure: it means SIRE works here once you write one small adapter.
 *
 * Only UNSUPPORTED blocks installation, and it is reserved for things that
 * genuinely cannot work — a PHP below SIRE's floor, a database SIRE cannot
 * reach. Everything else is a note about effort, not possibility.
 */
class SireCompatibility extends Command
{
    protected $signature = 'sire:compatibility
                            {--json : Machine-readable output}
                            {--fresh : Re-run discovery first}';

    protected $description = 'SIRE: report what this application supports, and what needs manual integration.';

    public function handle(): int
    {
        $profile = $this->option('fresh') ? (new HostDiscovery)->run() : HostDiscovery::load();

        if ($profile === null) {
            $this->warn('No host profile found — running discovery first.');
            $profile = (new HostDiscovery)->run();
            (new HostDiscovery)->save($profile);
            $this->newLine();
        }

        $rows = (new Compatibility)->evaluate($profile);

        if ($this->option('json')) {
            $this->line(json_encode(['rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return (new Compatibility)->isBlocked($rows) ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->info('SIRE compatibility');
        $this->newLine();

        $this->table(
            ['Area', 'Detected', 'Verdict', 'Note'],
            array_map(fn (array $r) => [$r['area'], $r['detected'], $this->badge($r['verdict']), $this->wrap($r['note'])], $rows),
        );

        $counts = array_count_values(array_column($rows, 'verdict'));

        $this->newLine();
        $this->line(sprintf(
            '  %d supported · %d conditional · %d manual · %d unsupported',
            $counts[Compatibility::SUPPORTED] ?? 0,
            $counts[Compatibility::CONDITIONAL] ?? 0,
            $counts[Compatibility::MANUAL] ?? 0,
            $counts[Compatibility::UNSUPPORTED] ?? 0,
        ));
        $this->newLine();

        if ((new Compatibility)->isBlocked($rows)) {
            $this->error('  SIRE cannot install here. Resolve the UNSUPPORTED rows above.');
            $this->newLine();

            return self::FAILURE;
        }

        $manual = $counts[Compatibility::MANUAL] ?? 0;

        $this->line($manual > 0
            ? "  SIRE can install. {$manual} area(s) need a small adapter — see docs/ADAPTERS.md."
            : '  SIRE can install with no custom adapters.');
        $this->newLine();
        $this->line('  Next: <comment>php artisan sire:install</comment>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function badge(string $verdict): string
    {
        return match ($verdict) {
            Compatibility::SUPPORTED   => '<fg=green>SUPPORTED</>',
            Compatibility::CONDITIONAL => '<fg=yellow>CONDITIONAL</>',
            Compatibility::MANUAL      => '<fg=cyan>MANUAL</>',
            default                    => '<fg=red>UNSUPPORTED</>',
        };
    }

    private function wrap(string $note): string
    {
        return wordwrap($note, 58, "\n", false);
    }
}
