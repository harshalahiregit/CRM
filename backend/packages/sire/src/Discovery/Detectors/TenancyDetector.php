<?php

namespace Sire\Discovery\Detectors;

use Illuminate\Support\Facades\Schema;
use Sire\Discovery\Finding;

/**
 * SIRE — how the host decides which tenant a request belongs to.
 *
 * THE ONE DETECTOR THAT MUST REFUSE TO GUESS.
 *
 * Everything else discovery gets wrong fails loudly: a wrong notification
 * adapter throws, a wrong role column shows an empty picker. A wrong tenant
 * source returns ANOTHER CUSTOMER'S DATA on a page that renders perfectly
 * normally, with nothing in any log.
 *
 * So this detector is deliberately pessimistic:
 *
 *   - Finding ONE tenant-shaped column is MEDIUM, never HIGH. A column called
 *     `company_id` might identify the tenant, or it might be the customer the
 *     user works for — inside a single-tenant CRM those are different things
 *     that look identical from the schema.
 *   - Finding SEVERAL is LOW and reports all of them. `tenant_id` beside
 *     `organization_id` is a question for a human, not a tiebreak for SIRE.
 *   - Finding NONE is reported plainly, with the alternative strategies spelled
 *     out. A host may resolve tenancy from a subdomain, and no amount of column
 *     inspection will show that.
 *
 * The installer never applies a tenant strategy without explicit confirmation,
 * whatever this returns. That is belt and braces on purpose.
 */
class TenancyDetector implements Detector
{
    /** Ordered by how unambiguously each name means "tenant". */
    private const COLUMN_CANDIDATES = [
        'tenant_id', 'tenantId',
        'organization_id', 'organisation_id',
        'workspace_id', 'account_id', 'company_id', 'client_id',
    ];

    /** Packages that own tenancy outright. Their presence changes the answer. */
    private const TENANCY_PACKAGES = [
        'stancl/tenancy',
        'spatie/laravel-multitenancy',
        'tenancy/tenancy',
        'hyn/multi-tenant',
    ];

    private const MODEL_CANDIDATES = [
        'App\\Models\\Tenant', 'App\\Models\\Organization', 'App\\Models\\Organisation',
        'App\\Models\\Workspace', 'App\\Models\\Company', 'App\\Models\\Account',
        'App\\Tenant', 'App\\Organization', 'App\\Company',
    ];

    public function __construct(private readonly ComposerReader $composer)
    {
    }

    public function name(): string
    {
        return 'tenancy';
    }

    public function detect(): array
    {
        $out = [];

        // ---- 1. a tenancy package owns this ----------------------------------
        $package = $this->composer->firstOf(self::TENANCY_PACKAGES);

        if ($package !== null) {
            $out['tenant.package'] = Finding::found($package, Finding::HIGH, ['composer.json']);
            $out['tenant.strategy'] = Finding::found(
                'resolver',
                Finding::LOW,
                ["{$package} manages tenancy; SIRE cannot know its resolution API from the schema"],
                ['callable', 'user_attribute'],
            );
        } else {
            $out['tenant.package'] = Finding::absent(['no known tenancy package in composer.json']);
        }

        // ---- 2. a tenant model ----------------------------------------------
        $models = array_values(array_filter(self::MODEL_CANDIDATES, 'class_exists'));

        $out['tenant.model'] = $models === []
            ? Finding::absent(['none of the conventional tenant model names exist'])
            : Finding::found(
                $models[0],
                count($models) === 1 ? Finding::MEDIUM : Finding::LOW,
                ['class_exists('.$models[0].')'],
                array_slice($models, 1),
            );

        // ---- 3. a tenant column on the users table ---------------------------
        $usersTable = $this->usersTable();

        if ($usersTable === null || ! Schema::hasTable($usersTable)) {
            $out['tenant.attribute'] = Finding::absent(['users table not found; cannot inspect columns']);

            return $this->withStrategy($out, null);
        }

        $columns = Schema::getColumnListing($usersTable);
        $matches = array_values(array_intersect(self::COLUMN_CANDIDATES, $columns));

        if ($matches === []) {
            $out['tenant.attribute'] = Finding::absent([
                "no tenant-shaped column on {$usersTable}",
                'looked for: '.implode(', ', self::COLUMN_CANDIDATES),
            ]);

            return $this->withStrategy($out, null);
        }

        $out['tenant.attribute'] = Finding::found(
            $matches[0],
            // NEVER HIGH. One tenant-shaped column is a strong hint and a weak
            // fact: `company_id` may identify the tenant, or the customer the
            // user works for. The schema cannot tell those apart.
            count($matches) === 1 ? Finding::MEDIUM : Finding::LOW,
            ["column '{$matches[0]}' on {$usersTable}"],
            array_slice($matches, 1),
        );

        return $this->withStrategy($out, $matches[0]);
    }

    /**
     * Propose a strategy, and say plainly when there is nothing to propose.
     */
    private function withStrategy(array $out, ?string $attribute): array
    {
        if (isset($out['tenant.strategy'])) {
            return $out;   // a tenancy package already claimed it
        }

        if ($attribute !== null) {
            $out['tenant.strategy'] = Finding::found(
                'user_attribute',
                Finding::MEDIUM,
                ["the users table carries '{$attribute}'"],
                ['relationship', 'resolver', 'callable', 'single_tenant'],
            );

            return $out;
        }

        // Absent, not wrong. A single-company CRM legitimately has no tenant
        // column, and so does one resolving tenancy from a subdomain. Both are
        // supported; neither is detectable from a schema.
        $out['tenant.strategy'] = Finding::absent([
            'no tenant column, model or package found',
            'this is normal for a single-tenant application (strategy: single_tenant)',
            'and for tenancy resolved from a subdomain or header (strategy: callable)',
        ]);

        return $out;
    }

    private function usersTable(): ?string
    {
        $guard = config('auth.defaults.guard');
        $providerName = config("auth.guards.{$guard}.provider") ?? config('auth.defaults.provider');
        $model = config("auth.providers.{$providerName}.model");

        if (is_string($model) && class_exists($model)) {
            try {
                return (new $model)->getTable();
            } catch (\Throwable $e) {
                // fall through to the conventional name
            }
        }

        return config("auth.providers.{$providerName}.table") ?? 'users';
    }
}
