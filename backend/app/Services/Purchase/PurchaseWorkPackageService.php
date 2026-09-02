<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseActivity;
use App\Models\Purchase\PurchaseWorkPackage;
use App\Models\Purchase\PurchaseWorker;

/**
 * Purchase work packages + the work-authorisation check.
 *
 * Mirrors TpvWorkPackageService and TpvWorkAuthorizationService on Purchase's
 * tables. Authorisation is DERIVED on every call rather than stored: a worker
 * whose medical lapsed overnight must stop being authorised overnight, and a
 * cached flag would keep letting them work until something remembered to clear
 * it.
 */
class PurchaseWorkPackageService
{
    public function __construct(private PurchaseWorkforceService $workforce)
    {
    }

    /* ── Packages ────────────────────────────────────────────────────────── */

    public function list(int $tenantId, array $filters = [])
    {
        $q = PurchaseWorkPackage::where('tenant_id', $tenantId)
            ->with(['vendor:id,company_name,purchase_vendor_code', 'activities'])
            ->withCount('workers');

        if (! empty($filters['status'])) {
            $q->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $q->where('purchase_vendor_id', (int) $filters['vendor_id']);
        }

        return $q->latest('id')->get();
    }

    public function create(int $tenantId, array $data, ?int $userId): PurchaseWorkPackage
    {
        return PurchaseWorkPackage::create(array_merge($data, [
            'tenant_id'  => $tenantId,
            'status'     => $data['status'] ?? 'Planned',
            'created_by' => $userId,
        ]))->fresh(['activities', 'vendor']);
    }

    public function update(PurchaseWorkPackage $wp, array $data): PurchaseWorkPackage
    {
        $wp->update($data);

        return $wp->fresh(['activities', 'vendor']);
    }

    public function delete(PurchaseWorkPackage $wp): void
    {
        // Workers point at the package by id. Deleting it while people are
        // assigned would leave them accountable to nothing, so the assignment
        // is cleared first rather than left dangling.
        PurchaseWorker::where('work_package_id', $wp->id)->update(['work_package_id' => null]);
        $wp->activities()->delete();
        $wp->delete();
    }

    /* ── Activities ──────────────────────────────────────────────────────── */

    public function addActivity(PurchaseWorkPackage $wp, array $data): PurchaseActivity
    {
        return PurchaseActivity::create(array_merge($data, [
            'tenant_id'       => $wp->tenant_id,
            'work_package_id' => $wp->id,
            'status'          => $data['status'] ?? 'Active',
            // Appended, not supplied — two people adding activities must not
            // both claim the same position.
            'sort_order'      => (int) $wp->activities()->max('sort_order') + 1,
        ]));
    }

    public function updateActivity(PurchaseActivity $activity, array $data): PurchaseActivity
    {
        $activity->update($data);

        return $activity->fresh();
    }

    public function deleteActivity(PurchaseActivity $activity): void
    {
        $activity->delete();
    }

    /* ── Work authorisation ──────────────────────────────────────────────── */

