<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrHoliday;
use App\Models\User;
use App\Repositories\Hr\HolidayRepository;
use Illuminate\Support\Facades\Log;

/**
 * Holiday Calendar (Leave Phase 5). Tenant-scoped CRUD with scope validation
 * (Organization / Department / Designation reusing Org Setup) and duplicate
 * blocking (same date + scope). Never hard-deleted — deactivate to retire.
 */
class HolidayService
{
    public function __construct(private HolidayRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->list($tenantId, $f)->map(fn ($h) => $this->present($h))->all(),
            'stats' => $this->repo->stats($tenantId, ! empty($f['year']) && $f['year'] !== 'All' ? (int) $f['year'] : null),
        ];
    }

    /** Flat holiday list for the calendar grid (the frontend lays out the month). */
    public function calendar(int $tenantId, array $f): array
    {
        return $this->repo->list($tenantId, $f)->map(fn ($h) => $this->present($h))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $data = $this->validate($data, $tenantId);
        $holiday = HrHoliday::create([...$this->attrs($data), 'tenant_id' => $tenantId, 'created_by' => $actor?->id, 'updated_by' => $actor?->id]);
        $holiday->recordAudit('Holiday Created', $actor, null, ['title' => $holiday->title, 'date' => $holiday->holiday_date?->toDateString()]);
        $this->log('Holiday created', $tenantId, $holiday->id);

        return $this->show($holiday->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $holiday = $this->find($id, $tenantId);
        $data = $this->validate($data, $tenantId, $holiday);
        $holiday->update([...$this->attrs($data), 'updated_by' => $actor?->id]);
        $holiday->recordAudit('Holiday Updated', $actor, null, ['title' => $holiday->title]);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $holiday = $this->find($id, $tenantId);
        $holiday->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $holiday->recordAudit($active ? 'Holiday Activated' : 'Holiday Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Validation ───────────────────────────────────────── */
    private function validate(array $data, int $tenantId, ?HrHoliday $existing = null): array
    {
        $title = $data['title'] ?? $existing?->title;
        if (! trim((string) $title)) {
            throw new BusinessException('Title is required.');
        }
        $date = $data['holiday_date'] ?? optional($existing?->holiday_date)->toDateString();
        if (! $date) {
            throw new BusinessException('Holiday date is required.');
        }
        $type = $data['holiday_type'] ?? $existing?->holiday_type;
        if (! in_array($type, HrHoliday::TYPES, true)) {
            throw new BusinessException('Invalid holiday type.');
        }
        $scope = $data['applicable_for'] ?? $existing?->applicable_for ?? 'Organization';
        if (! in_array($scope, HrHoliday::SCOPES, true)) {
            throw new BusinessException('Invalid applicable scope.');
        }

        // Resolve scope references; clear the irrelevant one.
        $departmentId = $scope === 'Department' ? ($data['department_id'] ?? $existing?->department_id) : null;
        $designationId = $scope === 'Designation' ? ($data['designation_id'] ?? $existing?->designation_id) : null;

        if ($scope === 'Department') {
            if (! $departmentId) {
                throw new BusinessException('Select a department for a department-scoped holiday.');
            }
            if (! HrDepartment::where('tenant_id', $tenantId)->where('id', $departmentId)->exists()) {
                throw new BusinessException('Selected department is invalid.');
            }
        }
        if ($scope === 'Designation') {
            if (! $designationId) {
                throw new BusinessException('Select a designation for a designation-scoped holiday.');
            }
            if (! HrDesignation::where('tenant_id', $tenantId)->where('id', $designationId)->exists()) {
                throw new BusinessException('Selected designation is invalid.');
            }
        }

        if ($this->repo->existsForScope($tenantId, $date, $scope, $departmentId, $designationId, $existing?->id)) {
            throw new BusinessException('A holiday already exists for that date and scope.');
        }

        // Normalise the resolved values back onto the payload.
        $data['holiday_type']   = $type;
        $data['applicable_for'] = $scope;
        $data['department_id']  = $departmentId;
        $data['designation_id'] = $designationId;

        return $data;
    }

    private function attrs(array $d): array
    {
        $attrs = array_filter([
            'title'          => $d['title'] ?? null,
            'description'    => $d['description'] ?? null,
            'holiday_date'   => $d['holiday_date'] ?? null,
            'holiday_type'   => $d['holiday_type'] ?? null,
            'applicable_for' => $d['applicable_for'] ?? null,
        ], fn ($v) => $v !== null);

        // department/designation are nullable-clearable; booleans handled explicitly.
        if (array_key_exists('department_id', $d)) {
            $attrs['department_id'] = $d['department_id'] ?: null;
        }
        if (array_key_exists('designation_id', $d)) {
            $attrs['designation_id'] = $d['designation_id'] ?: null;
        }
        foreach (['is_optional', 'is_active'] as $b) {
            if (array_key_exists($b, $d)) {
                $attrs[$b] = (bool) $d[$b];
            }
        }

        return $attrs;
    }

    private function present(HrHoliday $h): array
    {
        return [
            'id' => $h->id, 'title' => $h->title, 'description' => $h->description,
            'holiday_date' => optional($h->holiday_date)->toDateString(),
            'holiday_type' => $h->holiday_type, 'applicable_for' => $h->applicable_for,
            'department_id' => $h->department_id, 'department_name' => $h->department?->name,
            'designation_id' => $h->designation_id, 'designation_name' => $h->designation?->name,
            'is_optional' => $h->is_optional, 'is_active' => $h->is_active,
        ];
    }

    private function find(int $id, int $tenantId): HrHoliday
    {
        $holiday = $this->repo->find($id, $tenantId);
        if (! $holiday) {
            throw new BusinessException('Holiday not found', 404);
        }

        return $holiday;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
