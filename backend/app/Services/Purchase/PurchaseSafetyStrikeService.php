<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseSafetyStrike;
use App\Models\Purchase\PurchaseWorker;
use App\Models\User;
use App\Support\Purchase\PurchaseStrikeSeverity as Severity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The safety strike ("punch") engine, Purchase side.
 *
 * Three active strikes terminate site access automatically; a Critical strike
 * terminates on its own. Strikes are voided rather than deleted so an appeal
 * leaves a trace — and voiding never silently restores access, because
 * reinstatement is a deliberate act taken separately.
 *
 * A mirror of Tpv\SafetyStrikeService. Purchase had no strikes engine at all,
 * so a repeat offender on a Purchase crew could be sent home and nothing
 * recorded it: the next site had no way to know.
 */
class PurchaseSafetyStrikeService
{
    public function __construct(
        private PurchaseWorkforceService $workers,
        private PurchaseSettingService $settings,
    ) {}

    public function listForWorker(PurchaseWorker $worker): Collection
    {
        return $worker->strikes()->with(['issuer:id,name', 'voider:id,name'])->get();
    }

    /**
     * @param  array{severity?:?string,active?:?string,worker_id?:mixed,vendor_id?:mixed}  $filters
     */
    public function list(int $tenantId, array $filters): Collection
    {
        $query = PurchaseSafetyStrike::forTenant($tenantId)
            ->with(['worker:id,full_name,worker_code,status,purchase_vendor_id', 'issuer:id,name']);

        if (! empty($filters['severity']) && $filters['severity'] !== 'All') {
            $query->where('severity', $filters['severity']);
        }

        if (($filters['active'] ?? null) === 'true') {
            $query->active();
        }

        if (! empty($filters['worker_id'])) {
            $query->where('purchase_worker_id', (int) $filters['worker_id']);
        }

        // Same shape as the gate log: a strike is against a WORKER, and the
        // vendor screen wants every strike across the workers it owns.
        if (! empty($filters['vendor_id'])) {
            $query->whereIn(
                'purchase_worker_id',
                PurchaseWorker::forTenant($tenantId)
                    ->where('purchase_vendor_id', (int) $filters['vendor_id'])->select('id'),
            );
        }

        return $query->latest('occurred_at')->get();
    }

    public function activeCount(PurchaseWorker $worker): int
    {
        return $worker->strikes()->active()->count();
    }

    /**
     * Issue a strike.
     *
     * Returns the strike plus whether it terminated the worker, so the caller
     * can say what actually happened rather than reporting a bare success for
     * an action that just ended somebody's site access.
     */
    public function issue(PurchaseWorker $worker, array $data, User $actor): array
    {
        if (! Severity::isValid($data['severity'] ?? null)) {
            throw new BusinessException('Unknown strike severity.');
        }

        if ($worker->status === 'Terminated') {
            throw new BusinessException('This worker is already terminated.');
        }

        $rules = $this->rulesFor($worker->tenant_id);
        $strike = null;
        $terminated = false;

        DB::transaction(function () use ($worker, $data, $actor, $rules, &$strike, &$terminated) {
            $strike = $worker->strikes()->create([
                'tenant_id'   => $worker->tenant_id,
                'issued_by'   => $actor->id,
                'severity'    => $data['severity'],
                'reason'      => $data['reason'],
                'notes'       => $data['notes'] ?? null,
                'location'    => $data['location'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $active   = $this->activeCount($worker);
            $critical = $rules['critical_terminates_immediately']
                && Severity::terminatesImmediately($data['severity']);

            // Either policy trips termination: one Critical, or reaching the limit.
            if ($critical || $active >= $rules['limit']) {
                $why = $critical
                    ? "Critical safety violation: {$data['reason']}"
                    : "Reached {$active} active safety strikes (limit {$rules['limit']}).";

                $this->workers->terminate($worker, $actor, $why);
                $strike->update(['triggered_termination' => true]);
                $terminated = true;
            }
        });

        $strike->refresh();
        $strike->recordAudit('Safety Strike Issued', $actor, $data['reason'], [
            'severity' => $data['severity'], 'terminated' => $terminated,
        ]);

        Log::warning('Purchase safety strike issued', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
            'severity'  => $data['severity'], 'terminated' => $terminated,
        ]);

        return [
            'strike'       => $strike->fresh(['issuer:id,name']),
            'terminated'   => $terminated,
            'active_count' => $this->activeCount($worker->fresh()),
        ];
    }

    /**
     * Void a strike (appeal upheld).
     *
     * The row stays on the ledger; it just stops counting. This never
     * auto-reinstates a terminated worker — restoring site access is an
     * explicit decision, taken separately, by somebody who means it.
     */
    public function void(PurchaseSafetyStrike $strike, User $actor, string $reason): PurchaseSafetyStrike
    {
        if ($strike->voided_at) {
            throw new BusinessException('This strike is already voided.');
        }

        $strike->update([
            'voided_at'   => now(),
            'voided_by'   => $actor->id,
            'void_reason' => $reason,
        ]);

        $strike->recordAudit('Safety Strike Voided', $actor, $reason);

        Log::info('Purchase safety strike voided', [
            'strike_id' => $strike->id,
            'worker_id' => $strike->purchase_worker_id,
            'tenant_id' => $strike->tenant_id,
        ]);

        return $strike->fresh(['issuer:id,name', 'voider:id,name']);
    }

    public function stats(int $tenantId): array
    {
        $base  = fn () => PurchaseSafetyStrike::forTenant($tenantId);
        $rules = $this->rulesFor($tenantId);

        return [
            'total'        => $base()->count(),
            'active'       => $base()->active()->count(),
            'voided'       => $base()->whereNotNull('voided_at')->count(),
            'critical'     => $base()->active()->where('severity', Severity::CRITICAL)->count(),
            'terminations' => $base()->where('triggered_termination', true)->count(),
            // Workers one strike away from termination — the watch list.
            'at_risk'      => PurchaseWorker::forTenant($tenantId)
                ->where('status', 'Active')
                ->whereHas('strikes', fn ($q) => $q->whereNull('voided_at'), '>=', $rules['warn_at'])
                ->count(),
            'rules'        => $rules,
        ];
    }

    /**
     * The termination policy for a tenant.
     *
     * Configurable for the same reason TPV's is: what counts as three chances
     * differs by site, and a threshold hard-coded in PHP is one nobody can
     * change. An unset key falls back to the shipped policy.
     *
     * @return array{limit:int,warn_at:int,critical_terminates_immediately:bool}
     */
    private function rulesFor(int $tenantId): array
    {
        $limit = max(1, (int) $this->settings->get($tenantId, 'strike_limit'));

        return [
            'limit'   => $limit,
            // Never above the limit: a warning that fires at or after the point
            // of termination is a warning nobody can act on.
            'warn_at' => max(1, min($limit, (int) $this->settings->get($tenantId, 'strike_warn_at'))),
            'critical_terminates_immediately' => (bool) $this->settings->get($tenantId, 'strike_critical_terminates'),
        ];
    }
}
