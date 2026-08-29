<?php

namespace App\Services\Tpv;

use App\Exceptions\BusinessException;
use App\Models\Tpv\TpvApproval;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\TpvSettings;
use Illuminate\Support\Facades\Log;

/**
 * Central TPV approval engine (Sangoe TPV §12). Raise, list and decide generic
 * approval requests. Other services can call raise() to route an action through
 * approval; the onboarding chain stays independent.
 *
 * §12 routing: when raised, an approval is placed onto the multi-level approver
 * route its dimensions resolve to (TpvSettings::routeFor — Risk / Value / etc.),
 * and decide() then threads it through each level in turn. A single-level route
 * (the default) behaves as a one-shot approval, so nothing downstream changes
 * until a tenant configures a real multi-level rule.
 */
class TpvApprovalService
{
    public function __construct(private TpvSettings $settings) {}

    public function list(int $tenantId, array $filters = [])
    {
        return TpvApproval::forTenant($tenantId)
            ->with(['vendor:id,company_name,vendor_code', 'requester:id,name', 'decider:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['approval_type'] ?? null, fn ($q, $t) => $q->where('approval_type', $t))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->latest('id')
            ->get();
    }

    /**
     * Raise an approval request. `$subject` optionally links the model being
     * approved (its morph type/id + vendor_id are captured when resolvable).
     */
    public function raise(array $data, int $tenantId, int $userId): TpvApproval
    {
        // §12 — resolve the approver route this request must pass, from its
        // dimensions (Risk from the vendor + any explicit route_context the caller
        // supplies). The first matching rule wins; falls back to default_levels.
        $route = $this->settings->routeFor($this->routingContext($data, $tenantId), $tenantId);

        $approval = TpvApproval::create([
            'tenant_id' => $tenantId,
            'approval_type' => $data['approval_type'],
            'subject_type' => $data['subject_type'] ?? null,
            'subject_id' => $data['subject_id'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'Medium',
            'status' => 'Pending',
            'route' => $route,
            'route_index' => 0,
            'requested_by' => $userId,
            'meta' => $data['meta'] ?? null,
        ]);

        Log::channel('tpv')->info('TPV approval raised', [
            'approval_id' => $approval->id, 'tenant_id' => $tenantId, 'type' => $approval->approval_type,
            'route' => $route,
        ]);

        return $approval->load('vendor:id,company_name,vendor_code', 'requester:id,name');
    }

    /**
     * §12 — build the [dimension => value] context routeFor() matches against. Risk
     * is derived from the linked vendor's classification; a caller may pass any
     * further dimensions (value / work_type / workforce_size / site / department)
     * explicitly under `route_context`, which take precedence.
     */
    private function routingContext(array $data, int $tenantId): array
    {
        $context = [];

        if (! empty($data['vendor_id'])) {
            $vendor = Vendor::query()->where('tenant_id', $tenantId)->find($data['vendor_id']);
            if ($vendor && ! empty($vendor->risk_level)) {
                $context['risk'] = $vendor->risk_level;
            }
        }

        foreach (($data['route_context'] ?? []) as $k => $v) {
            $context[$k] = $v;
        }

        return $context;
    }

    /**
     * Approve / reject / cancel. Terminal states cannot be re-decided.
     *
     * §12 — an "approve" on a multi-level route advances the request to the next
     * level and it stays Pending; only the FINAL level's approval marks it Approved.
     * A reject or cancel at any level is terminal. A single-level (default) route
     * approves in one step, exactly as before.
     */
    public function decide(TpvApproval $approval, string $decision, ?string $remarks, User $actor): TpvApproval
    {
        if ($approval->status !== 'Pending') {
            throw new BusinessException("This approval is already {$approval->status}.");
        }

        $map = ['approve' => 'Approved', 'reject' => 'Rejected', 'cancel' => 'Cancelled'];
        if (! isset($map[$decision])) {
            throw new BusinessException("Unknown decision: {$decision}.");
        }
        if ($decision === 'reject' && empty($remarks)) {
            throw new BusinessException('A reason is required to reject an approval.');
        }

        $route = $approval->route ?? [];
        $index = (int) $approval->route_index;
        $level = $route[$index] ?? null;

        // Intermediate level on a multi-level route: sign off this level and pass it
        // up the chain — the request remains Pending on the next approver.
        if ($decision === 'approve' && $index < count($route) - 1) {
            $approval->update(['route_index' => $index + 1]);
            $approval->recordAudit('Approval signed off ('.($level ?? 'level '.$index).')', $actor, $remarks, [
                'type' => $approval->approval_type, 'level' => $level, 'next' => $route[$index + 1] ?? null,
            ]);

            return $approval->load('vendor:id,company_name,vendor_code', 'requester:id,name', 'decider:id,name');
        }

        $approval->update([
            'status' => $map[$decision],
            'decided_by' => $actor->id,
            'decided_at' => now(),
            'decision_remarks' => $remarks,
        ]);

        $approval->recordAudit('Approval '.$map[$decision], $actor, $remarks, [
            'type' => $approval->approval_type, 'level' => $level,
        ]);

        return $approval->load('vendor:id,company_name,vendor_code', 'requester:id,name', 'decider:id,name');
    }
}
