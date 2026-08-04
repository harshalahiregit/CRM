<?php

namespace App\Services\Hr\Statutory;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrStatutoryRule;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\WorkStates;

/**
 * CRUD over the statutory rule book, plus the company-wide payroll defaults.
 *
 * The rules ARE the legal configuration — every rate, ceiling and slab lives in
 * `config`, never in code. This service therefore validates SHAPE (are the slabs
 * contiguous? is a PT rule attached to a state?) and never VALUE: it has no opinion
 * on what any rate should be, because nobody here is qualified to have one.
 */
class StatutoryRuleService
{
    public function __construct(
        private StatutoryRuleResolver $resolver,
        private SettingsService $settings,
    ) {
    }

    public function list(int $tenantId, array $filters = []): array
    {
        $q = HrStatutoryRule::forTenant($tenantId);

        if (! empty($filters['rule_type'])) {
            $q->where('rule_type', $filters['rule_type']);
        }
        if (! empty($filters['state'])) {
            $q->whereRaw('LOWER(state) = ?', [mb_strtolower(trim($filters['state']))]);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderBy('rule_type')->orderBy('state')->orderByDesc('effective_from')
            ->get()->map(fn ($r) => $this->present($r))->all();
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $data = $this->prepare($data);

        $rule = HrStatutoryRule::create($data + [
            'tenant_id'  => $tenantId,
            'created_by' => $actor?->id,
        ]);
        $rule->recordAudit('Statutory Rule Created', $actor, null, [
            'rule_type' => $rule->rule_type, 'state' => $rule->state,
        ]);
        $this->resolver->flush();

        return $this->present($rule);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $rule = $this->find($id, $tenantId);
        $data = $this->prepare($data, $rule);

        $rule->update($data + ['updated_by' => $actor?->id]);
        $rule->recordAudit('Statutory Rule Updated', $actor);
        $this->resolver->flush();

        return $this->present($rule->refresh());
    }

    public function destroy(int $id, int $tenantId, ?User $actor = null): void
    {
        $rule = $this->find($id, $tenantId);
        $rule->recordAudit('Statutory Rule Deleted', $actor, null, [
            'rule_type' => $rule->rule_type, 'state' => $rule->state,
        ]);
        $rule->delete();
        $this->resolver->flush();
    }

    /* ── Company-wide payroll defaults ────────────────────────────────── */

    public function defaults(int $tenantId): array
    {
        return [
            'default_work_state' => $this->settings->get($tenantId, 'payroll', 'default_work_state'),
            'fy_start_month'     => (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4),
            // Loan affordability thresholds — company policy, not law, so they
            // live alongside the other payroll-wide defaults rather than in the
            // statutory rule book.
            'loan_emi_warn_percent'    => (float) $this->settings->get($tenantId, 'payroll', 'loan_emi_warn_percent', 40),
            'loan_emi_max_percent'     => (float) $this->settings->get($tenantId, 'payroll', 'loan_emi_max_percent', 50),
            'loan_enforce_eligibility' => (bool) $this->settings->get($tenantId, 'payroll', 'loan_enforce_eligibility', true),
        ];
    }

    public function saveDefaults(array $data, int $tenantId): array
    {
        if (array_key_exists('default_work_state', $data)) {
            // Stored canonical so it matches a rule's `state` regardless of spelling.
            $this->settings->set($tenantId, 'payroll', 'default_work_state',
                WorkStates::normalize($data['default_work_state']));
        }

        foreach (['fy_start_month', 'loan_emi_warn_percent', 'loan_emi_max_percent', 'loan_enforce_eligibility'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->settings->set($tenantId, 'payroll', $key, $data[$key]);
            }
        }

        return $this->defaults($tenantId);
    }

    /* ── Shape validation ─────────────────────────────────────────────── */

    /**
     * Normalise and sanity-check a payload. Business VALUES are never judged here —
     * only that the structure is one the calculators can actually consume.
     */
    private function prepare(array $data, ?HrStatutoryRule $existing = null): array
    {
        $type = $data['rule_type'] ?? $existing?->rule_type;

        if ($type === 'pt') {
            // A PT rule without a state can never be applied — the engine matches
            // state-exact for PT. Rejecting it here beats a rule that silently
            // never fires.
            $state = WorkStates::normalize($data['state'] ?? $existing?->state);
            if (! $state) {
                throw new BusinessException('Professional Tax is levied per state — choose the state this rule applies to.');
            }
            $data['state'] = $state;
        } elseif (array_key_exists('state', $data)) {
            $data['state'] = WorkStates::normalize($data['state']);
        }

        if (array_key_exists('config', $data)) {
            $data['config'] = $this->checkConfig($type, $data['config'] ?? []);
        }

        if (! empty($data['effective_to']) && ! empty($data['effective_from'])
            && $data['effective_to'] < $data['effective_from']) {
            throw new BusinessException('The end date cannot be before the start date.');
        }

        return $data;
    }

    /** Structural checks only — a malformed slab list is a bug, a wrong rate is a business decision. */
    private function checkConfig(?string $type, $config): array
    {
        $config = is_array($config) ? $config : [];

        if (in_array($type, ['pt', 'tds'], true)) {
            $slabs = $config['slabs'] ?? [];
            if (! is_array($slabs) || $slabs === []) {
                throw new BusinessException('Add at least one slab — without slabs this rule computes nothing.');
            }
            foreach ($slabs as $i => $slab) {
                $from = $slab['from'] ?? null;
                $to   = $slab['to'] ?? null;
                if ($from === null || $from === '') {
                    throw new BusinessException('Slab '.($i + 1).' needs a "from" amount.');
                }
                if ($to !== null && $to !== '' && (float) $to < (float) $from) {
                    throw new BusinessException('Slab '.($i + 1).' ends before it begins.');
                }
            }
        }

        // #30 — a WCP or Mediclaim rule that names no mode of payment computes
        // nothing, which on a premium reads as "not covered" rather than as a
        // misconfiguration. Structure only: the rates themselves are the tenant's
        // business decision, exactly as with every other rule type.
        if (in_array($type, ['wcp', 'mediclaim'], true)) {
            $mode = mb_strtolower((string) ($config['mode'] ?? 'percentage'));

            if (! in_array($mode, ['fixed', 'percentage'], true)) {
                throw new BusinessException('Mode must be either "fixed" or "percentage".');
            }

            $keys = $mode === 'fixed'
                ? ['amount', 'employee_amount', 'employer_amount']
                : ['employee_rate', 'employer_rate'];

            $hasAny = array_filter($keys, fn ($k) => (float) ($config[$k] ?? 0) > 0) !== [];

            if (! $hasAny) {
                throw new BusinessException($mode === 'fixed'
                    ? 'Set a premium amount — a fixed-mode rule with no amount deducts and costs nothing.'
                    : 'Set an employee or employer rate — a percentage rule with neither computes nothing.');
            }
        }

        return $config;
    }

    private function find(int $id, int $tenantId): HrStatutoryRule
    {
        $rule = HrStatutoryRule::forTenant($tenantId)->find($id);
        if (! $rule) {
            throw new BusinessException('Statutory rule not found', 404);
        }

        return $rule;
    }

    private function present(HrStatutoryRule $r): array
    {
        return [
            'id'             => $r->id,
            'rule_type'      => $r->rule_type,
            'state'          => $r->state,
            'effective_from' => optional($r->effective_from)->toDateString(),
            'effective_to'   => optional($r->effective_to)->toDateString(),
            'config'         => $r->config ?: [],
            'is_active'      => (bool) $r->is_active,
            'notes'          => $r->notes,
            'created_at'     => optional($r->created_at)->toIso8601String(),
        ];
    }
}
