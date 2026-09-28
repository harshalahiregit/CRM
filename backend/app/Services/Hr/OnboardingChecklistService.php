<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrOnboardingChecklistItem as Item;
use App\Models\User;
use App\Support\Hr\OnboardingTaskCategory as TaskCat;
use Illuminate\Support\Facades\DB;

/**
 * The onboarding checklist a workspace actually uses, and the editing of it.
 *
 * Two jobs, deliberately in one place: resolving the list the onboarding
 * runtime seeds from, and the CRUD an administrator drives. They belong
 * together because the resolver is the only reader that matters — if the two
 * disagreed, the Settings screen would be editing a list nothing consults,
 * which is exactly what the hardcoded constant amounted to.
 *
 * THE FALLBACK RULE, stated once. A tenant with NO rows at all has never
 * configured a checklist, and gets the 27 defaults so a new workspace onboards
 * somebody properly on day one. A tenant WITH rows gets exactly its active
 * rows, even when that is none — deactivating everything is a decision, and
 * quietly restoring the defaults over it would be the system overruling an
 * administrator. The distinction is "has any rows", not "has active rows", and
 * it is asserted in both directions.
 */
class OnboardingChecklistService
{
    /**
     * The checklist to seed a new onboarding from.
     *
     * Returns plain rows in the shape seedTasks() already wrote, so the runtime
     * changed shape not at all — only where the rows come from.
     *
     * @return array<int, array{category:string, title:string, owner_role:string, is_mandatory:bool}>
     */
    public function applicableFor(int $tenantId): array
    {
        if (! $this->isConfigured($tenantId)) {
            return $this->defaults();
        }

        return Item::where('tenant_id', $tenantId)
            ->active()
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (Item $i) => [
                'category'     => $i->category,
                'title'        => $i->title,
                'owner_role'   => $i->owner_role,
                'is_mandatory' => (bool) $i->is_mandatory,
            ])->all();
    }

    /** Whether this workspace has a checklist of its own, active or not. */
    public function isConfigured(int $tenantId): bool
    {
        return Item::where('tenant_id', $tenantId)->exists();
    }

    /** The hardcoded 27, flattened — the fallback and nothing more. */
    public function defaults(): array
    {
        $out = [];

        foreach (TaskCat::DEFAULT_TASKS as $category => $tasks) {
            foreach ($tasks as $task) {
                $out[] = [
                    'category'     => $category,
                    'title'        => $task['title'],
                    'owner_role'   => $task['owner_role'],
                    'is_mandatory' => (bool) $task['is_mandatory'],
                ];
            }
        }

        return $out;
    }

    /* ── administration ───────────────────────────────────────────────── */

    /** Everything, in order — inactive rows included, so they can be brought back. */
    public function list(int $tenantId): array
    {
        return [
            'configured' => $this->isConfigured($tenantId),
            'items'      => Item::where('tenant_id', $tenantId)
                ->orderBy('sort_order')->orderBy('id')
                ->get()->map(fn (Item $i) => $this->present($i))->all(),
            'options'    => [
                'categories'  => array_map(
                    fn ($c) => ['value' => $c, 'label' => TaskCat::LABELS[$c] ?? $c],
                    TaskCat::ALL
                ),
                'owner_roles' => Item::OWNER_ROLES,
            ],
        ];
    }

    public function create(int $tenantId, array $data, ?User $actor = null): array
    {
        $item = Item::create($this->attributes($data, $tenantId) + [
            'tenant_id'  => $tenantId,
            // Appended, not inserted at the top: a new task joins the end of
            // the list until somebody moves it deliberately.
            'sort_order' => (int) Item::where('tenant_id', $tenantId)->max('sort_order') + 1,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $item->recordAudit('Checklist Item Added', $actor, $item->title);

        return $this->present($item);
    }

    public function update(int $tenantId, int $id, array $data, ?User $actor = null): array
    {
        $item = $this->find($tenantId, $id);

        $item->update($this->attributes($data, $tenantId, partial: true) + ['updated_by' => $actor?->id]);
        $item->recordAudit('Checklist Item Updated', $actor, $item->title);

        return $this->present($item->fresh());
    }

    public function setActive(int $tenantId, int $id, bool $active, ?User $actor = null): array
    {
        $item = $this->find($tenantId, $id);

        $item->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $item->recordAudit($active ? 'Checklist Item Enabled' : 'Checklist Item Disabled', $actor, $item->title);

        return $this->present($item->fresh());
    }

    /**
     * Delete an item.
     *
     * Safe by construction, and worth saying why rather than adding a guard
     * that protects nothing. A seeded task carries its own copy of the title
     * and category and holds no reference back to this row, so removing a
     * template item cannot orphan, alter or hide anything on an onboarding
     * that has already started — finished or in progress. Disabling is offered
     * for the other case, where somebody wants the record of a retired step.
     */
    public function delete(int $tenantId, int $id, ?User $actor = null): void
    {
        $item = $this->find($tenantId, $id);

        $item->recordAudit('Checklist Item Removed', $actor, $item->title);
        $item->delete();
    }

    /**
     * Reorder wholesale.
     *
     * The payload IS the order. Ids from another workspace are refused rather
     * than skipped: a reorder that silently ignored half its input would leave
     * the screen showing an order the server never stored.
     */
    public function reorder(int $tenantId, array $ids, ?User $actor = null): array
    {
        $owned = Item::where('tenant_id', $tenantId)->pluck('id')->map(fn ($i) => (int) $i)->all();

        foreach ($ids as $id) {
            if (! in_array((int) $id, $owned, true)) {
                throw new BusinessException('That checklist item does not belong to this workspace.', 422);
            }
        }

        DB::transaction(function () use ($tenantId, $ids, $actor) {
            $order = 0;
            foreach ($ids as $id) {
                Item::where('tenant_id', $tenantId)->whereKey((int) $id)
                    ->update(['sort_order' => $order++, 'updated_by' => $actor?->id]);
            }
        });

        return $this->list($tenantId);
    }

    /**
     * Adopt the defaults as editable rows.
     *
     * For a workspace running on the fallback: it turns the 27 implicit tasks
     * into 27 rows somebody can actually change. Refused once a workspace has
     * its own list, so it can never overwrite one.
     */
    public function adoptDefaults(int $tenantId, ?User $actor = null): array
    {
        if ($this->isConfigured($tenantId)) {
            throw new BusinessException('This workspace already has its own checklist.', 422);
        }

        $sort = 0;
        foreach ($this->defaults() as $row) {
            Item::create($row + [
                'tenant_id'  => $tenantId,
                'sort_order' => $sort++,
                'is_active'  => true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
        }

        return $this->list($tenantId);
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function find(int $tenantId, int $id): Item
    {
        $item = Item::where('tenant_id', $tenantId)->find($id);

        if (! $item) {
            throw new BusinessException('Checklist item not found.', 404);
        }

        return $item;
    }

    /** Validate and narrow to the columns this template actually has. */
    private function attributes(array $data, int $tenantId, bool $partial = false): array
    {
        $out = [];

        if (! $partial || array_key_exists('title', $data)) {
            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                throw new BusinessException('A checklist task needs a title.', 422);
            }
            $out['title'] = mb_substr($title, 0, 200);
        }

        if (! $partial || array_key_exists('category', $data)) {
            $category = $data['category'] ?? TaskCat::GENERAL;
            if (! in_array($category, TaskCat::ALL, true)) {
                throw new BusinessException('That is not a checklist category.', 422);
            }
            $out['category'] = $category;
        }

        if (! $partial || array_key_exists('owner_role', $data)) {
            $role = $data['owner_role'] ?? 'HR';
            if (! in_array($role, Item::OWNER_ROLES, true)) {
                throw new BusinessException('That is not an owner role.', 422);
            }
            $out['owner_role'] = $role;
        }

        if (! $partial || array_key_exists('is_mandatory', $data)) {
            $out['is_mandatory'] = filter_var($data['is_mandatory'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        return $out;
    }

    private function present(Item $i): array
    {
        return [
            'id'             => $i->id,
            'category'       => $i->category,
            'category_label' => TaskCat::LABELS[$i->category] ?? $i->category,
            'title'          => $i->title,
            'owner_role'     => $i->owner_role,
            'is_mandatory'   => (bool) $i->is_mandatory,
            'is_active'      => (bool) $i->is_active,
            'sort_order'     => (int) $i->sort_order,
        ];
    }
}
