<?php

namespace App\Services\Sales;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\Reminder;
use Illuminate\Support\Facades\DB;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Log;

class ReminderService
{
    /**
     * Resolve a short subject key (lead|invoice|…) → FQCN, reusing the
     * activity allowlist so both features accept the same keys.
     */
    private function resolveType(?string $key): ?string
    {
        if ($key === null) return null;
        return SalesActivityService::SUBJECT_MAP[$key]
            ?? throw new \App\Exceptions\BusinessException("Unknown reminder subject type: {$key}", 422);
    }

    public function list(int $tenantId, array $filters)
    {
        $query = Reminder::forTenant($tenantId)->with(['assignee:id,name', 'creator:id,name']);

        if (!empty($filters['subject_type']) && !empty($filters['subject_id'])) {
            $query->forSubject($this->resolveType($filters['subject_type']), (int) $filters['subject_id']);
        }
        if (!empty($filters['staff_id'])) {
            $query->where('staff_id', $filters['staff_id']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['pending'])) {
            $query->pending();
        }
        if (!empty($filters['from'])) {
            $query->where('due_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('due_at', '<=', $filters['to']);
        }

        return $query->orderBy('due_at')->get();
    }

    public function upcoming(int $tenantId, int $userId)
    {
        return Reminder::forTenant($tenantId)
            ->pending()
            ->where('staff_id', $userId)
            ->with(['assignee:id,name'])
            ->orderBy('due_at')
            ->limit(50)
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): Reminder
    {
        $data['remindable_type'] = $this->resolveType($data['remindable_type']);
        // Rich text (notepad editor) — sanitized before it ever reaches the DB.
        $data = HtmlSanitizer::cleanFields($data, ['notes']);

        $reminder = Reminder::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'created_by' => $userId,
        ]);

        Log::channel('sales')->info('Reminder created', ['reminder_id' => $reminder->id, 'tenant_id' => $tenantId]);

        return $reminder->load(['assignee:id,name', 'creator:id,name']);
    }

    /**
     * The same reminder engine, addressed by MODEL CLASS instead of a short key.
     *
     * The key path above resolves through SalesActivityService::SUBJECT_MAP, which
     * is also what /api/sales/reminders validates against. That route carries no
     * role gate, so adding a subject to the map would expose it to every
     * authenticated login — including a vendor portal one. Modules outside Sales
     * therefore attach here, behind their own gate, and the shared allowlist stays
     * exactly as wide as it is today.
     *
     * Everything after the subject is identical: same table, same sanitising, same
     * complete/update/delete methods, one reminder engine.
     */
    public function createForSubject(string $subjectClass, int $subjectId, array $data, int $tenantId, int $userId): Reminder
    {
        unset($data['remindable_type'], $data['remindable_id']);
        $data = HtmlSanitizer::cleanFields($data, ['notes']);

        $reminder = Reminder::create([
            ...$data,
            'remindable_type' => $subjectClass,
            'remindable_id'   => $subjectId,
            'tenant_id'       => $tenantId,
            'created_by'      => $userId,
        ]);

        Log::channel('sales')->info('Reminder created', [
            'reminder_id' => $reminder->id, 'tenant_id' => $tenantId, 'subject' => $subjectClass,
        ]);

        return $reminder->load(['assignee:id,name', 'creator:id,name']);
    }

    /** Pending-first listing for one subject, addressed by model class. */
    public function listForSubject(string $subjectClass, int $subjectId, int $tenantId)
    {
        return Reminder::forTenant($tenantId)
            ->forSubject($subjectClass, $subjectId)
            ->with(['assignee:id,name', 'creator:id,name'])
            ->orderByRaw('completed_at IS NOT NULL')   // open ones first
            ->orderBy('due_at')
            ->get();
    }

    public function update(Reminder $reminder, array $data, int $tenantId): Reminder
    {
        $this->assertTenant($reminder, $tenantId);

        if (array_key_exists('remindable_type', $data)) {
            $data['remindable_type'] = $this->resolveType($data['remindable_type']);
        }

        $data = HtmlSanitizer::cleanFields($data, ['notes']);

        $reminder->update($data);
        return $reminder->fresh()->load(['assignee:id,name', 'creator:id,name']);
    }

    public function delete(Reminder $reminder, int $tenantId): void
    {
        $this->assertTenant($reminder, $tenantId);
        $reminder->delete();
    }

    /**
     * Mark a reminder done. If a next_follow_up datetime is supplied, spawn a
     * fresh reminder for that date (manual chaining — no cron involved).
     */
    public function complete(Reminder $reminder, array $data, int $tenantId, int $userId): Reminder
    {
        $this->assertTenant($reminder, $tenantId);

        return DB::transaction(function () use ($reminder, $data, $tenantId, $userId) {
            $reminder->update([
                'outcome'      => $data['outcome'] ?? $reminder->outcome,
                'completed_at' => now(),
                'is_notified'  => true,
                'notified_at'  => $reminder->notified_at ?? now(),
            ]);

            if (!empty($data['next_follow_up'])) {
                Reminder::create([
                    'tenant_id'       => $tenantId,
                    'remindable_type' => $reminder->remindable_type,
                    'remindable_id'   => $reminder->remindable_id,
                    'type'            => $reminder->type,
                    'title'           => 'Follow-up: ' . $reminder->title,
                    'due_at'          => $data['next_follow_up'],
                    'priority'        => $reminder->priority,
                    'staff_id'        => $reminder->staff_id,
                    'created_by'      => $userId,
                ]);
            }

            Log::channel('sales')->info('Reminder completed', ['reminder_id' => $reminder->id, 'tenant_id' => $tenantId]);

            return $reminder->fresh()->load(['assignee:id,name', 'creator:id,name']);
        });
    }

    private function assertTenant(Reminder $reminder, int $tenantId): void
    {
        if ($reminder->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
