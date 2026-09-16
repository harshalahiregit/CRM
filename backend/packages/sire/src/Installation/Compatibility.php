<?php

namespace Sire\Installation;

use Sire\Discovery\Finding;
use Sire\Discovery\HostProfile;

/**
 * SIRE — what this host can run, stated from the code rather than from hope.
 *
 * Four verdicts, and the middle two carry the weight:
 *
 *   SUPPORTED       SIRE works here. Tested against a reference fixture.
 *   CONDITIONAL     SIRE works with a caveat that is stated, not implied.
 *   MANUAL          SIRE works once you write one adapter. Not a defect.
 *   UNSUPPORTED     SIRE will not work. Said plainly, with the reason.
 *
 * WHY MANUAL IS NOT A FAILURE
 *
 * A CRM resolving tenancy from a subdomain, or keeping users in an identity
 * service, is not an incompatible CRM — it is one where SIRE needs twenty lines
 * of adapter. Reporting that as UNSUPPORTED would be both wrong and
 * discouraging. Reporting it as SUPPORTED would be a lie a developer discovers
 * at the worst moment.
 *
 * THE VERSION FLOORS ARE REAL
 *
 * PHP 8.2 is not a preference: SIRE's DTOs use readonly properties in promoted
 * constructors (8.1) and enums in match arms throughout, and the codebase uses
 * `never` return types and disjunctive normal form types that 8.1 rejects.
 * Laravel 10 is the floor because SIRE's migrations use `Schema::hasColumn` on a
 * connection resolved the 10.x way. Both were derived by reading the code, not
 * by picking round numbers.
 */
final class Compatibility
{
    public const SUPPORTED   = 'SUPPORTED';
    public const CONDITIONAL = 'CONDITIONAL';
    public const MANUAL      = 'MANUAL';
    public const UNSUPPORTED = 'UNSUPPORTED';

    public const PHP_MIN     = '8.2.0';
    public const PHP_TESTED  = ['8.2', '8.3', '8.4'];
    public const LARAVEL_MIN = 10;
    public const LARAVEL_MAX = 12;

    /**
     * @param  HostProfile $profile
     * @return array<int, array{area: string, detected: string, verdict: string, note: string}>
     */
    public function evaluate(HostProfile $profile): array
    {
        return array_merge(
            $this->platform($profile),
            $this->authentication($profile),
            $this->tenancy($profile),
            $this->roles($profile),
            $this->frontend($profile),
            $this->optional($profile),
        );
    }

    private function platform(HostProfile $profile): array
    {
        $rows = [];

        $php = (string) $profile->value('php.version', PHP_VERSION);
        $rows[] = $this->row('PHP', $php, match (true) {
            version_compare($php, self::PHP_MIN, '<') => [self::UNSUPPORTED,
                'SIRE requires PHP '.self::PHP_MIN.'+. It uses readonly promoted properties and DNF types that earlier versions reject.'],
            in_array(substr($php, 0, 3), self::PHP_TESTED, true) => [self::SUPPORTED, 'Tested.'],
            default => [self::CONDITIONAL, 'Above the floor but not among the tested versions ('.implode(', ', self::PHP_TESTED).').'],
        });

        $laravel = (string) $profile->value('framework.version', '0');
        $major = (int) $laravel;
        $rows[] = $this->row('Laravel', $laravel, match (true) {
            $major === 0 => [self::UNSUPPORTED, 'Could not determine the framework version.'],
            $major < self::LARAVEL_MIN => [self::UNSUPPORTED, 'SIRE requires Laravel '.self::LARAVEL_MIN.'+.'],
            $major > self::LARAVEL_MAX => [self::CONDITIONAL,
                'Newer than the tested range ('.self::LARAVEL_MIN.'–'.self::LARAVEL_MAX.'). Likely fine; run the test suite.'],
            default => [self::SUPPORTED, 'Tested.'],
        });

        $driver = (string) $profile->value('database.driver', '');
        $engine = (string) $profile->value('database.engine', $driver);
        $rows[] = $this->row('Database', $engine ?: 'unknown', match ($driver) {
            'mysql'  => [self::SUPPORTED, 'MySQL and MariaDB are the tested engines.'],
            'sqlite' => [self::CONDITIONAL, 'Fine for development and the test suite. Not recommended for production SIRE.'],
            'pgsql'  => [self::CONDITIONAL, 'Migrations avoid MySQL-only syntax, but PostgreSQL is not covered by the test suite.'],
            ''       => [self::UNSUPPORTED, 'No reachable database connection.'],
            default  => [self::MANUAL, "Driver '{$driver}' is untested. Review the migrations before installing."],
        });

        return $rows;
    }

    private function authentication(HostProfile $profile): array
    {
        $packages = (array) $profile->value('auth.packages', []);
        $guards = (array) $profile->value('auth.guards', []);

        $detected = $packages !== [] ? implode(', ', $packages) : (implode(', ', $guards) ?: 'none');

        return [$this->row('Authentication', $detected, match (true) {
            in_array('laravel/sanctum', $packages, true)  => [self::SUPPORTED, "Set auth_middleware to ['auth:sanctum']."],
            in_array('laravel/passport', $packages, true) => [self::SUPPORTED, "Set auth_middleware to ['auth:api']."],
            $packages !== []                              => [self::SUPPORTED, 'A recognised guard package is installed.'],
            $guards !== []                                => [self::CONDITIONAL,
                'Guards are configured but no known package. Set auth_middleware to the guard SIRE should use.'],
            default => [self::MANUAL, 'No guards found. SIRE refuses all traffic until auth_middleware is set — by design.'],
        })];
    }

