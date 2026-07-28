<?php

namespace App\Services\Hr\Scoring;

use App\Models\Hr\AirScoringConfig;

/**
 * Resolves the active scoring configuration for a tenant.
 *
 * Order: tenant + job_family → tenant default → global default row → an unsaved
 * in-memory row carrying the column defaults. The engine therefore always has a
 * config, and no weight or threshold is ever written into engine code.
 *
 * Resolution is memoised per request: scoring a page of candidates must not issue
 * one config query per candidate.
 */
class ScoringConfigResolver
{
    /** @var array<string, AirScoringConfig> */
    private array $cache = [];

    public function resolve(?int $tenantId, ?string $jobFamily = null): AirScoringConfig
    {
        $key = ($tenantId ?? 'null').'|'.($jobFamily ?? '');

        return $this->cache[$key] ??= $this->lookup($tenantId, $jobFamily);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    private function lookup(?int $tenantId, ?string $jobFamily): AirScoringConfig
    {
        $base = AirScoringConfig::query()->where('is_active', true);

        if ($tenantId !== null) {
            if ($jobFamily) {
                $hit = (clone $base)->where('tenant_id', $tenantId)->where('job_family', $jobFamily)->first();
                if ($hit) {
                    return $hit;
                }
            }
            $hit = (clone $base)->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('is_default', true)->orWhere('job_family', 'General'))
                ->orderByDesc('is_default')->first();
            if ($hit) {
                return $hit;
            }
        }

        $global = (clone $base)->whereNull('tenant_id')->orderByDesc('is_default')->first();
        if ($global) {
            return $global;
        }

        // Nothing configured yet. An unsaved model carries the migration's column
        // defaults, so scoring works out of the box and seeding stays optional.
        return new AirScoringConfig([
            'tenant_id'  => $tenantId,
            'job_family' => 'General',
            'is_active'  => true,
            'is_default' => true,
        ]);
    }

    /** Create the tenant's default row if it has none. Used by the seeder/command. */
    public function ensureDefaultFor(?int $tenantId): AirScoringConfig
    {
        $existing = AirScoringConfig::where('tenant_id', $tenantId)->where('is_default', true)->first();
        if ($existing) {
            return $existing;
        }

        $config = AirScoringConfig::create(array_merge(
            ['tenant_id' => $tenantId, 'job_family' => 'General', 'is_active' => true, 'is_default' => true],
            // Weight columns carry DB defaults, but state them so a row read back is
            // explicit about what it applies.
            array_combine(
                array_map(fn ($d) => $d.'_weight', array_keys(AirScoringConfig::DEFAULT_WEIGHTS)),
                array_values(AirScoringConfig::DEFAULT_WEIGHTS)
            )
        ));
        $this->flush();

        return $config;
    }
}
