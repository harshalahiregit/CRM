<?php

namespace Sire\Adapters\Defaults;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Sire\Contracts\SireTenantProvider;
use Sire\Dto\SireTenantIdentity;

/**
 * The shipped tenant provider — five strategies, no guessing.
 *
 * THIS IS THE MOST DANGEROUS CLASS IN SIRE.
 *
 * Every other misconfiguration fails loudly. This one fails SILENTLY: get it
 * wrong and SIRE returns another tenant's issues on a page that renders
 * perfectly normally, with no error, no warning and nothing in a log.
 *
 * That asymmetry drives every decision here:
 *
 *   - There is no fallback. Nowhere in this class returns 0, null, or a
 *     "default" tenant when resolution fails — it throws, because a fallback IS
 *     the bug.
 *   - Nothing reads the request. Not a body, not a query string, not a route
 *     parameter, not a header. A client that could name its own tenant would
 *     make every other protection decorative.
 *   - `sire:install` will not write a strategy without explicit confirmation,
 *     and `sire:doctor` re-checks it on every run.
 *
 * FIVE STRATEGIES, because "the tenant id is on the user" is only one of the
 * ways real applications do this:
 *
 *   user_attribute   $user->tenant_id / organization_id / company_id / …
 *   relationship     $user->tenant->id  (a belongsTo, resolved lazily)
 *   resolver         $someService->currentTenantId()
 *   callable         'Class@method' — for tenancy from a subdomain or middleware
 *   single_tenant    the application is not multi-tenant at all
 *
 * `single_tenant` deserves a warning of its own: it is correct for a
 * single-company CRM and catastrophic for a shared one, and nothing in the code
 * can tell the difference. It is chosen deliberately or not at all.
 */
class SireLocalTenantProvider implements SireTenantProvider
{
    /** @var array<int, SireTenantIdentity> request-scoped; the same tenant is asked for repeatedly */
    private array $cache = [];

    private ?bool $tenantTableExists = null;

    public function currentTenant(): SireTenantIdentity
    {
        $tenantId = $this->resolveId();

        if ($tenantId === null) {
            throw new RuntimeException($this->explainFailure());
        }

        return $this->cache[$tenantId] ??= new SireTenantIdentity($tenantId, $this->nameFor($tenantId));
    }

    public function hasTenant(): bool
    {
        try {
            return $this->resolveId() !== null;
        } catch (\Throwable $e) {
            // A broken strategy is "no tenant", never "some tenant".
            return false;
        }
    }

    public function exists(int $tenantId): bool
    {
        if (! $this->tenantTableExists()) {
            // With no tenant table SIRE cannot disprove a tenant. Returning true
            // keeps `--tenant=` usable; every query is still scoped by that id,
            // so a wrong one yields an empty result rather than someone else's.
            return true;
        }

        $model = config('sire.tenant.model');

        return $model::query()->whereKey($tenantId)->exists();
    }

    public function assertAccess(object $record): void
    {
        $recordTenant = $record->tenant_id ?? null;

        // A SIRE record with no tenant_id is a programming error, not a 404:
        // every SIRE table declares tenant_id NOT NULL.
        if ($recordTenant === null) {
            throw new RuntimeException(
                'SIRE: '.$record::class.' has no tenant_id; it cannot be ownership-checked.'
            );
        }

        if ((int) $recordTenant !== $this->currentTenant()->id) {
            // 404, never 403. A 403 confirms the record exists, which turns any
            // id field into a cross-tenant existence oracle: increment the id,
            // watch the status codes, and count another tenant's issues.
            abort(404);
        }
    }

    // ------------------------------------------------------------------ strategies

    private function resolveId(): ?int
    {
        $strategy = (string) config('sire.tenant.strategy', 'user_attribute');

        $id = match ($strategy) {
            'user_attribute' => $this->fromUserAttribute(),
            'relationship'   => $this->fromRelationship(),
            'resolver'       => $this->fromResolver(),
            'callable'       => $this->fromCallable(),
            'single_tenant'  => config('sire.tenant.tenant_id'),
            default          => throw new RuntimeException(
                "SIRE: unknown tenant strategy '{$strategy}'. Expected one of: "
                .'user_attribute, relationship, resolver, callable, single_tenant.'
            ),
        };

        // 0 and '' are treated as absent. A tenant id of zero is far more likely
        // to be an unset column than a real tenant, and guessing wrong here is
        // the one failure with no symptoms.
        return ($id === null || $id === '' || (int) $id === 0) ? null : (int) $id;
    }

