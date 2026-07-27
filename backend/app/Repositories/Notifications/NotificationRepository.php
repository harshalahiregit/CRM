<?php

namespace App\Repositories\Notifications;

use App\Models\Notifications\HrNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Read queries for notifications (Central Notification Engine). Tenant-scoped; no writes. */
class NotificationRepository
{
    /**
     * Notifications visible to a user: their own, plus role-queue notifications when
     * they manage the HR queue. Tenant-scoped first.
     */
    public function visibleTo(User $user): Builder
    {
        $tenantId = (int) $user->tenant_id;
        $canQueue = method_exists($user, 'canManageHrQueue') && $user->canManageHrQueue();

        return HrNotification::where('tenant_id', $tenantId)
            ->where(function ($q) use ($user, $canQueue) {
                $q->where('recipient_user_id', $user->id);
                if ($canQueue) {
                    $q->orWhereNull('recipient_user_id'); // role-targeted queue items
                }
            });
    }

    public function feed(User $user, array $f, int $perPage): array
    {
        $q = $this->visibleTo($user)
            ->when(! empty($f['module']) && $f['module'] !== 'All', fn ($x) => $x->where('module', $f['module']))
            ->when(! empty($f['priority']) && $f['priority'] !== 'All', fn ($x) => $x->where('priority', $f['priority']))
            ->when(isset($f['is_read']) && $f['is_read'] !== '' && $f['is_read'] !== 'All', fn ($x) => $x->where('is_read', $f['is_read'] === 'Read' || $f['is_read'] === '1' || $f['is_read'] === true))
            ->when(! empty($f['notification_type']) && $f['notification_type'] !== 'All', fn ($x) => $x->where('notification_type', $f['notification_type']))
            ->when(! empty($f['from']), fn ($x) => $x->whereDate('created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($x) => $x->whereDate('created_at', '<=', $f['to']))
            ->when(! empty($f['search']), fn ($x) => $x->where(function ($w) use ($f) {
                $w->where('title', 'like', '%'.$f['search'].'%')->orWhere('message', 'like', '%'.$f['search'].'%');
            }))
            ->orderByDesc('id');

        $page = $q->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn ($n) => $this->present($n))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ];
    }

    public function unreadCount(User $user): int
    {
        return (int) $this->visibleTo($user)->where('is_read', false)->count();
    }

    public function dropdown(User $user, int $limit = 10): array
    {
        return $this->visibleTo($user)->orderByDesc('id')->limit($limit)->get()->map(fn ($n) => $this->present($n))->all();
    }

    public function stats(User $user): array
    {
        $base = fn () => $this->visibleTo($user);
        $today = Carbon::today()->toDateString();

        return [
            'unread'   => (int) $base()->where('is_read', false)->count(),
            'read'     => (int) $base()->where('is_read', true)->count(),
            'critical' => (int) $base()->where('priority', 'Critical')->where('is_read', false)->count(),
            'overdue'  => (int) $base()->where('notification_type', 'escalation')->where('is_read', false)->count(),
            'today'    => (int) $base()->whereDate('created_at', $today)->count(),
        ];
    }

    public function findVisible(int $id, User $user): ?HrNotification
    {
        return $this->visibleTo($user)->with('auditLogs')->find($id);
    }

    /**
     * Read-only notification view for one employee (Employee Profile). Scoped to the
     * employee's linked user account within the tenant. Returns latest, pending
     * (unread), and reminder/escalation history.
     */
    public function forEmployee(int $tenantId, ?int $userId): array
    {
        if (! $userId) {
            return ['latest' => [], 'pending' => [], 'reminders' => []];
        }
        $base = fn () => HrNotification::where('tenant_id', $tenantId)->where('recipient_user_id', $userId);

        return [
            'latest' => $base()->orderByDesc('id')->limit(5)->get()->map(fn ($n) => $this->present($n))->all(),
            'pending' => $base()->where('is_read', false)->orderByDesc('id')->limit(5)->get()->map(fn ($n) => $this->present($n))->all(),
            'reminders' => $base()->whereIn('notification_type', ['reminder', 'escalation'])->orderByDesc('id')->limit(10)->get()->map(fn ($n) => $this->present($n))->all(),
        ];
    }

    public function present(HrNotification $n): array
    {
        return [
            'id' => $n->id, 'module' => $n->module, 'event' => $n->event,
            'entity_type' => $n->entity_type, 'entity_id' => $n->entity_id,
            'priority' => $n->priority, 'notification_type' => $n->notification_type,
            'title' => $n->title, 'message' => $n->message,
            'recipient_user_id' => $n->recipient_user_id, 'recipient_role' => $n->recipient_role,
            'action_url' => $n->action_url, 'action_label' => $n->action_label,
            'is_read' => $n->is_read, 'read_at' => optional($n->read_at)->toIso8601String(),
            'created_at' => optional($n->created_at)->toIso8601String(),
            'timeline' => $n->relationLoaded('auditLogs')
                ? $n->auditLogs->sortBy('id')->values()->map(fn ($l) => ['action' => $l->action, 'actor_name' => $l->actor_name, 'created_at' => optional($l->created_at)->toIso8601String()])->all()
                : null,
        ];
    }
}
