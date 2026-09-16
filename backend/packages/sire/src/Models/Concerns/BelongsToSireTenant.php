<?php

namespace Sire\Models\Concerns;

use Sire\Contracts\SireTenantProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * SIRE — opt-in tenant scoping, matching the CRM's existing convention.
 *
 * WHY OPT-IN AND NOT A GLOBAL SCOPE
 *
 * The CRM this installs into scopes tenants by calling ->forTenant() at each
 * query site — roughly 1,120 of them — with no global scope and no tenant
 * middleware. SIRE follows that convention exactly. Adding a global scope for
 * SIRE tables alone would mean two tenancy models in one codebase, and the
 * failure mode of "some models scope themselves and others do not" is far worse
 * than one consistent rule.
 *
 * The consequence is that scoping is a discipline, not a guarantee. So it is
 * enforced mechanically: tests/tenant-scoping.test.mjs reads every query in
 * SIRE and fails the build on a tenant-table query with no ->forTenant().
 *
 * SIRE owns this trait rather than importing a host's, so the module has no
 * compile-time dependency on a trait signature it cannot verify. Tenancy itself
 * still comes from the host, through SireTenantProvider — this trait only
 * decides WHERE that answer is applied.
 */
trait BelongsToSireTenant
{
    public static function bootBelongsToSireTenant(): void
    {
        static::creating(function ($model): void {
            if (! empty($model->tenant_id)) {
                return;
            }

            // Console commands and queued work run without a session. Stamping a
            // guessed tenant would be worse than leaving it null and letting the
            // NOT NULL constraint reject the write.
            $tenants = app(SireTenantProvider::class);

            if ($tenants->hasTenant()) {
                $model->tenant_id = $tenants->currentTenant()->id;
            }
        });
    }

    /**
     * Restrict to one tenant. Called with no argument it uses the current
     * session's tenant and THROWS when there is none — never silently returns
     * every tenant's rows.
     */
    public function scopeForTenant(Builder $query, int|string|null $tenantId = null): Builder
    {
        $tenantId ??= app(SireTenantProvider::class)->currentTenant()->id;

        return $query->where($query->getModel()->getTable().'.tenant_id', (int) $tenantId);
    }
}
