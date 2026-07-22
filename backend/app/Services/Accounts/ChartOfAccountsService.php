<?php

namespace App\Services\Accounts;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CRUD for the chart of accounts (groups + ledgers) with the integrity guards
 * from spec §5.1 / §7: system groups can't be deleted, a ledger that has
 * postings can only be deactivated, and nature is inherited from the group.
 */
class ChartOfAccountsService
{
    public function __construct(private AuditLogger $audit)
    {
    }

    /* ── Groups ───────────────────────────────────────────────── */

    /** Full group tree (nested children) for the CoA screen. */
    public function groupTree(int $tenantId): array
    {
        $groups = AccountGroup::forTenant($tenantId)
            ->withCount('ledgers')
            ->orderBy('name')
            ->get();

        return $this->buildTree($groups, null);
    }

    /** Flat group list (for parent/group dropdowns). */
    public function groups(int $tenantId)
    {
        return AccountGroup::forTenant($tenantId)->orderBy('name')->get();
    }

    public function createGroup(array $data, int $tenantId, ?int $userId): AccountGroup
    {
        $nature = $this->resolveNature($data, $tenantId);

        $group = AccountGroup::create([
            'tenant_id' => $tenantId,
            'parent_id' => $data['parent_id'] ?? null,
            'name'      => $data['name'],
            'nature'    => $nature,
            'is_primary' => false,
            'is_system' => false,
        ]);

        $this->audit->log($tenantId, $userId, 'account_group', $group->id, 'create', after: $group->toArray());
        Log::channel('accounts')->info('Account group created', ['group_id' => $group->id, 'tenant_id' => $tenantId]);

        return $group;
    }

    public function updateGroup(AccountGroup $group, array $data, int $tenantId, ?int $userId): AccountGroup
    {
        $this->assertTenant($group, $tenantId);

        if ($group->is_system && (($data['name'] ?? $group->name) !== $group->name || isset($data['nature']))) {
            throw new BusinessException('System groups cannot be renamed or reclassified.');
        }

        // A reparent must point at a group owned by the same tenant.
        if (array_key_exists('parent_id', $data) && $data['parent_id']) {
            $this->groupFor($data['parent_id'], $tenantId);
        }

        $before = $group->toArray();
        $group->update([
            'name'      => $data['name'] ?? $group->name,
            'parent_id' => array_key_exists('parent_id', $data) ? $data['parent_id'] : $group->parent_id,
        ]);

        $this->audit->log($tenantId, $userId, 'account_group', $group->id, 'update', $before, $group->fresh()->toArray());

        return $group;
    }

    public function deleteGroup(AccountGroup $group, int $tenantId, ?int $userId): void
    {
        $this->assertTenant($group, $tenantId);

        if ($group->is_system) {
            throw new BusinessException('System groups cannot be deleted.');
        }
        if ($group->ledgers()->exists()) {
            throw new BusinessException('This group has ledgers. Move or delete them first.');
        }
        if ($group->children()->exists()) {
            throw new BusinessException('This group has sub-groups. Delete them first.');
        }

        $before = $group->toArray();
        $group->delete();
        $this->audit->log($tenantId, $userId, 'account_group', $group->id, 'update', $before, null);
    }

    /* ── Ledgers ──────────────────────────────────────────────── */

