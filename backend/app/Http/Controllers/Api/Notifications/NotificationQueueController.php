<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notifications\HrNotificationQueueItem;
use App\Services\Notifications\NotificationQueueService;
use Illuminate\Http\Request;

/**
 * Central Notification Engine — email/delivery queue monitor. Reads open to HR
 * users; retry/process require HR-queue management. Tenant-scoped, audited.
 */
class NotificationQueueController extends Controller
{
    public function __construct(private NotificationQueueService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->query($request, $request->only(['status', 'channel'])));
    }

    public function failed(Request $request)
    {
        return response()->json($this->query($request, ['status' => HrNotificationQueueItem::FAILED]));
    }

    public function stats(Request $request)
    {
        $base = fn () => HrNotificationQueueItem::where('tenant_id', $this->tenant($request));

        return response()->json([
            'pending' => (int) $base()->where('status', HrNotificationQueueItem::PENDING)->count(),
            'processing' => (int) $base()->where('status', HrNotificationQueueItem::PROCESSING)->count(),
            'sent' => (int) $base()->where('status', HrNotificationQueueItem::SENT)->count(),
            'failed' => (int) $base()->where('status', HrNotificationQueueItem::FAILED)->count(),
        ]);
    }

    public function retry(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->retry($id, $this->tenant($request), $request->user()));
    }

    /** Manually drain the pending queue for this tenant. */
    public function process(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->process($this->tenant($request), 500, $request->user()));
    }

    private function query(Request $request, array $f): array
    {
        $perPage = min(100, max(5, (int) $request->input('per_page', 25)));
        $page = HrNotificationQueueItem::with('notification')
            ->where('tenant_id', $this->tenant($request))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['channel']) && $f['channel'] !== 'All', fn ($q) => $q->where('channel', $f['channel']))
            ->when(! empty($f['module']) && $f['module'] !== 'All', fn ($q) => $q->whereHas('notification', fn ($n) => $n->where('module', $f['module'])))
            ->orderByDesc('id')->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn ($i) => [
                'id' => $i->id, 'channel' => $i->channel, 'status' => $i->status,
                'retry_count' => $i->retry_count, 'error_message' => $i->error_message,
                'sent_at' => optional($i->sent_at)->toIso8601String(),
                'created_at' => optional($i->created_at)->toIso8601String(),
                'notification' => $i->notification ? [
                    'id' => $i->notification->id, 'module' => $i->notification->module,
                    'event' => $i->notification->event, 'title' => $i->notification->title,
                    'priority' => $i->notification->priority,
                ] : null,
            ])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ];
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage the notification queue');
    }
}
