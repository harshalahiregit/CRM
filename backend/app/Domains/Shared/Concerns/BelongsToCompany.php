<?php

namespace App\Domains\Shared\Concerns;

/**
 * STOS tenancy: every table in this system carries `company_id`, and nothing
 * reads a STOS table without scoping to it (golden rule 1).
 *
 * ── The one bridge you need to know about ────────────────────────────────
 * This host CRM identifies a workspace with `users.tenant_id`, not
 * `company_id` — there is no `companies` table here, `tenants` is it. STOS
 * names the column `company_id` as its spec requires, and this trait is the
 * SINGLE place the two names meet: `currentCompanyId()` prefers a real
 * `company_id` on the user and falls back to `tenant_id`.
 *
 * So the day the platform grows a first-class `companies` table, this resolver
 * is the only thing that changes — not seven migrations and not every query.
 *
 * Like the host's own BelongsToTenant, the scope is OPT-IN: this trait stamps
 * writes automatically, but reads are NOT filtered for you. Chain
 * ->forCompany($companyId) on every query, every time.
 */
trait BelongsToCompany
{
    /** Opt-in read scope. Nothing filters for you — call this explicitly. */
    public function scopeForCompany($query, $companyId)
    {
        return $query->where($this->getTable().'.company_id', $companyId);
    }

    /** The workspace the signed-in user belongs to, or null when unauthenticated. */
    public static function currentCompanyId(): ?int
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        $id = $user->company_id ?? $user->tenant_id ?? null;

        return $id === null ? null : (int) $id;
    }

    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (empty($model->company_id)) {
                $model->company_id = static::currentCompanyId();
            }
        });
    }
}
