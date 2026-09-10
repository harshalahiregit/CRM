<?php

namespace Sire\Discovery\Detectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sire\Discovery\Finding;
use Sire\Discovery\RoleClassifier;
use Sire\Support\SireLoginType;

/**
 * SIRE — how the host models roles, and what those roles are called.
 *
 * Feeds the four-login-type mapping, which is the boundary between "engineering
 * can see the defect backlog" and "so can the customer it is about".
 *
 * WHAT IT READS, AND WHY THAT IS ACCEPTABLE
 *
 * This is the one detector that reads DATA rather than shape: the DISTINCT role
 * names in use. That is deliberate and bounded — it reads role names only, from
 * a roles table or a role column, capped, and never alongside any other column.
 * Without it the installer could only ask "type your role names", which invites
 * typos in exactly the mapping where a typo means a customer sees the backlog.
 *
 * THE CLASSIFICATION IS A PROPOSAL, NEVER A DECISION
 *
 * `super_admin` is obviously ADMIN. `partner` could be a colleague or a
 * competitor's supplier, and no amount of string matching will tell you which.
 * So every proposal carries its confidence, unmatched roles are listed
 * explicitly as unclassified, and the installer makes a human confirm the whole
 * mapping before writing it.
 */
class RoleDetector implements Detector
{
    private const PERMISSION_PACKAGES = [
        'spatie/laravel-permission',
        'bezhansalleh/filament-shield',
        'santigarcor/laratrust',
        'ultraware/roles',
        'jeremykenedy/laravel-roles',
    ];

    private const ROLE_TABLES = ['roles', 'user_roles', 'role', 'account_types'];

    private const MAX_ROLES = 100;

    public function __construct(private readonly ComposerReader $composer)
    {
    }

    public function name(): string
    {
        return 'roles';
    }

    public function detect(): array
    {
        $out = [];

        $package = $this->composer->firstOf(self::PERMISSION_PACKAGES);
        $out['permissions.provider'] = $package === null
            ? Finding::absent(['no known permission package in composer.json'])
            : Finding::found($package, Finding::HIGH, ['composer.json']);

        $out['permissions.gate'] = Finding::found(
            class_exists(\Illuminate\Support\Facades\Gate::class),
            Finding::HIGH,
            ['Laravel Gate is always available'],
        );

        [$roles, $source, $confidence] = $this->readRoleNames();

        if ($roles === []) {
            $out['roles.available'] = Finding::absent([
                'no roles table and no role column with readable values',
                'SIRE cannot propose a login-type mapping without role names',
            ]);

            foreach (SireLoginType::ALL as $type) {
                $out["roles.{$type}"] = Finding::absent(['no roles to classify']);
            }

            return $out;
        }

        $out['roles.available'] = Finding::found($roles, $confidence, [$source]);
        $out['roles.source'] = Finding::found($source, $confidence, [$source]);

        // ---- propose a mapping ------------------------------------------------
        // The rule lives in RoleClassifier so it can be tested against the
        // reference fixtures without a database.
        $classified = (new RoleClassifier)->classifyAll($roles);
        $unmatched = $classified['unclassified'];

        foreach (SireLoginType::ALL as $type) {
            $matched = $classified[$type] ?? [];

            $out["roles.{$type}"] = $matched === []
                ? Finding::absent(["no role name suggested {$type}"])
                : Finding::found(
                    $matched,
                    // Never HIGH. Naming conventions are a hint about intent,
                    // and this mapping decides who can read the defect backlog.
                    Finding::MEDIUM,
                    ['matched by name against SIRE\'s hint list'],
                );
        }

        $out['roles.unclassified'] = $unmatched === []
            ? Finding::absent(['every role name matched a category'])
            : Finding::found($unmatched, Finding::HIGH, [
                'these matched no hint and will have NO SIRE access until mapped',
            ]);

        return $out;
    }

    /** @return array{0: array<int, string>, 1: string, 2: string} */
    private function readRoleNames(): array
    {
        // A roles table is the clearest source: the names are the point of it.
        foreach (self::ROLE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (['name', 'slug', 'title', 'key'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                try {
                    $names = DB::table($table)->distinct()->limit(self::MAX_ROLES)->pluck($column)
                        ->filter()->map(fn ($v) => (string) $v)->values()->all();
                } catch (\Throwable $e) {
                    continue;
                }

                if ($names !== []) {
                    return [$names, "{$table}.{$column}", Finding::HIGH];
                }
            }
        }

        // Otherwise: distinct values of a role column on users. Bounded, and
        // the only column read.
        $usersTable = config('sire.user.table', 'users');

        foreach (['role', 'user_type', 'type', 'account_type'] as $column) {
            if (! Schema::hasTable($usersTable) || ! Schema::hasColumn($usersTable, $column)) {
                continue;
            }

            try {
                $names = DB::table($usersTable)->distinct()->limit(self::MAX_ROLES)->pluck($column)
                    ->filter()->map(fn ($v) => (string) $v)->values()->all();
            } catch (\Throwable $e) {
                continue;
            }

            if ($names !== []) {
                return [$names, "{$usersTable}.{$column} (distinct values)", Finding::MEDIUM];
            }
        }

        return [[], 'none', Finding::NONE];
    }

}
