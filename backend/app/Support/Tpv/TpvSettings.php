<?php

namespace App\Support\Tpv;

use App\Models\Tpv\TpvSetting;

/**
 * The per-tenant TPV settings catalog (Sangoe TPV §34).
 *
 * Every configurable engine reads its knobs through here instead of touching
 * config()/constants directly. For a group it returns the shipped baseline
 * (config file / PHP constant) deep-merged with the tenant's stored override
 * row, if any. No row → the baseline is returned verbatim, so behaviour is
 * identical to the pre-settings code until an admin changes something.
 *
 * Registered as a singleton so the per-tenant merge is memoised within a request.
 */
class TpvSettings
{
    /** @var array<string,array> memo keyed by "tenantId:group" */
    private array $memo = [];

    /* ── Public typed accessors ─────────────────────────────────────────── */

    /** Safety-strike thresholds (§26, Rules 3 & 9). */
    public function strike(?int $tenantId = null): array
    {
        return $this->effective('strike_rules', $tenantId);
    }

    /** Vendor Performance Index weights / deductions / bands / dimensions (§27). */
    public function vpi(?int $tenantId = null): array
    {
        return $this->effective('vpi', $tenantId);
    }

    /** Onboarding approval chain — mode / levels / SLA (§12, Rule 1). */
    public function approvalWorkflow(?int $tenantId = null): array
    {
        return $this->effective('approval_workflow', $tenantId);
    }

    /** HSSE authority matrix — who signs off what (§12). */
    public function authority(?int $tenantId = null): array
    {
        return $this->effective('authority_matrix', $tenantId);
    }

    /** The catalogue of approval types + labels + active flags (§12). */
    public function approvalTypes(?int $tenantId = null): array
    {
        return $this->effective('approval_types', $tenantId);
    }

    /** Gate behaviour — PPE enforcement mode (Rule 5). */
    public function gate(?int $tenantId = null): array
    {
        return $this->effective('gate', $tenantId);
    }

    /** Vendor violation escalation ladder — severity points + thresholds (§26, Rule 9). */
    public function violationLadder(?int $tenantId = null): array
    {
        return $this->effective('violation_ladder', $tenantId);
    }

    /* ── Core merge ─────────────────────────────────────────────────────── */

    /** The shipped defaults for a group (no tenant involved). */
    public function baseline(string $group): array
    {
        return match ($group) {
            'strike_rules' => [
                'limit'                          => StrikeSeverity::LIMIT,
                'warn_at'                        => StrikeSeverity::WARN_AT,
                'critical_terminates_immediately' => true,
                'severities'                     => StrikeSeverity::ALL,
            ],
            'vpi' => [
                'weights'                  => config('vpi.weights', []),
                'deductions'               => config('vpi.deductions', []),
                'doc_expiring_window_days' => (int) config('vpi.doc_expiring_window_days', 30),
                'bands'                    => config('vpi.bands', []),
                'dimensions'               => \App\Services\Tpv\TpvVendorPerformanceService::DIMENSIONS,
            ],
            'approval_workflow' => [
                'mode'      => (string) config('tpv.approval.mode', 'single'),
                'levels'    => config('tpv.approval.levels', []),
                'sla_hours' => (int) config('tpv.approval.sla_hours', 48),
            ],
            'authority_matrix' => [
                'authorities' => config('authority.authorities', []),
                'matrix'      => config('authority.matrix', []),
            ],
            'approval_types' => [
                'types' => array_map(
                    fn ($v) => ['value' => $v, 'label' => ApprovalType::LABELS[$v] ?? $v, 'is_active' => true],
                    ApprovalType::ALL
                ),
            ],
            'gate' => [
                'ppe_enforcement' => (string) config('tpv.gate.ppe_enforcement', 'warn'),
            ],
            'violation_ladder' => [
                'severity_points' => ViolationType::SEVERITY_POINTS,
                'steps'           => ViolationType::ladderSteps(),
            ],
            default => [],
        };
    }

    /** Baseline deep-merged with the tenant's stored override (memoised). */
    public function effective(string $group, ?int $tenantId = null): array
    {
        $tid = $this->resolveTenant($tenantId);
        $key = $tid.':'.$group;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $baseline = $this->baseline($group);
        $override = $this->override($group, $tid);
        $effective = $override === null ? $baseline : $this->deepMerge($baseline, $override);

        return $this->memo[$key] = $effective;
    }

    /** The raw stored override payload for a group, or null if the tenant has none. */
    public function override(string $group, ?int $tenantId = null): ?array
    {
        $tid = $this->resolveTenant($tenantId);
        $row = TpvSetting::query()->forTenant($tid)->where('group', $group)->first();

        return $row?->payload;
    }

    /** [group => {builtins, custom, effective}] for the settings screen. */
    public function bundle(?int $tenantId = null): array
    {
        $tid = $this->resolveTenant($tenantId);
        $out = [];
        foreach (TpvSetting::GROUPS as $group) {
            $out[$group] = [
                'builtins'  => $this->baseline($group),
                'custom'    => $this->override($group, $tid),
                'effective' => $this->effective($group, $tid),
            ];
        }

        return $out;
    }

    /** Drop the memo after a write so the next read reflects it. */
    public function forget(?int $tenantId = null, ?string $group = null): void
    {
        if ($group === null) {
            $this->memo = [];

            return;
        }
        $tid = $this->resolveTenant($tenantId);
        unset($this->memo[$tid.':'.$group]);
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function resolveTenant(?int $tenantId): int
    {
        return (int) ($tenantId ?? auth()->user()?->tenant_id ?? 0);
    }

    /**
     * Deep-merge $over onto $base. Associative arrays merge key-by-key so an
     * override can touch a single weight; list arrays (numeric keys, e.g. the
     * approval levels or the authority matrix) are replaced wholesale, because a
     * partial list merge is never what an editor means.
     */
    private function deepMerge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])
                && ! array_is_list($v) && ! array_is_list($base[$k])) {
                $base[$k] = $this->deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }
}
