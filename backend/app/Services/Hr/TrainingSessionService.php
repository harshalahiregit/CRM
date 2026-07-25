<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrTrainingProgram;
use App\Models\Hr\HrTrainingProvider;
use App\Models\Hr\HrTrainingSession;
use App\Models\User;
use App\Repositories\Hr\TrainingRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Training Sessions & Calendar (L&D Phase 3). Schedules an instance of a Training
 * Program (Phase 2), reusing Provider / Department / Designation. Lifecycle is
 * strictly guarded: Scheduled → Ongoing → Completed, Cancelled from either;
 * Completed / Cancelled are terminal (immutable). No employee assignment here
 * (Phase 4). Tenant-scoped, audited.
 */
class TrainingSessionService
{
    private const MODES = ['Online', 'Offline', 'Hybrid'];
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(private TrainingRepository $repo)
    {
    }

    public function list(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->sessions($tenantId, $f)->map(fn ($s) => $this->present($s))->all(),
            'stats' => $this->repo->sessionStats($tenantId),
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    /** Calendar payload: sessions for a month grouped by date, plus KPI stats. */
    public function calendar(int $tenantId, array $f): array
    {
        $year = (int) ($f['year'] ?? now()->year);
        $month = (int) ($f['month'] ?? now()->month);
        $month = max(1, min(12, $month));

        $sessions = $this->repo->sessionsForMonth($tenantId, $year, $month)->map(fn ($s) => $this->present($s));
        $byDate = [];
        foreach ($sessions as $s) {
            $byDate[$s['date']][] = $s;
        }

        return [
            'year' => $year, 'month' => $month, 'month_label' => self::MONTHS[$month].' '.$year,
            'sessions' => $sessions->all(),
            'by_date' => $byDate,
            'stats' => $this->repo->sessionStats($tenantId),
        ];
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $program = $this->assertProgram((int) ($data['training_program_id'] ?? 0), $tenantId);
        [$start, $end] = $this->assertWindow($data);
        $this->assertRefs($data, $tenantId);

        $session = HrTrainingSession::create([
            'tenant_id'           => $tenantId,
            'training_program_id' => $program->id,
            'provider_id'         => $data['provider_id'] ?? $program->provider_id,
            'department_id'       => $data['department_id'] ?? $program->department_id,
            'designation_id'      => $data['designation_id'] ?? $program->designation_id,
            'title'               => $data['title'] ?? ($program->program_name.' — '.$start->format('d M Y')),
            'trainer_name'        => $data['trainer_name'],
            'mode'                => $this->mode($data['mode'] ?? $program->mode),
            'venue'               => $data['venue'] ?? null,
            'meeting_url'         => $data['meeting_url'] ?? null,
            'start_at'            => $start,
            'end_at'              => $end,
            'capacity'            => isset($data['capacity']) ? max(1, (int) $data['capacity']) : (int) $program->capacity,
            'status'              => HrTrainingSession::SCHEDULED,
            'notes'               => $data['notes'] ?? null,
            'created_by'          => $actor?->id,
            'updated_by'          => $actor?->id,
        ]);
        $session->recordAudit('Training Session Scheduled', $actor, null, ['program' => $program->program_name, 'start' => $start->toDateTimeString()]);
        $this->log('Training session scheduled', $tenantId, $session->id);

        return $this->show($session->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $session = $this->find($id, $tenantId);
        if (in_array($session->status, HrTrainingSession::TERMINAL, true)) {
            throw new BusinessException('A completed or cancelled session can no longer be edited.');
        }
        $this->assertRefs($data, $tenantId);

        $attrs = ['updated_by' => $actor?->id];
        foreach (['trainer_name', 'venue', 'meeting_url', 'title', 'notes'] as $k) {
            if (array_key_exists($k, $data)) {
                $attrs[$k] = $data[$k];
            }
        }
        foreach (['provider_id', 'department_id', 'designation_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $attrs[$k] = $data[$k] ?: null;
            }
        }
        if (array_key_exists('mode', $data)) {
            $attrs['mode'] = $this->mode($data['mode']);
        }
        if (array_key_exists('capacity', $data)) {
            $attrs['capacity'] = max(1, (int) $data['capacity']);
        }
        if (array_key_exists('start_at', $data) || array_key_exists('end_at', $data)) {
            [$start, $end] = $this->assertWindow([
                'start_at' => $data['start_at'] ?? optional($session->start_at)->toDateTimeString(),
                'end_at'   => $data['end_at'] ?? optional($session->end_at)->toDateTimeString(),
            ]);
            $attrs['start_at'] = $start;
            $attrs['end_at'] = $end;
        }

        $session->update($attrs);
        $session->recordAudit('Training Session Updated', $actor, null, ['title' => $session->title]);

        return $this->show($id, $tenantId);
    }

    /** Status transition. Scheduled → Ongoing/Completed/Cancelled; Ongoing → Completed/Cancelled. */
    public function setStatus(int $id, string $status, int $tenantId, ?User $actor = null): array
    {
        $session = $this->find($id, $tenantId);
        $status = ucwords(strtolower(trim($status)));
        $allowed = [
            HrTrainingSession::SCHEDULED => [HrTrainingSession::ONGOING, HrTrainingSession::COMPLETED, HrTrainingSession::CANCELLED],
            HrTrainingSession::ONGOING   => [HrTrainingSession::COMPLETED, HrTrainingSession::CANCELLED],
        ];

        if (in_array($session->status, HrTrainingSession::TERMINAL, true)) {
            throw new BusinessException("This session is already {$session->status} and cannot change.");
        }
        if (! in_array($status, $allowed[$session->status] ?? [], true)) {
            throw new BusinessException("Cannot move a {$session->status} session to {$status}.");
        }

        $session->update(['status' => $status, 'updated_by' => $actor?->id]);
        $action = match ($status) {
            HrTrainingSession::ONGOING   => 'Training Session Started',
            HrTrainingSession::COMPLETED => 'Training Session Completed',
            HrTrainingSession::CANCELLED => 'Training Session Cancelled',
            default                      => 'Training Session Updated',
        };
        $session->recordAudit($action, $actor);
        $this->log('Training session status changed', $tenantId, $session->id);

        return $this->show($id, $tenantId);
    }

    /* ── Validation helpers ───────────────────────────────── */

    private function assertProgram(int $programId, int $tenantId): HrTrainingProgram
    {
        $program = HrTrainingProgram::where('tenant_id', $tenantId)->find($programId);
        if (! $program) {
            throw new BusinessException('Selected training program is invalid.');
        }
        if (! $program->is_active) {
            throw new BusinessException('Cannot schedule a session for an inactive program.');
        }

        return $program;
    }

    /** Parse + validate the session window; end must be after start. */
    private function assertWindow(array $data): array
    {
        if (empty($data['start_at']) || empty($data['end_at'])) {
            throw new BusinessException('Session start and end times are required.');
        }
        $start = Carbon::parse($data['start_at']);
        $end = Carbon::parse($data['end_at']);
        if ($end->lte($start)) {
            throw new BusinessException('Session end time must be after the start time.');
        }

        return [$start, $end];
    }

    private function assertRefs(array $d, int $tenantId): void
    {
        if (! empty($d['provider_id']) && ! HrTrainingProvider::where('tenant_id', $tenantId)->where('id', $d['provider_id'])->exists()) {
            throw new BusinessException('Selected provider is invalid.');
        }
        if (! empty($d['department_id']) && ! HrDepartment::where('tenant_id', $tenantId)->where('id', $d['department_id'])->exists()) {
            throw new BusinessException('Selected department is invalid.');
        }
        if (! empty($d['designation_id']) && ! HrDesignation::where('tenant_id', $tenantId)->where('id', $d['designation_id'])->exists()) {
            throw new BusinessException('Selected designation is invalid.');
        }
        if (array_key_exists('capacity', $d) && $d['capacity'] !== null && (int) $d['capacity'] <= 0) {
            throw new BusinessException('Capacity must be greater than zero.');
        }
    }

    private function mode(?string $mode): string
    {
        return in_array($mode, self::MODES, true) ? $mode : 'Offline';
    }

    private function present(HrTrainingSession $s, bool $full = false): array
    {
        $out = [
            'id' => $s->id,
            'title' => $s->title,
            'training_program_id' => $s->training_program_id,
            'program' => $s->program?->program_name, 'program_code' => $s->program?->program_code,
            'category' => $s->program?->category?->name,
            'provider_id' => $s->provider_id, 'provider' => $s->provider?->name,
            'department_id' => $s->department_id, 'department' => $s->department?->name,
            'designation_id' => $s->designation_id, 'designation' => $s->designation?->name,
            'trainer_name' => $s->trainer_name, 'mode' => $s->mode,
            'venue' => $s->venue, 'meeting_url' => $s->meeting_url,
            'start_at' => optional($s->start_at)->toIso8601String(),
            'end_at' => optional($s->end_at)->toIso8601String(),
            'date' => optional($s->start_at)->toDateString(),
            'capacity' => $s->capacity, 'status' => $s->status,
            'notes' => $s->notes,
        ];

        if ($full) {
            $out['timeline'] = $s->relationLoaded('auditLogs')
                ? $s->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrTrainingSession
    {
        $session = $this->repo->findSession($id, $tenantId);
        if (! $session) {
            throw new BusinessException('Training session not found', 404);
        }

        return $session;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
