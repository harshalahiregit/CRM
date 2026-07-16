<?php

namespace App\Repositories\Sales;

use App\Models\Sales\Task;
use App\Models\Sales\TaskStatus;
use App\Repositories\BaseRepository;

class TaskRepository extends BaseRepository
{
    protected string $modelClass = Task::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = Task::forTenant($tenantId)
            ->with(['status', 'assignees:id,name', 'creator:id,name'])
            ->withCount(['checklistItems', 'comments']);

        if (!empty($filters['status_id'])) {
            $query->where('status_id', $filters['status_id']);
        }
        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (!empty($filters['assignee'])) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $filters['assignee']));
        }
        if (!empty($filters['taskable_type']) && !empty($filters['taskable_id'])) {
            $query->where('taskable_type', $filters['taskable_type'])
                  ->where('taskable_id', $filters['taskable_id']);
        }
        if (!empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('description', 'like', $s));
        }

        $sort  = $filters['sort'] ?? 'created_at';
        $order = $filters['order'] ?? 'desc';

        return $query->orderBy($sort, $order)->get();
    }

    public function kanbanColumns(int $tenantId)
    {
        $statuses = TaskStatus::forTenant($tenantId)->ordered()->get();

        $tasks = Task::forTenant($tenantId)
            ->with(['assignees:id,name'])
            ->withCount(['checklistItems', 'comments'])
            ->orderBy('kanban_order')
            ->get();

        return $statuses->map(function ($status) use ($tasks) {
            $statusTasks = $tasks->where('status_id', $status->id)->values();
            return [
                'id'           => $status->id,
                'name'         => $status->name,
                'color'        => $status->color,
                'is_completed' => $status->is_completed_status,
                'tasks'        => $statusTasks,
                'count'        => $statusTasks->count(),
            ];
        });
    }
}
