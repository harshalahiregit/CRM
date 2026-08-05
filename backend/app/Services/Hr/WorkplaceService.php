<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrBranch;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeWorkLocation;
use App\Models\Hr\HrFloor;
use App\Models\Hr\HrOffice;
use App\Models\User;
use App\Support\Hr\WorkStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Workplace Management: Branch → Office → Floor, and effective-dated seating.
 *
 * Deletes are refused wherever a child or an assignment exists. Cascading would
 * silently erase where people worked, which is the one thing this module is for.
 *
 * Assigning a work location optionally back-fills the employee's `work_state` from
 * the branch — see assignLocation(). That is the payoff for reusing the WorkStates
 * vocabulary on the branch: Professional Tax stops depending on per-employee data
 * entry once branches are set up.
 */
class WorkplaceService
{
    /* ── Branch ───────────────────────────────────────────────────────── */

    public function branches(int $tenantId, array $filters = []): array
    {
        $q = HrBranch::forTenant($tenantId)->withCount('offices');

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['work_state'])) {
            $q->where('work_state', WorkStates::normalize($filters['work_state']));
        }

        return $q->orderByDesc('is_head_office')->orderBy('name')
            ->get()->map(fn ($b) => $this->presentBranch($b))->all();
    }

    public function saveBranch(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $attrs = array_filter([
            'name' => $data['name'] ?? null, 'code' => $data['code'] ?? null,
            'address' => $data['address'] ?? null, 'city' => $data['city'] ?? null,
            'work_state' => $data['work_state'] ?? null, 'pincode' => $data['pincode'] ?? null,
            'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
        ], fn ($v) => $v !== null);

        // Booleans must be assigned, not filtered — `false` is a real value here.
        foreach (['is_head_office', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $attrs[$flag] = (bool) $data[$flag];
            }
        }

        $branch = DB::transaction(function () use ($id, $attrs, $tenantId, $actor) {
            if ($id) {
                $branch = $this->findBranch($id, $tenantId);
                $branch->update($attrs + ['updated_by' => $actor?->id]);
            } else {
                $branch = HrBranch::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
            }

            // Only one head office — a second would make "which is head office?"
            // unanswerable, so setting one clears the rest.
            if (! empty($attrs['is_head_office'])) {
                HrBranch::forTenant($tenantId)->where('id', '!=', $branch->id)
                    ->update(['is_head_office' => false]);
            }

            return $branch;
        });

        $branch->recordAudit($id ? 'Branch Updated' : 'Branch Created', $actor, null, ['name' => $branch->name]);

        return $this->presentBranch($branch->fresh()->loadCount('offices'));
    }

    public function deleteBranch(int $id, int $tenantId, ?User $actor = null): void
    {
        $branch = $this->findBranch($id, $tenantId);

        if (HrOffice::forTenant($tenantId)->where('branch_id', $id)->exists()) {
            throw new BusinessException('This branch has offices. Remove them first, or deactivate the branch.');
        }
        if (HrEmployeeWorkLocation::forTenant($tenantId)->where('branch_id', $id)->exists()) {
            throw new BusinessException('Employees have been assigned to this branch. Deactivate it instead of deleting it.');
        }

        $branch->recordAudit('Branch Deleted', $actor, null, ['name' => $branch->name]);
        $branch->delete();
    }

    /* ── Office ───────────────────────────────────────────────────────── */

    public function offices(int $tenantId, ?int $branchId = null): array
    {
        return HrOffice::forTenant($tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,name')->withCount('floors')
            ->orderBy('name')->get()->map(fn ($o) => $this->presentOffice($o))->all();
    }

    public function saveOffice(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        if (! empty($data['branch_id'])) {
            $this->findBranch((int) $data['branch_id'], $tenantId);   // tenant guard
        }

        $attrs = array_filter([
            'branch_id' => $data['branch_id'] ?? null, 'name' => $data['name'] ?? null,
            'code' => $data['code'] ?? null, 'address' => $data['address'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('is_active', $data)) {
            $attrs['is_active'] = (bool) $data['is_active'];
        }

        if ($id) {
            $office = $this->findOffice($id, $tenantId);
            $office->update($attrs + ['updated_by' => $actor?->id]);
        } else {
            $office = HrOffice::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
        }

        $office->recordAudit($id ? 'Office Updated' : 'Office Created', $actor);

        return $this->presentOffice($office->fresh(['branch'])->loadCount('floors'));
    }

    public function deleteOffice(int $id, int $tenantId, ?User $actor = null): void
    {
        $office = $this->findOffice($id, $tenantId);

        if (HrFloor::forTenant($tenantId)->where('office_id', $id)->exists()) {
            throw new BusinessException('This office has floors. Remove them first.');
        }
        if (HrEmployeeWorkLocation::forTenant($tenantId)->where('office_id', $id)->exists()) {
            throw new BusinessException('Employees have been assigned to this office. Deactivate it instead.');
        }

        $office->recordAudit('Office Deleted', $actor);
        $office->delete();
    }

    /* ── Floor ────────────────────────────────────────────────────────── */

    public function floors(int $tenantId, ?int $officeId = null): array
    {
        return HrFloor::forTenant($tenantId)
            ->when($officeId, fn ($q) => $q->where('office_id', $officeId))
            ->with('office:id,name,branch_id')
            ->orderBy('name')->get()->map(fn ($f) => $this->presentFloor($f, $tenantId))->all();
    }

    public function saveFloor(?int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        if (! empty($data['office_id'])) {
            $this->findOffice((int) $data['office_id'], $tenantId);
        }

        $attrs = array_filter([
            'office_id' => $data['office_id'] ?? null, 'name' => $data['name'] ?? null,
            'code' => $data['code'] ?? null, 'seat_capacity' => $data['seat_capacity'] ?? null,
        ], fn ($v) => $v !== null);

        if (array_key_exists('is_active', $data)) {
            $attrs['is_active'] = (bool) $data['is_active'];
        }

        if ($id) {
            $floor = $this->findFloor($id, $tenantId);
            $floor->update($attrs + ['updated_by' => $actor?->id]);
        } else {
            $floor = HrFloor::create($attrs + ['tenant_id' => $tenantId, 'created_by' => $actor?->id]);
        }

        $floor->recordAudit($id ? 'Floor Updated' : 'Floor Created', $actor);

        return $this->presentFloor($floor->fresh(['office']), $tenantId);
    }

    public function deleteFloor(int $id, int $tenantId, ?User $actor = null): void
    {
        $floor = $this->findFloor($id, $tenantId);

        if (HrEmployeeWorkLocation::forTenant($tenantId)->where('floor_id', $id)->exists()) {
            throw new BusinessException('Employees are seated on this floor. Deactivate it instead.');
        }

        $floor->recordAudit('Floor Deleted', $actor);
        $floor->delete();
    }

    /* ── Assignment (== history) ──────────────────────────────────────── */

    /**
     * Seat an employee from a date, closing the previous assignment the day before.
     *
     * When `sync_work_state` is set and the branch has a state, the employee's
     * `work_state` is updated to match. That is opt-in rather than automatic
     * because it changes what Professional Tax is computed against — a silent tax
     * change on a desk move would be indefensible.
     */
    public function assignLocation(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find((int) ($data['employee_id'] ?? 0));
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        $branch = $this->findBranch((int) ($data['branch_id'] ?? 0), $tenantId);
        $office = ! empty($data['office_id']) ? $this->findOffice((int) $data['office_id'], $tenantId) : null;
        $floor  = ! empty($data['floor_id']) ? $this->findFloor((int) $data['floor_id'], $tenantId) : null;

        // A floor in another office, or an office in another branch, would produce a
        // seat that does not exist. Reject rather than store an impossible address.
        if ($office && (int) $office->branch_id !== (int) $branch->id) {
            throw new BusinessException('That office does not belong to the selected branch.');
        }
        if ($floor && $office && (int) $floor->office_id !== (int) $office->id) {
            throw new BusinessException('That floor does not belong to the selected office.');
        }
        if ($floor && ! $office) {
            throw new BusinessException('Choose the office the floor belongs to.');
        }

        $from = Carbon::parse($data['effective_from'] ?? now())->startOfDay();

        $assignment = DB::transaction(function () use ($employee, $tenantId, $branch, $office, $floor, $from, $data, $actor) {
            $current = HrEmployeeWorkLocation::forTenant($tenantId)
                ->where('employee_id', $employee->id)->whereNull('effective_to')
                ->orderByDesc('effective_from')->first();

            if ($current) {
                if ($current->effective_from->gt($from)) {
                    throw new BusinessException(
                        'This employee already has a location starting '
                        .$current->effective_from->toDateString().'. Choose a later date.'
                    );
                }
                $current->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            }

            $row = HrEmployeeWorkLocation::create([
                'tenant_id' => $tenantId, 'employee_id' => $employee->id,
                'branch_id' => $branch->id, 'office_id' => $office?->id, 'floor_id' => $floor?->id,
                'seat_no' => $data['seat_no'] ?? null,
                'effective_from' => $from->toDateString(),
                'reason' => $data['reason'] ?? null, 'assigned_by' => $actor?->id,
            ]);

            if (! empty($data['sync_work_state']) && $branch->work_state) {
                $employee->update(['work_state' => $branch->work_state]);
            }

            return $row;
        });

        $assignment->recordAudit('Work Location Assigned', $actor, $data['reason'] ?? null, [
            'employee' => $employee->name, 'branch' => $branch->name,
        ]);
        $this->log('Work location assigned', $tenantId, $assignment->id);

        return $this->presentAssignment($assignment->load(['branch', 'office', 'floor']));
    }

    public function locationHistory(int $employeeId, int $tenantId): array
    {
        return HrEmployeeWorkLocation::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->with(['branch:id,name,work_state', 'office:id,name', 'floor:id,name'])
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->get()->map(fn ($a) => $this->presentAssignment($a))->all();
    }

    /** Current seating for every employee. */
    public function seating(int $tenantId, array $filters = []): array
    {
        $q = HrEmployeeWorkLocation::forTenant($tenantId)
            ->whereNull('effective_to')
            ->with(['employee:id,name,employee_code,department', 'branch:id,name,work_state', 'office:id,name', 'floor:id,name']);

        foreach (['branch_id', 'office_id', 'floor_id'] as $key) {
            if (! empty($filters[$key])) {
                $q->where($key, (int) $filters[$key]);
            }
        }

        return $q->get()->filter(fn ($a) => $a->employee !== null)
            ->map(fn ($a) => $this->presentAssignment($a) + [
                'employee_name' => $a->employee->name,
                'employee_code' => $a->employee->employee_code,
                'department'    => $a->employee->department,
            ])->values()->all();
    }

    /** Branch → office → floor, for cascading selects. */
    public function tree(int $tenantId): array
    {
        return HrBranch::forTenant($tenantId)->where('is_active', true)
            ->with(['offices' => fn ($q) => $q->where('is_active', true)->with(['floors' => fn ($f) => $f->where('is_active', true)])])
            ->orderBy('name')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name, 'work_state' => $b->work_state,
                'offices' => $b->offices->map(fn ($o) => [
                    'id' => $o->id, 'name' => $o->name,
                    'floors' => $o->floors->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->all(),
                ])->all(),
            ])->all();
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    private function findBranch(int $id, int $tenantId): HrBranch
    {
        $branch = HrBranch::forTenant($tenantId)->find($id);
        if (! $branch) {
            throw new BusinessException('Branch not found', 404);
        }

        return $branch;
    }

    private function findOffice(int $id, int $tenantId): HrOffice
    {
        $office = HrOffice::forTenant($tenantId)->find($id);
        if (! $office) {
            throw new BusinessException('Office not found', 404);
        }

        return $office;
    }

    private function findFloor(int $id, int $tenantId): HrFloor
    {
        $floor = HrFloor::forTenant($tenantId)->find($id);
        if (! $floor) {
            throw new BusinessException('Floor not found', 404);
        }

        return $floor;
    }

    private function presentBranch(HrBranch $b): array
    {
        return [
            'id' => $b->id, 'name' => $b->name, 'code' => $b->code,
            'address' => $b->address, 'city' => $b->city, 'work_state' => $b->work_state,
            'pincode' => $b->pincode, 'phone' => $b->phone, 'email' => $b->email,
            'is_head_office' => (bool) $b->is_head_office, 'is_active' => (bool) $b->is_active,
            'offices_count' => $b->offices_count ?? null,
        ];
    }

    private function presentOffice(HrOffice $o): array
    {
        return [
            'id' => $o->id, 'branch_id' => $o->branch_id, 'branch_name' => $o->branch?->name,
            'name' => $o->name, 'code' => $o->code, 'address' => $o->address,
            'is_active' => (bool) $o->is_active, 'floors_count' => $o->floors_count ?? null,
        ];
    }

    private function presentFloor(HrFloor $f, int $tenantId): array
    {
        return [
            'id' => $f->id, 'office_id' => $f->office_id, 'office_name' => $f->office?->name,
            'branch_id' => $f->office?->branch_id,
            'name' => $f->name, 'code' => $f->code,
            'seat_capacity' => $f->seat_capacity,
            'seats_used' => HrEmployeeWorkLocation::forTenant($tenantId)
                ->where('floor_id', $f->id)->whereNull('effective_to')->count(),
            'is_active' => (bool) $f->is_active,
        ];
    }

    private function presentAssignment(HrEmployeeWorkLocation $a): array
    {
        return [
            'id' => $a->id, 'employee_id' => $a->employee_id,
            'branch_id' => $a->branch_id, 'branch_name' => $a->branch?->name,
            'work_state' => $a->branch?->work_state,
            'office_id' => $a->office_id, 'office_name' => $a->office?->name,
            'floor_id' => $a->floor_id, 'floor_name' => $a->floor?->name,
            'seat_no' => $a->seat_no,
            'effective_from' => optional($a->effective_from)->toDateString(),
            'effective_to' => optional($a->effective_to)->toDateString(),
            'is_current' => $a->effective_to === null,
            'reason' => $a->reason,
        ];
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
