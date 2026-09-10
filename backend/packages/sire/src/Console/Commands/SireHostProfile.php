<?php

namespace Sire\Console\Commands;

use Illuminate\Console\Command;
use Sire\Discovery\HostDiscovery;

/**
 * SIRE — print the host profile, safely.
 *
 * For pasting into a ticket or sending to whoever is helping you install SIRE.
 * It prints the REDACTED profile: discovery never reads secrets in the first
 * place, and this strips anything secret-shaped a second time.
 *
 * Never prints: passwords, tokens, API keys, database credentials, cookies,
 * session data or environment values.
 */
class SireHostProfile extends Command
{
    protected $signature = 'sire:host-profile
                            {--raw : Include evidence and alternatives}
                            {--path : Print only the file path}';

    protected $description = 'SIRE: print the discovered host profile. Safe to share — contains no secrets.';

    public function handle(): int
    {
        if ($this->option('path')) {
            $this->line(HostDiscovery::path());

            return self::SUCCESS;
        }

        $profile = HostDiscovery::load();

        if ($profile === null) {
            $this->error('No host profile found.');
            $this->line('  Run: php artisan sire:discover');

            return self::FAILURE;
        }

        $data = $profile->redacted();

        if (! $this->option('raw')) {
            foreach ($data['findings'] as $key => $finding) {
                unset($data['findings'][$key]['evidence'], $data['findings'][$key]['alternatives']);
            }
        }

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
