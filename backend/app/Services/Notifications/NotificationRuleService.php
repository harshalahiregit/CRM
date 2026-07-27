<?php

namespace App\Services\Notifications;

use App\Exceptions\BusinessException;
use App\Models\Notifications\HrNotificationRule;
use App\Models\User;

/** CRUD for reminder/escalation rules. Tenant-scoped, audited, additive. */
class NotificationRuleService
{
    public function __construct(private ModuleEventCatalog $catalog)
    {
    }

    public function list(int $tenantId, array $f = []): array
    {
        return HrNotificationRule::where('tenant_id', $tenantId)
            ->when(! empty($f['module']) && $f['module'] !== 'All', fn ($q) => $q->where('module', $f['module']))
            ->orderBy('module')->orderBy('event')->get()->map(fn ($r) => $this->present($r))->all();
    }

    public function create(int $tenantId, array $data, User $actor): HrNotificationRule
    {
        if (! $this->catalog->exists($data['module'], $data['event'])) {
            throw new BusinessException('Unknown module/event. Register it in the module catalog first.');
        }
        if (HrNotificationRule::where('tenant_id', $tenantId)->where('module', $data['module'])->where('event', $data['event'])->exists()) {
            throw new BusinessException('A reminder rule already exists for this module and event.');
        }
        $r = HrNotificationRule::create([
            'tenant_id' => $tenantId, 'module' => $data['module'], 'event' => $data['event'],
            'reminder_days' => $this->normalizeDays($data['reminder_days'] ?? [0]),
            'repeat_daily' => (bool) ($data['repeat_daily'] ?? false),
            'escalation_days' => $this->normalizeLadder($data['escalation_days'] ?? null),
            'priority' => $data['priority'] ?? 'Warning',
            'enabled' => (bool) ($data['enabled'] ?? true),
        ]);
        $r->recordAudit('Rule Updated', $actor, 'Created');

        return $r;
    }

    public function update(HrNotificationRule $r, array $data, User $actor): HrNotificationRule
    {
        if (array_key_exists('reminder_days', $data)) {
            $r->reminder_days = $this->normalizeDays($data['reminder_days']);
        }
        if (array_key_exists('repeat_daily', $data)) {
            $r->repeat_daily = (bool) $data['repeat_daily'];
        }
        if (array_key_exists('escalation_days', $data)) {
            $r->escalation_days = $this->normalizeLadder($data['escalation_days']);
        }
        if (array_key_exists('priority', $data)) {
            $r->priority = $data['priority'];
        }
        if (array_key_exists('enabled', $data)) {
            $r->enabled = (bool) $data['enabled'];
        }
        $r->save();
        $r->recordAudit('Rule Updated', $actor);

        return $r;
    }

    public function setStatus(HrNotificationRule $r, bool $enabled, User $actor): HrNotificationRule
    {
        $r->update(['enabled' => $enabled]);
        $r->recordAudit('Rule Updated', $actor, $enabled ? 'Enabled' : 'Disabled');

        return $r;
    }

    private function normalizeDays($days): array
    {
        return collect((array) $days)->map(fn ($d) => (int) $d)->unique()->sortDesc()->values()->all();
    }

    private function normalizeLadder($ladder): ?array
    {
        if (empty($ladder)) {
            return null;
        }
        $out = [];
        foreach ((array) $ladder as $step) {
            if (isset($step['days'], $step['role'])) {
                $out[] = ['days' => (int) $step['days'], 'role' => (string) $step['role']];
            }
        }

        return $out ?: null;
    }

    public function present(HrNotificationRule $r): array
    {
        return [
            'id' => $r->id, 'module' => $r->module, 'event' => $r->event,
            'reminder_days' => $r->reminder_days ?: [], 'repeat_daily' => (bool) $r->repeat_daily,
            'escalation_days' => $r->escalation_days ?: [], 'priority' => $r->priority,
            'enabled' => (bool) $r->enabled,
            'updated_at' => optional($r->updated_at)->toIso8601String(),
        ];
    }
}
