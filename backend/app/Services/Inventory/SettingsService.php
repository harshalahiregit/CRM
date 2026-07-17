<?php

namespace App\Services\Inventory;

use App\Exceptions\BusinessException;
use App\Models\Inventory\Attribute;
use App\Models\Inventory\Group;
use App\Models\Inventory\Product;
use App\Models\Inventory\Subgroup;
use App\Models\Inventory\Tax;
use App\Models\Inventory\Type;
use App\Models\Inventory\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Settings master data (blueprint §10).
 *
 * All six lookups behave identically, so they're driven from one map instead of
 * six copy-pasted services. `guard` names the column that would be orphaned if a
 * row were deleted while still in use — deleting in-use master data is refused
 * rather than silently blanking a product's group or unit.
 */
class SettingsService
{
    /** kind => [model, product column that references it] */
    private const KINDS = [
        'units'      => [Unit::class, 'unit_id'],
        'types'      => [Type::class, 'type_id'],
        'groups'     => [Group::class, 'group_id'],
        'subgroups'  => [Subgroup::class, 'subgroup_id'],
        'taxes'      => [Tax::class, 'tax_id'],
        'attributes' => [Attribute::class, null],   // guarded per-kind below
    ];

    public function assertKind(string $kind): void
    {
        if (! isset(self::KINDS[$kind])) {
            throw new BusinessException('Unknown settings section.', 404);
        }
    }

    private function model(string $kind): string
    {
        $this->assertKind($kind);

        return self::KINDS[$kind][0];
    }

    /** Everything the Item form's dropdowns need, in one request. */
    public function all(int $tenantId): array
    {
        return [
            'units'      => Unit::forTenant($tenantId)->orderBy('order')->orderBy('name')->get(),
            'types'      => Type::forTenant($tenantId)->orderBy('order')->orderBy('name')->get(),
            'groups'     => Group::forTenant($tenantId)->with('subgroups')->orderBy('order')->orderBy('name')->get(),
            'subgroups'  => Subgroup::forTenant($tenantId)->orderBy('order')->orderBy('name')->get(),
            'taxes'      => Tax::forTenant($tenantId)->orderBy('order')->orderBy('name')->get(),
            'attributes' => Attribute::forTenant($tenantId)->orderBy('order')->orderBy('name')->get()
                ->groupBy('kind'),
        ];
    }

    public function list(string $kind, int $tenantId, ?string $attrKind = null)
    {
        $model = $this->model($kind);
        $q = $model::forTenant($tenantId);

        if ($kind === 'attributes' && $attrKind) {
            $q->where('kind', $attrKind);
        }
        if ($kind === 'groups') {
            $q->with('subgroups');
        }

        return $q->orderBy('order')->orderBy('name')->get();
    }

    /** Sub-groups for one group — powers the dependent dropdown. */
    public function subgroupsFor(int $groupId, int $tenantId)
    {
        return Subgroup::forTenant($tenantId)->where('group_id', $groupId)
            ->orderBy('order')->orderBy('name')->get();
    }

    public function create(string $kind, array $data, int $tenantId)
    {
        $model = $this->model($kind);
        $this->validateShape($kind, $data, $tenantId);

        return $model::create([...$data, 'tenant_id' => $tenantId]);
    }

    public function update(string $kind, int $id, array $data, int $tenantId)
    {
        $model = $this->model($kind);
        $row = $model::forTenant($tenantId)->findOrFail($id);
        $this->validateShape($kind, $data, $tenantId);

        // `kind` is what an attribute IS — changing it would silently move a
        // colour into the sizes list, so it's fixed after creation.
        unset($data['kind']);
        $row->fill($data)->save();

        return $row;
    }

    public function delete(string $kind, int $id, int $tenantId): void
    {
        $model = $this->model($kind);
        $row = $model::forTenant($tenantId)->findOrFail($id);

        $this->assertNotInUse($kind, $row, $tenantId);

        DB::transaction(function () use ($kind, $row, $tenantId) {
            // A group's sub-groups are meaningless without it.
            if ($kind === 'groups') {
                Subgroup::forTenant($tenantId)->where('group_id', $row->id)->delete();
            }
            $row->delete();
        });
    }

    public function reorder(string $kind, array $orderedIds, int $tenantId): int
    {
        $model = $this->model($kind);

        return DB::transaction(function () use ($model, $orderedIds, $tenantId) {
            $n = 0;
            foreach (array_values($orderedIds) as $i => $id) {
                $n += $model::forTenant($tenantId)->whereKey($id)->update(['order' => $i]);
            }

            return $n;
        });
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function validateShape(string $kind, array $data, int $tenantId): void
    {
        if ($kind === 'attributes' && isset($data['kind']) && ! in_array($data['kind'], Attribute::KINDS, true)) {
            throw new BusinessException('Unknown attribute kind.', 422);
        }
        if ($kind === 'subgroups' && ! empty($data['group_id'])
            && ! Group::forTenant($tenantId)->whereKey($data['group_id'])->exists()) {
            throw new BusinessException('That commodity group does not exist.', 422);
        }
    }

    /** Refuse to delete master data a product still points at. */
    private function assertNotInUse(string $kind, $row, int $tenantId): void
    {
        $column = self::KINDS[$kind][1];

        if ($kind === 'attributes') {
            $column = $row->kind.'_id';       // color_id | model_id | size_id | style_id
        }
        if (! $column) {
            return;
        }

        $count = Product::forTenant($tenantId)->where($column, $row->id)->count();
        if ($count > 0) {
            throw new BusinessException(
                "“{$row->name}” is used by {$count} ".($count === 1 ? 'product' : 'products').'. Change those first.',
                422
            );
        }

        if ($kind === 'groups' && Subgroup::forTenant($tenantId)->where('group_id', $row->id)->exists()) {
            // Sub-groups cascade, but only if none of them are in use.
            $subIds = Subgroup::forTenant($tenantId)->where('group_id', $row->id)->pluck('id');
            if (Product::forTenant($tenantId)->whereIn('subgroup_id', $subIds)->exists()) {
                throw new BusinessException('A sub-group of this group is still used by products.', 422);
            }
        }
    }
}
