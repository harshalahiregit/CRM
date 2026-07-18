<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvSafetyStrike;
use App\Models\Tpv\TpvWorker;
use App\Models\User;
use App\Support\Tpv\StrikeSeverity as Severity;
use App\Support\Tpv\TpvWorkerStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The safety strike ("punch") engine.
 *
 * Three active strikes terminate site access automatically; a Critical strike
 * terminates on its own. Strikes are voided rather than deleted so an appeal
 * leaves a trace — and voiding never silently restores access, because
 * reinstatement is a deliberate act.
 */
class SafetyStrikeService
{
    public function __construct(private TpvWorkerService $workerService)
    {
    }

    public function listForWorker(TpvWorker $worker): Collection
    {
        return $worker->strikes()->with(['issuer:id,name', 'voider:id,name'])->get();
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = TpvSafetyStrike::forTenant($tenantId)
            ->with(['worker:id,name,worker_code,status', 'issuer:id,name']);

        if (! empty($filters['severity']) && $filters['severity'] !== 'All') {
            $query->where('severity', $filters['severity']);
        }
        if (($filters['active'] ?? null) === 'true') {
            $query->active();
        }
        if (! empty($filters['worker_id'])) {
            $query->where('tpv_worker_id', $filters['worker_id']);
        }

        return $query->latest('occurred_at')->get();
    }

    public function activeCount(TpvWorker $worker): int
    {
        return $worker->strikes()->active()->count();
    }

    /**
     * Issue a strike. Returns the strike plus whether it terminated the worker,
     * so the caller can tell the user what actually happened.
     */
    public function issue(TpvWorker $worker, array $data, User $actor): array
    {
        if (! Severity::isValid($data['severity'] ?? null)) {
            throw new BusinessException('Unknown strike severity.');
        }
        if ($worker->status === TpvWorkerStatus::TERMINATED) {
            throw new BusinessException('This worker is already terminated.');
        }

        $strike = null;
        $terminated = false;

        DB::transaction(function () use ($worker, $data, $actor, &$strike, &$terminated) {
            $strike = $worker->strikes()->create([
                'tenant_id'   => $worker->tenant_id,
                'issued_by'   => $actor->id,
                'severity'    => $data['severity'],
                'reason'      => $data['reason'],
                'notes'       => $data['notes'] ?? null,
                'location'    => $data['location'] ?? null,
                'occurred_at' => $data['occurred_at'] ?? now(),
            ]);

            $active = $this->activeCount($worker);
            $critical = Severity::terminatesImmediately($data['severity']);

            // Either policy trips termination: one Critical, or reaching the limit.
            if ($critical || $active >= Severity::LIMIT) {
                $why = $critical
                    ? "Critical safety violation: {$data['reason']}"
                    : "Reached {$active} active safety strikes (limit ".Severity::LIMIT.').';

                $this->workerService->terminate($worker, $actor, $why);
                $strike->update(['triggered_termination' => true]);
                $terminated = true;
            }
        });

        $strike->refresh();
        $strike->recordAudit('Safety Strike Issued', $actor, $data['reason'], [
            'severity' => $data['severity'], 'terminated' => $terminated,
        ]);

        Log::channel('tpv')->warning('Safety strike issued', [
            'worker_id' => $worker->id, 'tenant_id' => $worker->tenant_id,
            'severity' => $data['severity'], 'terminated' => $terminated,
        ]);

        return [
            'strike'       => $strike->fresh(['issuer:id,name']),
            'terminated'   => $terminated,
            'active_count' => $this->activeCount($worker->fresh()),
        ];
    }

    /**
     * Void a strike (appeal upheld). The row stays on the ledger; it just stops
     * counting. This never auto-reinstates a terminated worker — restoring site
     * access is an explicit decision, taken separately.
     */
    public function void(TpvSafetyStrike $strike, User $actor, string $reason): TpvSafetyStrike
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

        Log::channel('tpv')->info('Safety strike voided', [
            'strike_id' => $strike->id, 'worker_id' => $strike->tpv_worker_id, 'tenant_id' => $strike->tenant_id,
        ]);

        return $strike->fresh(['issuer:id,name', 'voider:id,name']);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => TpvSafetyStrike::forTenant($tenantId);

        return [
            'total'      => $base()->count(),
            'active'     => $base()->active()->count(),
            'voided'     => $base()->whereNotNull('voided_at')->count(),
            'critical'   => $base()->active()->where('severity', Severity::CRITICAL)->count(),
            'terminations' => $base()->where('triggered_termination', true)->count(),
            // Workers one strike away from termination — the watch list.
            'at_risk'    => TpvWorker::forTenant($tenantId)
                ->where('status', TpvWorkerStatus::ACTIVE)
                ->whereHas('strikes', fn ($q) => $q->whereNull('voided_at'), '>=', Severity::WARN_AT)
                ->count(),
        ];
    }
}