    /**
     * May this worker do this work?
     *
     * Every check is returned, not just the failing ones: "not authorised" with
     * no breakdown is unactionable, and the person at the barrier needs to know
     * which of six things to go and fix.
     *
     * `required` separates a hard gate from advice. A missing work-package
     * assignment is an accountability gap worth showing, but it is not a reason
     * to refuse someone whose medical, induction and PPE are all in order.
     */
    public function authorize(PurchaseWorker $worker, ?PurchaseActivity $activity = null): array
    {
        $worker->loadMissing(['vendor', 'latestMedical', 'latestInduction']);
        $readiness = $this->workforce->readiness($worker);
        $vendor = $worker->vendor;
        $checks = [];

        // 1 — Employing vendor is approved.
        $vendorOk = $vendor && $vendor->status === 'Active';
        $checks[] = $this->check('vendor', 'Vendor approved', true, $vendorOk,
            $vendor ? $vendor->status : 'No employing vendor');

        // 2 — The worker is not suspended or terminated in their own right.
        $workerOk = $worker->status === 'Active';
        $checks[] = $this->check('worker', 'Worker active', true, $workerOk, $worker->status);

        // 3 — Medical fitness, current.
        $checks[] = $this->check('medical', 'Medical fitness', true, (bool) $readiness['medical_ok'],
            $worker->latestMedical ? ($readiness['medical_ok'] ? 'Fit & current' : 'Unfit or expired') : 'Not recorded');

        // 4 — Induction.
        $checks[] = $this->check('induction', 'HSSE induction', true, (bool) $readiness['induction_ok'],
            $worker->latestInduction ? ($readiness['induction_ok'] ? 'Completed' : 'Not completed') : 'Not recorded');

        // 5 — Training.
        $checks[] = $this->check('training', 'Training', true, (bool) $readiness['training_ok'],
            $readiness['training_ok'] ? 'Recorded' : 'Not recorded');

        // 6 — Badge issued and unexpired. Without it there is nothing to scan.
        $badgeOk = (bool) $worker->badge_number
            && ! ($worker->badge_valid_until && $worker->badge_valid_until->isPast());
        $checks[] = $this->check('badge', 'Entry badge', true, $badgeOk,
            $worker->badge_number ? ($badgeOk ? 'Valid' : 'Expired') : 'Not issued');

        // 7 — Competency, required ONLY where the activity names one. Asking for
        // a ticket no activity demands would block work for no stated reason.
        $needed = $activity?->required_competency;
        if ($needed) {
            // `status` on PurchaseWorkerCompetency is a COMPUTED accessor
            // (Valid / Expiring / Expired from valid_until), not a column — so
            // it is filtered in PHP. A ->where('status', …) here would match
            // nothing at best and error at worst. The named requirement is
            // matched against `name` or `category`, the columns that exist.
            $matches = $worker->competencies()
                ->where(function ($q) use ($needed) {
                    $q->where('name', $needed)->orWhere('category', $needed);
                })->get();

            // Expiring still counts as held — it has not lapsed yet, and
            // refusing on it would stop work that is legitimately covered
            // today. Expired does not.
            $current = $matches->first(fn ($c) => $c->status !== 'Expired');
            $held = $current !== null;

            $checks[] = $this->check('competency', 'Competency: '.$needed, true, $held,
                $held
                    ? ($current->status === 'Expiring' ? 'Held, expiring soon' : 'Held and valid')
                    : ($matches->isEmpty() ? 'Not held' : 'Held but expired'));
        } else {
            $checks[] = $this->check('competency', 'Competency', false, true,
                $activity ? 'None required for this activity' : 'No activity selected');
        }

        // 8 — Permit, where the activity demands one. Advisory here: the permit
        // is enforced by its own lifecycle, and duplicating that as a hard gate
        // would let a stale read refuse work a live permit already cleared.
        if ($activity?->requires_permit) {
            $checks[] = $this->check('permit', 'Permit to work'.($activity->permit_type ? ' ('.$activity->permit_type.')' : ''),
                false, false, 'This activity requires an active permit — check it is open.');
        }

        // 9 — Work package assignment. Advisory: an accountability gap, not a
        // safety one.
        $wpOk = ! empty($worker->work_package_id);
        $checks[] = $this->check('work_package', 'Work package', false, $wpOk,
            $wpOk ? 'Assigned' : 'Not assigned to a work package');

        $blocking = array_values(array_filter($checks, fn ($c) => $c['required'] && ! $c['ok']));

        return [
            'worker'     => $worker->only(['id', 'full_name', 'worker_code', 'designation', 'status']),
            'activity'   => $activity?->only(['id', 'name', 'required_competency', 'requires_permit', 'permit_type']),
            'authorized' => count($blocking) === 0,
            'checks'     => $checks,
            'blockers'   => array_column($blocking, 'label'),
        ];
    }

    private function check(string $key, string $label, bool $required, bool $ok, ?string $detail = null): array
    {
        return ['key' => $key, 'label' => $label, 'required' => $required, 'ok' => $ok, 'detail' => $detail];
    }

    /** Everyone on a package (or in the tenant), with their authorisation. */
    public function roster(int $tenantId, array $filters = []): array
    {
        $q = PurchaseWorker::forTenant($tenantId)->with(['vendor:id,company_name']);

        if (! empty($filters['work_package_id'])) {
            $q->where('work_package_id', (int) $filters['work_package_id']);
        }
        if (! empty($filters['vendor_id'])) {
            $q->where('purchase_vendor_id', (int) $filters['vendor_id']);
        }

        $activity = ! empty($filters['activity_id'])
            ? PurchaseActivity::where('tenant_id', $tenantId)->find($filters['activity_id'])
            : null;

        $rows = $q->get()->map(fn (PurchaseWorker $w) => $this->authorize($w, $activity));

        return [
            'activity' => $activity?->only(['id', 'name', 'required_competency']),
            'rows'     => $rows->values(),
            'totals'   => [
                'workers'    => $rows->count(),
                'authorized' => $rows->where('authorized', true)->count(),
                'blocked'    => $rows->where('authorized', false)->count(),
            ],
        ];
    }

    /** Assign a worker to a package, or clear it with null. */
    public function assignWorker(PurchaseWorker $worker, ?int $packageId): PurchaseWorker
    {
        if ($packageId !== null) {
            $wp = PurchaseWorkPackage::where('tenant_id', $worker->tenant_id)->find($packageId);
            // Cross-tenant or unknown package would silently orphan the worker.
            if (! $wp) {
                throw new BusinessException('Work package not found.', 404);
            }
        }

        $worker->forceFill(['work_package_id' => $packageId])->save();

        return $worker->fresh();
    }
}
