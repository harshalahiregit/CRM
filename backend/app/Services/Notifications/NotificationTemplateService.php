<?php

namespace App\Services\Notifications;

use App\Exceptions\BusinessException;
use App\Models\Notifications\HrNotificationTemplate;
use App\Models\User;

/** CRUD for notification templates. Tenant-scoped, audited, additive. */
class NotificationTemplateService
{
    public function __construct(private ModuleEventCatalog $catalog)
    {
    }

    public function list(int $tenantId, array $f = []): array
    {
        return HrNotificationTemplate::where('tenant_id', $tenantId)
            ->when(! empty($f['module']) && $f['module'] !== 'All', fn ($q) => $q->where('module', $f['module']))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('event', 'like', '%'.$f['search'].'%')->orWhere('subject', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('module')->orderBy('event')->get()->map(fn ($t) => $this->present($t))->all();
    }

    public function create(int $tenantId, array $data, User $actor): HrNotificationTemplate
    {
        if (! $this->catalog->exists($data['module'], $data['event'])) {
            throw new BusinessException('Unknown module/event. Register it in the module catalog first.');
        }
        if (HrNotificationTemplate::where('tenant_id', $tenantId)->where('module', $data['module'])->where('event', $data['event'])->exists()) {
            throw new BusinessException('A template already exists for this module and event.');
        }
        $t = HrNotificationTemplate::create([
            'tenant_id' => $tenantId, 'module' => $data['module'], 'event' => $data['event'],
            'subject' => $data['subject'], 'body' => $data['body'],
            'email_enabled' => (bool) ($data['email_enabled'] ?? true),
            'in_app_enabled' => (bool) ($data['in_app_enabled'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $t->recordAudit('Template Updated', $actor, 'Created');

        return $t;
    }

    public function update(HrNotificationTemplate $t, array $data, User $actor): HrNotificationTemplate
    {
        $t->fill(array_filter([
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
        ], fn ($v) => $v !== null));
        foreach (['email_enabled', 'in_app_enabled', 'sms_enabled', 'whatsapp_enabled', 'teams_enabled', 'slack_enabled', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $t->{$flag} = (bool) $data[$flag];
            }
        }
        $t->save();
        $t->recordAudit('Template Updated', $actor);

        return $t;
    }

    public function setStatus(HrNotificationTemplate $t, bool $active, User $actor): HrNotificationTemplate
    {
        $t->update(['is_active' => $active]);
        $t->recordAudit('Template Updated', $actor, $active ? 'Activated' : 'Deactivated');

        return $t;
    }

    public function present(HrNotificationTemplate $t): array
    {
        return [
            'id' => $t->id, 'module' => $t->module, 'event' => $t->event,
            'subject' => $t->subject, 'body' => $t->body,
            'email_enabled' => (bool) $t->email_enabled, 'in_app_enabled' => (bool) $t->in_app_enabled,
            'sms_enabled' => (bool) $t->sms_enabled, 'whatsapp_enabled' => (bool) $t->whatsapp_enabled,
            'teams_enabled' => (bool) $t->teams_enabled, 'slack_enabled' => (bool) $t->slack_enabled,
            'is_active' => (bool) $t->is_active,
            'updated_at' => optional($t->updated_at)->toIso8601String(),
        ];
    }
}
