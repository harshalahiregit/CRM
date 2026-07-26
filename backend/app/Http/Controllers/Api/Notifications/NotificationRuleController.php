<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notifications\HrNotificationRule;
use App\Services\Notifications\NotificationRuleService;
use Illuminate\Http\Request;

/**
 * Central Notification Engine — reminder/escalation rule administration. Reads open
 * to HR users; writes require HR-queue management. Tenant-scoped, audited.
 */
class NotificationRuleController extends Controller
{
    public function __construct(private NotificationRuleService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['module'])));
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'module' => 'required|string', 'event' => 'required|string',
            'reminder_days' => 'array', 'reminder_days.*' => 'integer',
            'repeat_daily' => 'boolean',
            'escalation_days' => 'array', 'escalation_days.*.days' => 'required_with:escalation_days|integer',
            'escalation_days.*.role' => 'required_with:escalation_days|string',
            'priority' => 'string|in:Info,Success,Warning,Critical', 'enabled' => 'boolean',
        ]);

        return response()->json($this->service->present($this->service->create($this->tenant($request), $data, $request->user())), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $r = $this->find($id, $request);
        $data = $request->validate([
            'reminder_days' => 'array', 'reminder_days.*' => 'integer',
            'repeat_daily' => 'boolean',
            'escalation_days' => 'nullable|array', 'escalation_days.*.days' => 'required_with:escalation_days|integer',
            'escalation_days.*.role' => 'required_with:escalation_days|string',
            'priority' => 'string|in:Info,Success,Warning,Critical', 'enabled' => 'boolean',
        ]);

        return response()->json($this->service->present($this->service->update($r, $data, $request->user())));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $r = $this->find($id, $request);
        $data = $request->validate(['enabled' => 'required|boolean']);

        return response()->json($this->service->present($this->service->setStatus($r, (bool) $data['enabled'], $request->user())));
    }

    private function find(int $id, Request $request): HrNotificationRule
    {
        $r = HrNotificationRule::where('tenant_id', $this->tenant($request))->find($id);
        abort_if(! $r, 404, 'Reminder rule not found');

        return $r;
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage reminder rules');
    }
}
