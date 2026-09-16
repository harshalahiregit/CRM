<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Sire\Discovery\Finding;
use Sire\Discovery\HostDiscovery;

/**
 * SIRE — inspect this application and write a host profile.
 *
 * READ-ONLY. Reads the schema, the container, config, composer.json,
 * package.json and the filesystem; writes exactly one file, in storage/app.
 * No migration runs, no row is touched, no cache is flushed, no event fires.
 * Safe on production.
 *
 * The profile it writes is what `sire:compatibility`, `sire:install` and
 * `sire:doctor` all read, which is why discovery is a separate step: inspecting
 * a schema on every page load would be indefensible, so it happens once, on
 * demand, and everything else consults the result.
 */
class SireDiscover extends Command
{
    protected $signature = 'sire:discover
                            {--json : Machine-readable output}
                            {--show : Print every finding, not just the summary}';

    protected $description = 'SIRE: inspect the host application and write a profile. Read-only — changes nothing.';

    public function handle(): int
    {
        $discovery = new HostDiscovery;

        $profile = $discovery->run();
        $path = $discovery->save($profile);

        if ($this->option('json')) {
            $this->line(json_encode($profile->redacted(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('SIRE host discovery');
        $this->line('  read-only · nothing was modified');
        $this->newLine();

        $this->summary($profile);

        if ($this->option('show')) {
            $this->newLine();
            $this->everything($profile);
        }

        $this->newLine();
        $this->line("  Profile written to: <comment>{$path}</comment>");
        $this->line('  This file describes your auth, tenancy and role model. It is not web-reachable, and it contains no secrets.');
        $this->newLine();
        $this->line('  Next: <comment>php artisan sire:compatibility</comment>');
        $this->newLine();

        $errors = $profile->section('_errors');

        if ($errors !== []) {
            $this->warn('  Some detectors failed. The rest of the profile is still usable:');
            foreach ($errors as $key => $finding) {
                $this->line("    {$key}: {$finding->value}");
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function summary($profile): void
    {
        $rows = [];

        foreach ([
            'Framework'      => ['framework.version', fn ($v) => 'Laravel '.$v],
            'PHP'            => ['php.version', null],
            'Database'       => ['database.engine', null],
            'Auth guards'    => ['auth.guards', fn ($v) => implode(', ', (array) $v)],
            'User model'     => ['user.model', null],
            'Tenant column'  => ['tenant.attribute', null],
            'Tenant package' => ['tenant.package', null],
            'Roles found'    => ['roles.available', fn ($v) => count((array) $v).' — '.implode(', ', array_slice((array) $v, 0, 6))],
            'Permissions'    => ['permissions.provider', null],
            'Frontend'       => ['frontend.type', null],
        ] as $label => [$key, $format]) {
            $finding = $profile->get($key);

            $value = $finding->isPresent()
                ? ($format ? $format($finding->value) : (is_array($finding->value) ? implode(', ', $finding->value) : (string) $finding->value))
                : '<fg=gray>not found</>';

            $rows[] = [$label, $value, $this->badge($finding->confidence)];
        }

        $this->table(['', 'Detected', 'Confidence'], $rows);

        // The two that decide whether SIRE is safe, called out rather than left
        // in a table for someone to notice.
        $this->newLine();

        $tenant = $profile->get('tenant.attribute');

        if (! $tenant->isPresent()) {
            $this->warn('  TENANCY: no tenant column found.');
            $this->line('    Normal for a single-tenant application, or one resolving tenancy from a subdomain.');
            $this->line('    sire:install will ask you to choose a strategy. It will not guess.');
        } elseif ($tenant->alternatives !== []) {
            $this->warn('  TENANCY: several candidates — '.implode(', ', array_merge([$tenant->value], $tenant->alternatives)));
            $this->line('    Only you know which identifies the TENANT rather than the customer a user works for.');
        } else {
            $this->line("  <fg=yellow>TENANCY</>: proposing <comment>users.{$tenant->value}</comment> — you will be asked to confirm this.");
        }

        $unclassified = (array) $profile->value('roles.unclassified', []);

        if ($unclassified !== []) {
            $this->newLine();
            $this->warn('  ROLES: '.count($unclassified).' role(s) matched no category and will have NO SIRE access:');
            $this->line('    '.implode(', ', $unclassified));
        }
    }

    private function everything($profile): void
    {
        $rows = [];

        foreach ($profile->findings as $key => $finding) {
            $value = $finding->value;

            $rows[] = [
                $key,
                is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
                $this->badge($finding->confidence),
                implode('; ', array_slice($finding->evidence, 0, 2)),
            ];
        }

        $this->table(['Finding', 'Value', 'Confidence', 'Evidence'], $rows);
    }

    private function badge(string $confidence): string
    {
        return match ($confidence) {
            Finding::HIGH   => '<fg=green>high</>',
            Finding::MEDIUM => '<fg=yellow>medium</>',
            Finding::LOW    => '<fg=red>low</>',
            default         => '<fg=gray>—</>',
        };
    }
}
