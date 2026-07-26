<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Notifications\HrNotificationTemplate;
use App\Services\Notifications\ModuleEventCatalog;
use App\Services\Notifications\NotificationSeederService;
use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Http\Request;

/**
 * Central Notification Engine — template administration. Reads open to HR users;
 * writes require HR-queue management. Tenant-scoped, audited.
 */
class NotificationTemplateController extends Controller
{
    public function __construct(
        private NotificationTemplateService $service,
        private ModuleEventCatalog $catalog,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($this->tenant($request), $request->only(['module', 'search'])));
    }

    /** Module → events catalog (for the template/rule builders). */
    public function catalog()
    {
        return response()->json([
            'modules' => $this->catalog->modules(),
            'events' => $this->catalog->all(),
            'priorities' => \App\Models\Notifications\HrNotification::PRIORITIES,
            'escalation_ladder' => $this->catalog->escalationLadder(),
        ]);
    }

    public function store(Request $request)
    {
        $this->can($request);
        $data = $request->validate([
            'module' => 'required|string', 'event' => 'required|string',
            'subject' => 'required|string|max:255', 'body' => 'required|string',
            'email_enabled' => 'boolean', 'in_app_enabled' => 'boolean', 'is_active' => 'boolean',
        ]);

        return response()->json($this->service->present($this->service->create($this->tenant($request), $data, $request->user())), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $t = $this->find($id, $request);
        $data = $request->validate([
            'subject' => 'sometimes|string|max:255', 'body' => 'sometimes|string',
            'email_enabled' => 'boolean', 'in_app_enabled' => 'boolean',
            'sms_enabled' => 'boolean', 'whatsapp_enabled' => 'boolean',
            'teams_enabled' => 'boolean', 'slack_enabled' => 'boolean', 'is_active' => 'boolean',
        ]);

        return response()->json($this->service->present($this->service->update($t, $data, $request->user())));
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->can($request);
        $t = $this->find($id, $request);
        $data = $request->validate(['is_active' => 'required|boolean']);

        return response()->json($this->service->present($this->service->setStatus($t, (bool) $data['is_active'], $request->user())));
    }

    /** Seed missing default templates + reminder rules from the module catalog. */
    public function seedDefaults(Request $request, NotificationSeederService $seeder)
    {
        $this->can($request);

        return response()->json($seeder->seed($this->tenant($request)));
    }

    private function find(int $id, Request $request): HrNotificationTemplate
    {
        $t = HrNotificationTemplate::where('tenant_id', $this->tenant($request))->find($id);
        abort_if(! $t, 404, 'Template not found');

        return $t;
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage notification templates');
    }
}
