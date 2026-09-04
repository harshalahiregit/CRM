<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Company events for the HR calendar.
 *
 * Mirrors HolidayService deliberately — same list/create/update/setStatus shape,
 * same presenter contract — because the admin screen shows the two side by side
 * and the attendance app reads them the same way.
 */
class EventService
{
    public function list(int $tenantId, array $f): array
    {
        $q = HrEvent::where('tenant_id', $tenantId);

        if (! empty($f['year']) && $f['year'] !== 'All') {
            $q->whereYear('start_date', (int) $f['year']);
        }
        if (! empty($f['search'])) {
            $q->where('title', 'like', '%'.$f['search'].'%');
        }
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All') {
            $q->where('is_active', $f['status'] === 'Active' || $f['status'] === '1');
        }
        if (! empty($f['department_id'])) {
            $q->where('department_id', (int) $f['department_id']);
        }

        $rows = $q->orderBy('start_date')->get();

        return [
            'data'  => $rows->map(fn ($e) => $this->present($e))->all(),
            'stats' => [
                'total'    => $rows->count(),
                'active'   => $rows->where('is_active', true)->count(),
                'inactive' => $rows->where('is_active', false)->count(),
            ],
        ];
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId));
    }

    public function create(array $data, int $tenantId, ?User $actor = null): array
    {
        $event = HrEvent::create([
            ...$this->attrs($data),
            'tenant_id'  => $tenantId,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $event->recordAudit('Event Created', $actor, null, [
            'title' => $event->title, 'date' => $event->start_date?->toDateString(),
        ]);
        $this->log('Event created', $tenantId, $event->id);

        return $this->show($event->id, $tenantId);
    }

    public function update(int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $event = $this->find($id, $tenantId);
        $event->update([...$this->attrs($data, $event), 'updated_by' => $actor?->id]);
        $event->recordAudit('Event Updated', $actor);

        return $this->show($id, $tenantId);
    }

    public function setStatus(int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $event = $this->find($id, $tenantId);
        $event->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $event->recordAudit($active ? 'Event Activated' : 'Event Deactivated', $actor);

        return $this->show($id, $tenantId);
    }

    private function find(int $id, int $tenantId): HrEvent
    {
        return HrEvent::where('tenant_id', $tenantId)->findOrFail($id);
    }

    /**
     * Only the target the chosen scope uses is kept.
     *
     * A department id left behind on an event later switched to Organization
     * would keep narrowing it to that one team, which is invisible from the
     * form — the row simply stops reaching most people.
     */
    private function attrs(array $data, ?HrEvent $existing = null): array
    {
        $scope = $data['applicable_for'] ?? $existing?->applicable_for ?? 'Organization';

        $attrs = [
            'title'          => $data['title'] ?? $existing?->title,
            'description'    => $data['description'] ?? $existing?->description,
            'start_date'     => $data['start_date'] ?? $existing?->start_date,
            'color'          => $data['color'] ?? $existing?->color ?? '#7C3AED',
            'applicable_for' => $scope,
            'department_id'  => $scope === 'Department'  ? ($data['department_id'] ?? null) : null,
            'designation_id' => $scope === 'Designation' ? ($data['designation_id'] ?? null) : null,
            'is_active'      => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($existing?->is_active ?? true),
        ];

        // An end before the start is a typo, not a negative event: carry the
        // start along rather than storing a range that can never match a day.
        $end = $data['end_date'] ?? $existing?->end_date;
        $attrs['end_date'] = ($end && $end < $attrs['start_date']) ? $attrs['start_date'] : $end;

        return $attrs;
    }

    private function present(HrEvent $e): array
    {
        return [
            'id'              => $e->id,
            'title'           => $e->title,
            'description'     => $e->description,
            'start_date'      => $e->start_date?->toDateString(),
            'end_date'        => $e->effectiveEnd()->toDateString(),
            'color'           => $e->color,
            'applicable_for'  => $e->applicable_for,
            'department_id'   => $e->department_id,
            'department_name' => $e->department?->name,
            'designation_id'  => $e->designation_id,
            'designation_name' => $e->designation?->name,
            'is_active'       => (bool) $e->is_active,
        ];
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::info($msg, ['tenant_id' => $tenantId, 'event_id' => $id]);
    }
}