    private function tenancy(HostProfile $profile): array
    {
        $strategy = $profile->get('tenant.strategy');
        $attribute = $profile->get('tenant.attribute');
        $package = $profile->get('tenant.package');

        $detected = match (true) {
            $package->isPresent()   => (string) $package->value,
            $attribute->isPresent() => 'users.'.$attribute->value,
            default                 => 'none found',
        };

        return [$this->row('Tenancy', $detected, match (true) {
            $package->isPresent() => [self::MANUAL,
                'A tenancy package owns this. Implement SireTenantProvider, or point tenant.callable at its resolver.'],
            $attribute->atLeast(Finding::MEDIUM) && $attribute->alternatives === [] => [self::SUPPORTED,
                'Confirm this column is the TENANT and not the customer the user works for.'],
            $attribute->isPresent() => [self::CONDITIONAL,
                'Several tenant-shaped columns: '.implode(', ', array_merge([$attribute->value], $attribute->alternatives)).'. A human must choose.'],
            default => [self::MANUAL,
                'No tenant column found. Normal for a single-tenant app (strategy: single_tenant) or subdomain tenancy (strategy: callable).'],
        })];
    }

    private function roles(HostProfile $profile): array
    {
        $provider = $profile->get('permissions.provider');
        $roles = (array) $profile->value('roles.available', []);
        $unclassified = (array) $profile->value('roles.unclassified', []);

        $rows = [$this->row('Permissions', $provider->isPresent() ? (string) $provider->value : 'Laravel Gate / role column',
            $provider->isPresent()
                ? [self::SUPPORTED, 'Map its abilities to SIRE capability names, or use the coarse grants.']
                : [self::CONDITIONAL, 'No permission package. SIRE falls back to its own role rosters, which works.'])];

        $rows[] = $this->row('Roles', $roles === [] ? 'none readable' : count($roles).' found', match (true) {
            $roles === []          => [self::MANUAL, 'SIRE cannot propose a login-type mapping. Set config(\'sire.login_types\') by hand.'],
            $unclassified !== []   => [self::CONDITIONAL,
                count($unclassified).' role(s) matched no category and will have NO access until mapped: '.implode(', ', array_slice($unclassified, 0, 5))],
            default                => [self::SUPPORTED, 'Every role name matched a category. Confirm the mapping during install.'],
        });

        return $rows;
    }

    private function frontend(HostProfile $profile): array
    {
        $type = $profile->get('frontend.type');

        return [$this->row('Frontend', $type->isPresent() ? (string) $type->value : 'none detected', match ($type->value) {
            'react'         => [self::SUPPORTED, 'Full Report Issue experience.'],
            'inertia-react' => [self::SUPPORTED, 'Full experience; mount the provider in your persistent layout.'],
            'vue', 'inertia-vue' => [self::CONDITIONAL,
                'SIRE ships React components. Use the Blade widget, or call the API from your own Vue components.'],
            'livewire'      => [self::CONDITIONAL, 'Use the Blade Report Issue widget.'],
            default         => [self::CONDITIONAL, 'No SPA. The Blade widget and the API both work; SIRE is fully functional.'],
        })];
    }

    private function optional(HostProfile $profile): array
    {
        $rows = [];

        foreach ([
            'audit'       => 'Audit',
            'notes'       => 'Notes',
            'attachments' => 'Attachments',
            'settings'    => 'Settings',
            'knowledge'   => 'Knowledge base',
            'versions'    => 'Version registry',
        ] as $key => $label) {
            $finding = $profile->get("integrations.{$key}");

            $rows[] = $this->row($label, $finding->isPresent() ? 'candidate found' : 'none', $finding->isPresent()
                ? [self::MANUAL, 'Optional. Write the provider to connect it, or leave SIRE\'s own in place.']
                : [self::SUPPORTED, 'Optional. SIRE uses its own and needs nothing from the host.']);
        }

        $scheduler = $profile->value('scheduler.host_uses_scheduler', false);

        $rows[] = $this->row('Scheduler', $scheduler ? 'in use' : 'not detected', $scheduler
            ? [self::SUPPORTED, 'SIRE adds one command every 15 minutes.']
            : [self::CONDITIONAL, 'Without cron, SLA state is still computed on read; only proactive notices are lost.']);

        return $rows;
    }

    private function row(string $area, string $detected, array $verdict): array
    {
        return ['area' => $area, 'detected' => $detected, 'verdict' => $verdict[0], 'note' => $verdict[1]];
    }

    /** Would SIRE refuse to install? Only UNSUPPORTED blocks. */
    public function isBlocked(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['verdict'] === self::UNSUPPORTED) {
                return true;
            }
        }

        return false;
    }
}
