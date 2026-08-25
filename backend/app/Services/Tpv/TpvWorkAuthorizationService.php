<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\WorkPermit;
use App\Support\Tpv\TpvWorkerStatus;
use App\Support\Vendor\VendorStatus;

/**
 * Unified Work Authorization (Sangoe TPV §19).
 *
 * "A vendor being Active should NOT automatically mean all work is authorized."
 * This composes the six factors the doc names — Vendor Approval + Compliance +
 * Worker Competency + PPE + Permit + Work Package — into ONE verdict.
 *
 * This service is a READ verdict (roster + per-worker view). The hard enforcement
 * of Rule 4 (competency) and Rule 6 (permit for high-risk work) lives in
 * TpvWorkerService::blockers() — badge issuance is refused when a worker lacks a
 * required competency, or when their package has a high-risk activity with no valid
 * permit. This verdict mirrors those two as REQUIRED checks so the roster agrees
 * with the gate; medical/induction/PPE are the same signals the badge gate uses.
 */
class TpvWorkAuthorizationService
{
    public function __construct(private TpvCompetencyService $competency) {}

    /**
     * Authorize a worker for work — optionally for a specific activity. Returns
     * { authorized, checks: [{key,label,required,passed,detail}] }.
     */
    public function authorize(TpvWorker $worker, ?TpvActivity $activity = null): array
    {
        $worker->loadMissing('vendor', 'medical', 'induction');
        $vendor = $worker->vendor;
        $checks = [];

        // 1 — Vendor approval.
        $vendorOk = $vendor && $vendor->status === VendorStatus::ACTIVE;
        $checks[] = $this->check('vendor', 'Vendor approved', true, $vendorOk,
            $vendor ? $vendor->status_label : 'No employing vendor');

        // 2 — Compliance current (auto-suspension flags lapsed statutory cover).
        $complianceOk = $vendorOk && ! $vendor->auto_suspended;
        $checks[] = $this->check('compliance', 'Compliance current', true, $complianceOk,
            ($vendor && $vendor->auto_suspended) ? 'Vendor auto-suspended for lapsed compliance' : 'OK');

        // 3 — Medical fitness (valid, not expired).
        $medOk = (bool) $worker->medical?->isCurrentlyValid();
        $checks[] = $this->check('medical', 'Medical fitness', true, $medOk,
            $worker->medical ? ($medOk ? 'Fit & current' : 'Unfit or expired') : 'Not recorded');

        // 4 — HSSE induction passed.
        $indOk = (bool) $worker->induction?->passed;
        $checks[] = $this->check('induction', 'HSSE induction', true, $indOk,
            $worker->induction ? ($indOk ? 'Passed' : 'Not passed') : 'Not recorded');

        // 5 — Mandatory PPE issued.
        $missing = app(PpeInventoryService::class)->missingMandatoryFor($worker);
        $ppeOk = $missing->isEmpty();
        $checks[] = $this->check('ppe', 'Mandatory PPE', true, $ppeOk,
            $ppeOk ? 'Issued' : 'Missing: '.$missing->pluck('name')->implode(', '));

        // 6 — Competency (Rule 4). Required only where an activity names one.
        [$compReq, $compOk, $compDetail] = $this->competencyCheck($worker, $activity);
        $checks[] = $this->check('competency', 'Competency', $compReq, $compOk, $compDetail);

        // 7 — Work package assignment (advisory — accountability spine).
        $wpOk = ! empty($worker->work_package_id);
        $checks[] = $this->check('work_package', 'Work package', false, $wpOk,
            $wpOk ? 'Assigned' : 'Not assigned to a work package');

        // 8 — Permit (Rule 6). REQUIRED only where the activity/package is flagged
        // high-risk (requires_permit); otherwise advisory. A valid PTW for the vendor
        // — matching the pinned type where one is named — covers high-risk work.
        [$permReq, $permOk, $permDetail] = $this->permitCheck($worker, $activity, $vendor);
        $checks[] = $this->check('permit', 'Permit-to-Work', $permReq, $permOk, $permDetail);

        // Authorized when every REQUIRED check passes.
        $authorized = collect($checks)->every(fn ($c) => ! $c['required'] || $c['passed']);

        return [
            'worker' => $worker->only(['id', 'name', 'worker_code', 'status']),
            'activity' => $activity?->only(['id', 'name', 'required_competency']),
            'authorized' => $authorized,
            'checks' => $checks,
        ];
    }

