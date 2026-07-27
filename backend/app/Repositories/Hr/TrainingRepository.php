<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrTrainingCategory;
use App\Models\Hr\HrTrainingProgram;
use App\Models\Hr\HrTrainingProvider;
use App\Models\Hr\HrTrainingSession;
use App\Models\Hr\HrTrainingType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Learning & Development masters (Phase 1). Tenant-scoped; no writes. */
class TrainingRepository
{
    /* ── Categories ───────────────────────────────────────── */
    public function categories(int $tenantId, array $f): Collection
    {
        return HrTrainingCategory::where('tenant_id', $tenantId)
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findCategory(int $id, int $tenantId): ?HrTrainingCategory
    {
        return HrTrainingCategory::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Types ────────────────────────────────────────────── */
    public function types(int $tenantId, array $f): Collection
    {
        return HrTrainingType::where('tenant_id', $tenantId)
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findType(int $id, int $tenantId): ?HrTrainingType
    {
        return HrTrainingType::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Providers ────────────────────────────────────────── */
    public function providers(int $tenantId, array $f): Collection
    {
        return HrTrainingProvider::where('tenant_id', $tenantId)
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['provider_type']) && $f['provider_type'] !== 'All', fn ($q) => $q->where('provider_type', $f['provider_type']))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('contact_person', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findProvider(int $id, int $tenantId): ?HrTrainingProvider
    {
        return HrTrainingProvider::where('tenant_id', $tenantId)->find($id);
    }

    /* ── Programs (Phase 2) ───────────────────────────────── */
    private const PROGRAM_EAGER = [
        'category:id,name,code', 'trainingType:id,name,code,mode', 'provider:id,name,provider_type',
        'department:id,name', 'designation:id,name',
    ];

    public function programs(int $tenantId, array $f): Collection
    {
        return HrTrainingProgram::where('tenant_id', $tenantId)
            ->with(self::PROGRAM_EAGER)
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['category_id']), fn ($q) => $q->where('category_id', $f['category_id']))
            ->when(! empty($f['training_type_id']), fn ($q) => $q->where('training_type_id', $f['training_type_id']))
            ->when(! empty($f['provider_id']), fn ($q) => $q->where('provider_id', $f['provider_id']))
            ->when(! empty($f['mode']) && $f['mode'] !== 'All', fn ($q) => $q->where('mode', $f['mode']))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('program_name', 'like', '%'.$f['search'].'%')->orWhere('program_code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('program_name')->get();
    }

    public function findProgram(int $id, int $tenantId): ?HrTrainingProgram
    {
        return HrTrainingProgram::where('tenant_id', $tenantId)->with(self::PROGRAM_EAGER)->find($id);
    }

    public function programStats(int $tenantId): array
    {
        $base = HrTrainingProgram::where('tenant_id', $tenantId);

        return [
            'total'         => (clone $base)->count(),
            'active'        => (clone $base)->where('is_active', true)->count(),
            'inactive'      => (clone $base)->where('is_active', false)->count(),
            'certification' => (clone $base)->where('certification_applicable', true)->count(),
            'total_seats'   => (int) (clone $base)->where('is_active', true)->sum('capacity'),
        ];
    }

    /* ── Sessions (Phase 3) ───────────────────────────────── */
    private const SESSION_EAGER = [
        'program:id,program_name,program_code,category_id', 'program.category:id,name',
        'provider:id,name,provider_type', 'department:id,name', 'designation:id,name',
    ];

    public function sessions(int $tenantId, array $f): Collection
    {
        return HrTrainingSession::where('tenant_id', $tenantId)
            ->with(self::SESSION_EAGER)
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['training_program_id']), fn ($q) => $q->where('training_program_id', $f['training_program_id']))
            ->when(! empty($f['provider_id']), fn ($q) => $q->where('provider_id', $f['provider_id']))
            ->when(! empty($f['department_id']), fn ($q) => $q->where('department_id', $f['department_id']))
            ->when(! empty($f['mode']) && $f['mode'] !== 'All', fn ($q) => $q->where('mode', $f['mode']))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('start_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('start_at', '<=', $f['to']))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('title', 'like', '%'.$f['search'].'%')->orWhere('trainer_name', 'like', '%'.$f['search'].'%')
                  ->orWhereHas('program', fn ($p) => $p->where('program_name', 'like', '%'.$f['search'].'%'));
            }))
            ->orderBy('start_at')->get();
    }

    /** All sessions whose start_at falls in the given month — for the calendar grid. */
    public function sessionsForMonth(int $tenantId, int $year, int $month): Collection
    {
        return HrTrainingSession::where('tenant_id', $tenantId)
            ->with(self::SESSION_EAGER)
            ->whereYear('start_at', $year)->whereMonth('start_at', $month)
            ->orderBy('start_at')->get();
    }

    public function findSession(int $id, int $tenantId): ?HrTrainingSession
    {
        return HrTrainingSession::where('tenant_id', $tenantId)->with([...self::SESSION_EAGER, 'auditLogs'])->find($id);
    }

    public function sessionStats(int $tenantId): array
    {
        $base = fn () => HrTrainingSession::where('tenant_id', $tenantId);
        $now = Carbon::now();
        $today = $now->toDateString();

        return [
            'upcoming'  => (int) $base()->where('status', HrTrainingSession::SCHEDULED)->where('start_at', '>=', $now)->count(),
            'today'     => (int) $base()->whereIn('status', [HrTrainingSession::SCHEDULED, HrTrainingSession::ONGOING])->whereDate('start_at', $today)->count(),
            'ongoing'   => (int) $base()->where('status', HrTrainingSession::ONGOING)->count(),
            'completed' => (int) $base()->where('status', HrTrainingSession::COMPLETED)->count(),
            'cancelled' => (int) $base()->where('status', HrTrainingSession::CANCELLED)->count(),
        ];
    }

    /** Total / active / inactive for any of the three masters. */
    public function stats(string $model, int $tenantId): array
    {
        $base = $model::where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];
    }

    private function statusSet(array $f): bool
    {
        return isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All';
    }
}
