<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project\ProjectStatus;
use App\Models\Task\TaskStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The Advanced Status Manager. Owns the tenant's task/project status lists so
 * statuses stop being hardcoded strings duplicated across migrations, three
 * FormRequests, two services and the frontend.
 *
 * Shared rather than per-module because both modules need identical behaviour and
 * the config screen manages both lists side by side.
 */
class StatusService
{
    public const TYPES = ['task', 'project'];

    /** Falls back to these when a tenant predates the lookup tables. */
    private const FALLBACK = [
        'task'    => ['not_started', 'in_progress', 'testing', 'awaiting_feedback', 'complete'],
        'project' => ['not_started', 'in_progress', 'on_hold', 'cancelled', 'finished'],
    ];

    public function list(string $type, int $tenantId): Collection
    {
        return $this->model($type)::forTenant($tenantId)->orderBy('order')->orderBy('id')->get();
    }

    /** Valid keys for validation — the dynamic replacement for `in:a,b,c`. */
    public function keys(string $type, int $tenantId): array
    {
        $keys = $this->list($type, $tenantId)->pluck('key')->all();

        // A tenant created before this migration would otherwise validate against
        // an empty list and reject every status.
        return $keys ?: self::FALLBACK[$type];
    }

    /** key => label, for notification copy and CSV export. */
    public function labels(string $type, int $tenantId): array
    {
        $rows = $this->list($type, $tenantId);
        if ($rows->isEmpty()) {
            return array_combine(self::FALLBACK[$type], array_map(
                fn ($k) => Str::headline($k), self::FALLBACK[$type],
            ));
        }

        return $rows->pluck('name', 'key')->all();
    }

    /** The key that closes a record ('complete' / 'finished'). */
    public function closedKey(string $type, int $tenantId): ?string
    {
        return $this->list($type, $tenantId)->firstWhere('is_closed_status', true)?->key
            ?? ($type === 'task' ? 'complete' : 'finished');
    }

    /**
     * Enforce the configured workflow. A null/empty can_be_changed_to means "no
     * restriction" — which is what every seeded status uses, so this is a no-op
     * until someone actually configures transitions.
     */
    public function assertTransition(string $type, ?string $from, string $to, int $tenantId): void
    {
        if (! $from || $from === $to) {
            return;
        }

        $row = $this->list($type, $tenantId)->firstWhere('key', $from);
        $allowed = $row?->can_be_changed_to;
        if (! $row || ! $allowed) {
            return;
        }

        if (! in_array($to, $allowed, true)) {
            $labels = $this->labels($type, $tenantId);
            throw new BusinessException(
                sprintf('“%s” can’t move straight to “%s”.', $labels[$from] ?? $from, $labels[$to] ?? $to),
                422,
            );
        }
    }

    /* ── Management ─────────────────────────────────────────────── */

    public function create(string $type, array $data, int $tenantId)
    {
        $model = $this->model($type);
        $key = $this->uniqueKey($type, $data['name'], $tenantId);

        return $model::create([
            'tenant_id'         => $tenantId,
            'key'               => $key,
            'name'              => trim($data['name']),
            'color'             => $data['color'] ?? '#94a3b8',
            'order'             => $data['order'] ?? ((int) $model::forTenant($tenantId)->max('order') + 1),
            'is_default_filter' => $data['is_default_filter'] ?? false,
            'is_closed_status'  => false,   // only the seeded closing status may close
            'is_system'         => false,
            'can_be_changed_to' => $data['can_be_changed_to'] ?? null,
            'hidden_for'        => $data['hidden_for'] ?? null,
        ]);
    }

    public function update(string $type, int $id, array $data, int $tenantId)
    {
        $row = $this->find($type, $id, $tenantId);

        // key is deliberately immutable — records store it, so changing it would
        // orphan every task/project holding the old value.
        $row->update(array_intersect_key($data, array_flip([
            'name', 'color', 'order', 'is_default_filter', 'can_be_changed_to', 'hidden_for',
        ])));

        return $row->fresh();
    }

    public function delete(string $type, int $id, int $tenantId): void
    {
        $row = $this->find($type, $id, $tenantId);

        if ($row->is_system) {
            throw new BusinessException('This is a built-in status and can’t be deleted. You can rename or recolour it.', 422);
        }

        // Refuse rather than silently orphan: records hold the key as a string,
        // so deleting a status in use would leave rows with a status nothing maps.
        $inUse = $this->countUsing($type, $row->key, $tenantId);
        if ($inUse > 0) {
            $noun = $type === 'task' ? 'task' : 'project';
            throw new BusinessException(
                "{$inUse} {$noun}".($inUse === 1 ? '' : 's')." still use this status. Move them first.",
                422,
            );
        }

        $row->delete();
    }

    /** Persist a drag-reordered list. */
    public function reorder(string $type, array $orderedIds, int $tenantId): int
    {
        $model = $this->model($type);
        $n = 0;
        foreach ($orderedIds as $i => $id) {
            $n += $model::forTenant($tenantId)->whereKey($id)->update(['order' => $i + 1]);
        }

        return $n;
    }

    public function countUsing(string $type, string $key, int $tenantId): int
    {
        return $type === 'task'
            ? \App\Models\Task\Task::forTenant($tenantId)->where('status', $key)->count()
            : \App\Models\Project\Project::forTenant($tenantId)->where('status', $key)->count();
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function find(string $type, int $id, int $tenantId)
    {
        $row = $this->model($type)::forTenant($tenantId)->find($id);
        if (! $row) {
            throw new BusinessException('Status not found.', 404);
        }

        return $row;
    }

    private function uniqueKey(string $type, string $name, int $tenantId): string
    {
        $base = Str::slug($name, '_') ?: 'status';
        $key = $base;
        $i = 2;
        while ($this->model($type)::forTenant($tenantId)->where('key', $key)->exists()) {
            $key = "{$base}_{$i}";
            $i++;
        }

        return $key;
    }

    private function model(string $type): string
    {
        return match ($type) {
            'task'    => TaskStatus::class,
            'project' => ProjectStatus::class,
            default   => throw new BusinessException("Unknown status type: {$type}", 422),
        };
    }
}
