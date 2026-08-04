<?php

namespace App\Services\Hr\Statutory;

use App\Models\Hr\HrStatutoryRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Finds the rule in force for a tenant, type, state and date.
 *
 * Returns null when nothing is configured — deliberately. Every calculator treats
 * null as "not configured" and produces zero, so an unconfigured tenant runs
 * payroll exactly as it did before this module existed. Inventing a default here
 * would silently deduct money under rules nobody approved.
 *
 * Professional Tax is levied per state, so a state-specific row wins over a
 * tenant-wide one; the rest ignore state entirely.
 *
 * PT additionally passes $strictState. PT is levied by a state and by nothing else,
 * so falling back to a state-less "default" rule would deduct one state's tax from
 * an employee working in another. For PT the match is exact or there is no rule.
 */
class StatutoryRuleResolver
{
    /** Cache per request — a payroll run resolves the same rules for every employee. */
    private array $cache = [];

    public function resolve(
        string $ruleType,
        int $tenantId,
        ?CarbonInterface $on = null,
        ?string $state = null,
        bool $strictState = false,
    ): ?array {
        $on  = $on ? Carbon::parse($on) : Carbon::today();
        $key = implode('|', [$tenantId, $ruleType, $state ?? '-', $on->toDateString(), $strictState ? 'S' : '-']);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $base = fn () => HrStatutoryRule::where('tenant_id', $tenantId)
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from');

        $rule = null;

        // A matching state wins…
        if ($state !== null && $state !== '') {
            $rule = (clone $base())->whereRaw('LOWER(state) = ?', [mb_strtolower(trim($state))])->first();
        }
        // …otherwise fall back to the state-less default, unless the rule type is
        // state-levied (PT), where a wrong state is worse than no deduction.
        if ($rule === null && ! $strictState) {
            $rule = (clone $base())->whereNull('state')->first();
        }

        return $this->cache[$key] = $rule?->config ?: null;
    }

    /** Discard the per-request cache (used by tests and after a rule edit). */
    public function flush(): void
    {
        $this->cache = [];
    }
}
