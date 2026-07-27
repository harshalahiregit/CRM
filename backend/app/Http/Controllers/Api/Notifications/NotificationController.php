<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Repositories\Notifications\NotificationRepository;
use App\Services\Notifications\NotificationQueueService;
use Illuminate\Http\Request;

/**
 * Central Notification Engine — recipient-facing API (navbar bell, Notification
 * Center, history). Every read is scoped to notifications the caller may see (their
 * own, plus the HR queue when they manage it). Sanctum-authenticated, tenant-scoped.
 */
class NotificationController extends Controller
{
    public function __construct(
        private NotificationRepository $repo,
        private NotificationQueueService $queue,
    ) {
    }

    /** Notification Center feed (filters + pagination). */
    public function index(Request $request)
    {
        $f = $request->only(['module', 'priority', 'is_read', 'notification_type', 'from', 'to', 'search']);
        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));

        return response()->json($this->repo->feed($request->user(), $f, $perPage));
    }

    /** Navbar bell: unread count + newest items. */
    public function bell(Request $request)
    {
        return response()->json([
            'unread' => $this->repo->unreadCount($request->user()),
            'items' => $this->repo->dropdown($request->user(), (int) $request->input('limit', 10)),
        ]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['unread' => $this->repo->unreadCount($request->user())]);
    }

    /** Notification Center KPI cards. */
    public function stats(Request $request)
    {
        return response()->json($this->repo->stats($request->user()));
    }

    public function show(Request $request, int $id)
    {
        $n = $this->repo->findVisible($id, $request->user());
        abort_if(! $n, 404, 'Notification not found');

        return response()->json($this->repo->present($n));
    }

    public function markRead(Request $request, int $id)
    {
        $n = $this->repo->findVisible($id, $request->user());
        abort_if(! $n, 404, 'Notification not found');
        if (! $n->is_read) {
            $n->update(['is_read' => true, 'read_at' => now()]);
            $n->recordAudit('Notification Read', $request->user());
        }

        return response()->json($this->repo->present($n->fresh('auditLogs')));
    }

    public function markAllRead(Request $request)
    {
        $count = $this->repo->visibleTo($request->user())->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['marked' => (int) $count]);
    }

    /** Read-only notifications for one employee (Employee Profile section). */
    public function forEmployee(Request $request, int $employeeId)
    {
        $tenantId = (int) $request->user()->tenant_id;
        $employee = \App\Models\Hr\HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        abort_if(! $employee, 404, 'Employee not found');

        return response()->json($this->repo->forEmployee($tenantId, $employee->user_id));
    }

    /** Resend the email for a notification. */
    public function resend(Request $request, int $id)
    {
        $n = $this->repo->findVisible($id, $request->user());
        abort_if(! $n, 404, 'Notification not found');
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to resend notifications');

        return response()->json($this->queue->resend($n, $request->user()));
    }
}
