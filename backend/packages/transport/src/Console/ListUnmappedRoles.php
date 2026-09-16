<?php

namespace Transport\Console;

use Illuminate\Console\Command;
use Transport\Access\PermissionRegistry;
use Transport\Access\RoleResolver;

/**
 * Which accounts would transport refuse, and why.
 *
 * The role map ships empty on purpose, which means that on the day transport
 * routes go live everybody except an administrator is locked out. That is the
 * intended behaviour, but it should be discovered by running this rather than
 * by a dispatcher who cannot dispatch.
 *
 * It also answers the question the map has to be written against: which role
 * values do accounts in this tenant actually hold? Writing the mapping from
 * memory produces keys that match nothing.
 */
class ListUnmappedRoles extends Command
{
    protected $signature = 'transport:roles {tenant : Tenant id to inspect}';

    protected $description = 'List CRM role values that have no transport role mapped, and the accounts they would refuse';

    public function handle(RoleResolver $resolver): int
    {
        $tenantId = (int) $this->argument('tenant');
        $unmapped = $resolver->unmapped($tenantId);
        $mapped   = config('transport.roles.map', []);

        $this->line('');
        $this->info("Transport role mapping — tenant {$tenantId}");
        $this->line('');

        if ($mapped) {
            $this->line('Mapped:');
            foreach ($mapped as $from => $to) {
                $this->line(sprintf('  %-24s → %s', $from, $to));
            }
            $this->line('');
        } else {
            $this->warn('No role mapping is configured.');
            $this->line('Only users.role = admin resolves to a transport role. Everyone else is refused.');
            $this->line('');
        }

        if (! $unmapped) {
            $this->info('No unmapped active accounts.');

            return self::SUCCESS;
        }

        $this->line('Unmapped — these accounts can do nothing in transport:');
        $this->line('');
        $this->table(
            ['CRM role value', 'Active accounts'],
            collect($unmapped)->map(fn ($count, $role) => [$role, $count])->values()->all()
        );

        $this->line('Choose a transport role for each, in config/transport.php:');
        $this->line('  '.implode(', ', PermissionRegistry::ROLES));
        $this->line('');
        $this->warn('That mapping decides who may approve advances and expenses. It needs sign-off, not a guess.');

        return self::SUCCESS;
    }
}
