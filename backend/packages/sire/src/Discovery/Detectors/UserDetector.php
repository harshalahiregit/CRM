<?php

namespace Sire\Discovery\Detectors;

use Illuminate\Support\Facades\Schema;
use Sire\Discovery\Finding;

/**
 * SIRE — the user model, its table, and the two fields SIRE reads.
 *
 * SIRE needs exactly three things about a user: the key, something to show on a
 * timeline, and optionally a role. This detector finds where those live.
 *
 * It does NOT read any user data. It reads the SCHEMA — which columns exist —
 * and the auth configuration. Discovery must be safe to run against production,
 * and "we only looked at column names" is a claim that has to survive review.
 */
class UserDetector implements Detector
{
    private const NAME_CANDIDATES  = ['name', 'full_name', 'display_name', 'username', 'first_name'];
    private const ROLE_CANDIDATES  = ['role', 'user_type', 'type', 'account_type', 'user_role'];

    public function name(): string
    {
        return 'user';
    }

    public function detect(): array
    {
        $out = [];

        // The provider config is the authority on where users live: it is what
        // the framework itself consults, so it cannot disagree with reality.
        $guard = config('auth.defaults.guard');
        $providerName = config("auth.guards.{$guard}.provider") ?? config('auth.defaults.provider');
        $provider = (array) config("auth.providers.{$providerName}", []);

        $model = $provider['model'] ?? null;
        $table = $provider['table'] ?? null;

        if (is_string($model) && class_exists($model)) {
            $out['user.model'] = Finding::found($model, Finding::HIGH, ["config('auth.providers.{$providerName}.model')"]);

            try {
                $instance = new $model;
                $table = $instance->getTable();
                $out['user.id_field'] = Finding::found($instance->getKeyName(), Finding::HIGH, ['model getKeyName()']);
            } catch (\Throwable $e) {
                $out['user.id_field'] = Finding::found('id', Finding::LOW, ['could not instantiate the model']);
            }
        } else {
            $out['user.model'] = Finding::absent(["config('auth.providers.*.model') names no loadable class"]);
            $out['user.id_field'] = Finding::found('id', Finding::LOW, ['Laravel convention']);
        }

        $table = is_string($table) && $table !== '' ? $table : 'users';

        if (! Schema::hasTable($table)) {
            $out['user.table'] = Finding::absent(["table '{$table}' does not exist"]);

            return $out;
        }

        $out['user.table'] = Finding::found($table, Finding::HIGH, ['schema']);

        $columns = Schema::getColumnListing($table);
        $out['user.columns'] = Finding::found($columns, Finding::HIGH, ["columns of {$table}"]);

        $out['user.name_field'] = $this->firstColumn($columns, self::NAME_CANDIDATES, $table)
            ?? Finding::absent(["none of ".implode('/', self::NAME_CANDIDATES)." on {$table}"]);

        // A role COLUMN is one of several ways hosts model roles, and the least
        // common in larger applications. RoleDetector looks at the others; this
        // records only what is on the users table itself.
        $out['user.role_field'] = $this->firstColumn($columns, self::ROLE_CANDIDATES, $table)
            ?? Finding::absent(['no role-like column on the users table']);

        return $out;
    }

    private function firstColumn(array $columns, array $candidates, string $table): ?Finding
    {
        $matches = array_values(array_intersect($candidates, $columns));

        if ($matches === []) {
            return null;
        }

        return Finding::found(
            $matches[0],
            // Exactly one candidate is unambiguous. Several means SIRE is
            // picking, and picking is not knowing.
            count($matches) === 1 ? Finding::HIGH : Finding::MEDIUM,
            ["column '{$matches[0]}' on {$table}"],
            array_slice($matches, 1),
        );
    }
}
