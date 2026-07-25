<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * The header bell. Every route is implicitly scoped to the authenticated user —
 * a notification is only ever readable by its recipient.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private NotificationService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'items'        => $this->service->listFor($user->id, $user->tenant_id),
            'unread_count' => $this->service->unreadCount($user->id, $user->tenant_id),
        ], 'Notifications retrieved');
    }

    public function markRead(Request $request, int $id)
    {
        $user = $request->user();
        $this->service->markRead($id, $user->id, $user->tenant_id);

        return $this->success(null, 'Notification marked read');
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $count = $this->service->markAllRead($user->id, $user->tenant_id);

        return $this->success(['marked' => $count], 'All notifications marked read');
    }
}