    public function ledgers(int $tenantId, array $filters)
    {
        $query = Ledger::forTenant($tenantId)
            ->with('group:id,name,nature')
            ->search($filters['search'] ?? null);

        if (! empty($filters['group_id'])) {
            $query->where('group_id', $filters['group_id']);
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->orderBy('name')->paginate((int) ($filters['per_page'] ?? 50));
    }

    /** Flat active-ledger list for voucher-entry dropdowns. */
    public function ledgerOptions(int $tenantId)
    {
        return Ledger::forTenant($tenantId)->active()
            ->with('group:id,name,nature')
            ->orderBy('name')
            ->get(['id', 'name', 'group_id', 'is_bank', 'is_cash', 'is_party']);
    }

    public function createLedger(array $data, int $tenantId, ?int $userId): Ledger
    {
        $group = $this->groupFor($data['group_id'] ?? null, $tenantId);

        $ledger = Ledger::create([
            'tenant_id'            => $tenantId,
            'group_id'             => $group->id,
            'name'                 => $data['name'],
            'code'                 => $data['code'] ?? null,
            'opening_balance'      => $data['opening_balance'] ?? 0,
            'opening_balance_type' => $data['opening_balance_type'] ?? 'dr',
            'is_bank'              => $data['is_bank'] ?? false,
            'is_cash'              => $data['is_cash'] ?? false,
            'is_party'            => $data['is_party'] ?? false,
            'party_id'            => $data['party_id'] ?? null,
            'is_active'           => $data['is_active'] ?? true,
        ]);

        $this->audit->log($tenantId, $userId, 'ledger', $ledger->id, 'create', after: $ledger->toArray());
        Log::channel('accounts')->info('Ledger created', ['ledger_id' => $ledger->id, 'tenant_id' => $tenantId]);

        return $ledger->load('group:id,name,nature');
    }

    public function updateLedger(Ledger $ledger, array $data, int $tenantId, ?int $userId): Ledger
    {
        $this->assertTenant($ledger, $tenantId);

        if (isset($data['group_id']) && $data['group_id'] != $ledger->group_id) {
            $this->groupFor($data['group_id'], $tenantId); // validate belongs to tenant
        }

        $before = $ledger->toArray();
        $ledger->update(array_intersect_key($data, array_flip([
            'group_id', 'name', 'code', 'opening_balance', 'opening_balance_type',
            'is_bank', 'is_cash', 'is_party', 'party_id', 'is_active',
        ])));

        $this->audit->log($tenantId, $userId, 'ledger', $ledger->id, 'update', $before, $ledger->fresh()->toArray());

        return $ledger->load('group:id,name,nature');
    }

    /**
     * A ledger with postings is never destroyed — it is deactivated so history
     * and reports stay intact. Only a never-used ledger is soft-deleted.
     */
    public function deleteLedger(Ledger $ledger, int $tenantId, ?int $userId): string
    {
        $this->assertTenant($ledger, $tenantId);

        if ($ledger->lines()->exists()) {
            $ledger->update(['is_active' => false]);
            $this->audit->log($tenantId, $userId, 'ledger', $ledger->id, 'update', null, ['is_active' => false]);
            return 'deactivated';
        }

        $before = $ledger->toArray();
        $ledger->delete();
        $this->audit->log($tenantId, $userId, 'ledger', $ledger->id, 'update', $before, null);
        return 'deleted';
    }

    /* ── Helpers ──────────────────────────────────────────────── */

    private function buildTree($groups, $parentId): array
    {
        return $groups->where('parent_id', $parentId)->map(function ($group) use ($groups) {
            return [
                'id'            => $group->id,
                'name'          => $group->name,
                'nature'        => $group->nature,
                'is_primary'    => $group->is_primary,
                'is_system'     => $group->is_system,
                'ledgers_count' => $group->ledgers_count,
                'children'      => $this->buildTree($groups, $group->id),
            ];
        })->values()->all();
    }

    /** A child group inherits its nature from the parent; a root needs an explicit one. */
    private function resolveNature(array $data, int $tenantId): string
    {
        if (! empty($data['parent_id'])) {
            $parent = AccountGroup::forTenant($tenantId)->find($data['parent_id']);
            if (! $parent) {
                throw new BusinessException('Parent group not found.');
            }
            return $parent->nature;
        }

        if (empty($data['nature'])) {
            throw new BusinessException('A top-level group must specify its nature.');
        }

        return $data['nature'];
    }

    private function groupFor($groupId, int $tenantId): AccountGroup
    {
        $group = AccountGroup::forTenant($tenantId)->find($groupId);
        if (! $group) {
            throw new BusinessException('Account group not found.');
        }
        return $group;
    }

    private function assertTenant($model, int $tenantId): void
    {
        if ($model->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }
}