    /** Every active worker's authorization summary — the Work-Control roster. */
    public function roster(int $tenantId, array $filters = []): array
    {
        return TpvWorker::forTenant($tenantId)
            ->where('status', TpvWorkerStatus::ACTIVE)
            ->with('vendor:id,company_name,status,auto_suspended')
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->orderByDesc('id')
            ->get()
            ->map(function ($w) {
                $v = $this->authorize($w);
                $failed = collect($v['checks'])->filter(fn ($c) => $c['required'] && ! $c['passed'])->pluck('label')->values();

                return [
                    'id' => $w->id,
                    'name' => $w->name,
                    'worker_code' => $w->worker_code,
                    'vendor' => $w->vendor?->company_name,
                    'authorized' => $v['authorized'],
                    'blockers' => $failed->all(),
                ];
            })->all();
    }

    private function competencyCheck(TpvWorker $worker, ?TpvActivity $activity): array
    {
        if ($activity) {
            $req = $activity->required_competency;
            if (empty($req)) {
                return [false, true, 'Activity names no required competency'];
            }
            $ok = $this->competency->workerHasCompetency($worker->id, $req);

            return [true, $ok, $ok ? "Holds \"{$req}\"" : "Missing \"{$req}\""];
        }

        // No specific activity: check every activity of the worker's package.
        if (empty($worker->work_package_id)) {
            return [false, true, 'No activity / package context'];
        }
        $required = TpvActivity::where('tenant_id', $worker->tenant_id)
            ->where('work_package_id', $worker->work_package_id)
            ->whereNotNull('required_competency')
            ->pluck('required_competency')->unique();
        if ($required->isEmpty()) {
            return [false, true, 'Package activities name no competencies'];
        }
        $missing = $required->reject(fn ($r) => $this->competency->workerHasCompetency($worker->id, $r));

        return [true, $missing->isEmpty(), $missing->isEmpty() ? 'Meets all package competencies' : 'Missing: '.$missing->implode(', ')];
    }

    /**
     * Rule 6 — is a valid Permit-to-Work required and present? Required only where
     * the specific activity (or, with no activity, any package activity) is flagged
     * requires_permit. Passes when the vendor holds an Approved/Active, non-expired
     * permit of the pinned type (or any type where none is pinned).
     */
    private function permitCheck(TpvWorker $worker, ?TpvActivity $activity, $vendor): array
    {
        $hasValidPermit = function (?string $type) use ($worker, $vendor): bool {
            if (! $vendor) {
                return false;
            }

            return WorkPermit::where('tenant_id', $worker->tenant_id)
                ->where('vendor_id', $vendor->id)
                ->whereIn('status', ['Approved', 'Active'])
                ->when($type, fn ($q, $t) => $q->where('type', $t))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
                ->exists();
        };

        // Specific activity in view.
        if ($activity) {
            if (! $activity->requires_permit) {
                return [false, $hasValidPermit(null), $hasValidPermit(null) ? 'Active permit on file' : 'Not high-risk — no permit required'];
            }
            $ok = $hasValidPermit($activity->permit_type);
            $need = $activity->permit_type ? str_replace('_', ' ', $activity->permit_type).' permit' : 'a valid permit';

            return [true, $ok, $ok ? 'Valid '.$need.' held' : 'Missing '.$need];
        }

        // Whole package: any high-risk activity ⇒ required.
        if (empty($worker->work_package_id)) {
            return [false, $hasValidPermit(null), 'No activity / package context'];
        }
        $permitActs = TpvActivity::where('tenant_id', $worker->tenant_id)
            ->where('work_package_id', $worker->work_package_id)
            ->where('requires_permit', true)
            ->get(['name', 'permit_type']);
        if ($permitActs->isEmpty()) {
            return [false, $hasValidPermit(null), 'No high-risk activity in package'];
        }
        $missing = $permitActs->reject(fn ($a) => $hasValidPermit($a->permit_type));

        return [true, $missing->isEmpty(),
            $missing->isEmpty() ? 'Permits cover all high-risk activities' : 'Missing permit for: '.$missing->pluck('name')->implode(', ')];
    }

    private function check(string $key, string $label, bool $required, bool $passed, string $detail): array
    {
        return compact('key', 'label', 'required', 'passed', 'detail');
    }
}