    private function fromUserAttribute(): int|string|null
    {
        $user = auth()->user();
        $attribute = (string) config('sire.tenant.attribute', 'tenant_id');

        return $user?->{$attribute} ?? null;
    }

    private function fromRelationship(): int|string|null
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $relation = (string) config('sire.tenant.relation', 'tenant');
        $key = (string) config('sire.tenant.key', 'id');

        // Guarded: a relation named in config that does not exist on the user
        // model should surface as "no tenant", which fails closed, rather than
        // as a BadMethodCallException from deep inside a request.
        try {
            return $user->{$relation}?->{$key} ?? null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function fromResolver(): int|string|null
    {
        $resolver = config('sire.tenant.resolver');
        $method = (string) config('sire.tenant.method', 'currentTenantId');

        if (! is_string($resolver) || ! class_exists($resolver)) {
            return null;
        }

        $instance = app($resolver);

        return method_exists($instance, $method) ? $instance->{$method}() : null;
    }

    private function fromCallable(): int|string|null
    {
        $callable = config('sire.tenant.callable');

        if ($callable === null) {
            return null;
        }

        // Container-resolved, so 'Class@method' works and a closure bound in a
        // service provider works. Never eval, never a string SIRE interprets.
        return app()->call($callable);
    }

    // ------------------------------------------------------------------ support

    private function nameFor(int $tenantId): ?string
    {
        if (! $this->tenantTableExists()) {
            return null;
        }

        try {
            $model = config('sire.tenant.model');
            $field = (string) config('sire.tenant.name_field', 'name');

            return $model::query()->whereKey($tenantId)->value($field);
        } catch (\Throwable $e) {
            report($e);

            return null;   // a display label is never worth an exception
        }
    }

    private function tenantTableExists(): bool
    {
        if ($this->tenantTableExists !== null) {
            return $this->tenantTableExists;
        }

        $model = config('sire.tenant.model');

        return $this->tenantTableExists = is_string($model) && class_exists($model);
    }

    /**
     * Say WHY, not just that it failed.
     *
     * "No tenant in scope" sends a developer hunting. Naming the strategy, the
     * attribute it looked for and the setting to change turns a twenty-minute
     * problem into a one-line fix — and this exception is the single most likely
     * thing to greet someone on their first SIRE request.
     */
    private function explainFailure(): string
    {
        $strategy = (string) config('sire.tenant.strategy', 'user_attribute');
        $authenticated = auth()->check() ? 'yes' : 'no';

        $detail = match ($strategy) {
            'user_attribute' => sprintf(
                "looked for \$user->%s on the authenticated user",
                config('sire.tenant.attribute', 'tenant_id'),
            ),
            'relationship' => sprintf(
                "looked for \$user->%s->%s",
                config('sire.tenant.relation', 'tenant'),
                config('sire.tenant.key', 'id'),
            ),
            'resolver' => sprintf(
                "called %s::%s()",
                config('sire.tenant.resolver') ?: '(none configured)',
                config('sire.tenant.method', 'currentTenantId'),
            ),
            'callable' => sprintf("called %s", config('sire.tenant.callable') ?: '(none configured)'),
            'single_tenant' => sprintf("config('sire.tenant.tenant_id') = %s", var_export(config('sire.tenant.tenant_id'), true)),
            default => 'unknown strategy',
        };

        return implode("\n", [
            'SIRE: no tenant in scope.',
            "  strategy:      {$strategy}",
            "  {$detail}",
            "  authenticated: {$authenticated}",
            '',
            $authenticated === 'no'
                ? '  This looks like a request that never authenticated. Check config(\'sire.host.auth_middleware\').'
                : '  The user is authenticated but carries no tenant. Check config(\'sire.tenant.*\') — or run: php artisan sire:doctor',
            '',
            '  Console commands and scheduled work have no session; pass an explicit tenant id there.',
        ]);
    }
}
