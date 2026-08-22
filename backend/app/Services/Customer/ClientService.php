<?php

namespace App\Services\Customer;

use App\Exceptions\UnauthorizedTenantException;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Customer\ClientGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer CRUD, contacts, groups, and account-manager assignment. Read-only
 * rollups live in ClientLedgerService; import/export in ClientImportExportService.
 */
class ClientService
{
    public function __construct(private CustomFieldService $customFields)
    {
    }

    /* ── List + summary ───────────────────────────────────────── */
    public function list(int $tenantId, array $filters)
    {
        $query = Client::forTenant($tenantId)
            ->withCount('contacts')
            ->with('groups:id,name')
            ->search($filters['search'] ?? null);

        if (isset($filters['active']) && $filters['active'] !== '') {
            $query->where('active', (bool) $filters['active']);
        }

        if (! empty($filters['group_id'])) {
            $query->whereHas('groups', fn ($q) => $q->where('client_groups.id', $filters['group_id']));
        }

        $sort  = in_array($filters['sort'] ?? '', ['company', 'created_at']) ? $filters['sort'] : 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function summary(int $tenantId): array
    {
        $clients  = Client::forTenant($tenantId);
        $contacts = ClientContact::forTenant($tenantId);

        return [
            'total'            => (clone $clients)->count(),
            'active'           => (clone $clients)->where('active', true)->count(),
            'inactive'         => (clone $clients)->where('active', false)->count(),
            'contacts'         => (clone $contacts)->count(),
            'with_portal'      => (clone $contacts)->whereNotNull('user_id')->count(),
            'added_this_month' => (clone $clients)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];
    }

    /* ── CRUD ─────────────────────────────────────────────────── */
    public function create(array $data, int $tenantId, int $userId): Client
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $contacts     = $data['contacts']      ?? [];
            $groupIds     = $data['group_ids']     ?? [];
            $customFields = $data['custom_fields'] ?? [];
            $admins       = $data['admins']        ?? null; // ordered user ids (stepper); absent for CSV import
            unset($data['contacts'], $data['group_ids'], $data['custom_fields'], $data['admins']);

            $client = Client::create([...$data, 'tenant_id' => $tenantId, 'added_by' => $userId]);

            $this->syncContacts($client, $contacts, $tenantId);
            $client->groups()->sync($this->tenantGroupIds($groupIds, $tenantId));
            $this->customFields->saveValues($tenantId, 'customers', $client->id, $customFields);
            if (is_array($admins) && $admins !== []) {
                $this->syncAdmins($client, $admins, $tenantId);
            }

            Log::channel('customer')->info('Client created', ['client_id' => $client->id, 'tenant_id' => $tenantId]);

            return $this->show($client->fresh(), $tenantId);
        });
    }

    public function show(Client $client, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        return $client->load([
            'contacts',
            'groups:id,name',
            'admins:id,name,email',
        ])->loadCount('contacts');
    }

    public function update(Client $client, array $data, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        return DB::transaction(function () use ($client, $data, $tenantId) {
            $propagateDocs   = (bool) ($data['apply_to_previous_documents'] ?? false);
            $propagateCredit = (bool) ($data['apply_to_previous_credit_notes'] ?? false);
            $contacts        = $data['contacts']      ?? null;
            $groupIds        = $data['group_ids']     ?? null;
            $customFields    = $data['custom_fields'] ?? [];
            unset(
                $data['contacts'], $data['group_ids'], $data['custom_fields'],
                $data['apply_to_previous_documents'], $data['apply_to_previous_credit_notes'],
            );

            $client->update($data);

            if (is_array($contacts)) {
                $this->syncContacts($client, $contacts, $tenantId, replace: true);
            }
            if (is_array($groupIds)) {
                $client->groups()->sync($this->tenantGroupIds($groupIds, $tenantId));
            }
            $this->customFields->saveValues($tenantId, 'customers', $client->id, $customFields);

            if ($propagateDocs || $propagateCredit) {
                $this->propagateAddress($client->fresh(), $tenantId, $propagateDocs, $propagateCredit);
            }

            Log::channel('customer')->info('Client updated', [
                'client_id' => $client->id, 'tenant_id' => $tenantId,
                'propagated_docs' => $propagateDocs, 'propagated_credit' => $propagateCredit,
            ]);

            return $this->show($client->fresh(), $tenantId);
        });
    }

    /** Flip the customer's active/inactive status (one-click list toggle). */
    public function toggleActive(Client $client, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        $client->update(['active' => ! $client->active]);

        Log::channel('customer')->info('Client status toggled', [
            'client_id' => $client->id, 'tenant_id' => $tenantId, 'active' => $client->active,
        ]);

        return $client;
    }

    /**
     * Save the pinned map location. Coordinates are only meaningful as a
     * pair, so a missing half clears the pin (the address text is kept —
     * it stays useful on its own).
     */
    public function updateLocation(Client $client, array $data, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $hasPin = $lat !== null && $lng !== null;

        $client->update([
            'map_address' => $data['map_address'] ?? null,
            'latitude'    => $hasPin ? $lat : null,
            'longitude'   => $hasPin ? $lng : null,
        ]);

        Log::channel('customer')->info('Client map location updated', [
            'client_id' => $client->id, 'tenant_id' => $tenantId, 'pinned' => $hasPin,
        ]);

        return $client->fresh();
    }

    public function delete(Client $client, int $tenantId): void
    {
        $this->assertTenant($client, $tenantId);

        DB::transaction(function () use ($client) {
            // Soft delete does not fire DB-level FK cascade — soft-delete children
            // and detach pivots so counts stay accurate.
            $client->contacts()->delete();
            $client->groups()->detach();
            $client->admins()->detach();
            $client->delete();
        });

        Log::channel('customer')->info('Client deleted', ['client_id' => $client->id, 'tenant_id' => $tenantId]);
    }

    /* ── Contacts ─────────────────────────────────────────────── */
    /**
     * Upsert contacts. When $replace, contacts omitted from the payload (by id)
     * are removed. Guarantees at most one primary.
     */
    private function syncContacts(Client $client, array $contacts, int $tenantId, bool $replace = false): void
    {
        $keptIds = [];
        $primarySeen = false;

        foreach ($contacts as $row) {
            if (empty($row['email']) && empty($row['first_name'])) {
                continue;
            }

            $isPrimary = ! $primarySeen && (bool) ($row['is_primary'] ?? false);
            if ($isPrimary) {
                $primarySeen = true;
            }

            $payload = [
                'tenant_id'           => $tenantId,
                'first_name'          => $row['first_name'] ?? '',
                'last_name'           => $row['last_name'] ?? null,
                'email'               => $row['email'] ?? null,
                'phone'               => $row['phone'] ?? null,
                'title'               => $row['title'] ?? null,
                'is_primary'          => $isPrimary,
                'active'              => $row['active'] ?? true,
                'email_notifications' => $row['email_notifications'] ?? null,
                'permissions'         => $this->cleanPermissions($row['permissions'] ?? null),
                'emails_enabled'      => (bool) ($row['emails_enabled'] ?? true),
            ];

            if (! empty($row['id'])) {
                $contact = $client->contacts()->whereKey($row['id'])->first();
                if ($contact) {
                    $contact->update($payload);
                    $keptIds[] = $contact->id;
                    continue;
                }
            }

            $keptIds[] = $client->contacts()->create($payload)->id;
        }

        if ($replace) {
            $client->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
        }

        // Ensure exactly one primary when contacts exist and none was flagged.
        if (! $primarySeen && ! $client->contacts()->where('is_primary', true)->exists()) {
            $client->contacts()->oldest()->first()?->update(['is_primary' => true]);
        }
    }

    /** Known modules only, deduped, canonical order; null stays null. */
    private function cleanPermissions(?array $permissions): ?array
    {
        if ($permissions === null) {
            return null;
        }

        return array_values(array_intersect(ClientContact::MODULES, $permissions));
    }

    private function tenantGroupIds(array $groupIds, int $tenantId): array
    {
        if (empty($groupIds)) {
            return [];
        }

        return ClientGroup::forTenant($tenantId)->whereIn('id', $groupIds)->pluck('id')->all();
    }

    /* ── Address propagation ──────────────────────────────────── */
    /**
     * Push the client's current billing/shipping address onto existing documents.
     * Product decision: overwrite ALL matching documents including paid ones
     * (differs from legacy which skipped paid). Invoices + estimates are governed
     * by $docs; credit notes by $creditNotes.
     */
    private function propagateAddress(Client $client, int $tenantId, bool $docs, bool $creditNotes): void
    {
        $snapshot = $client->addressSnapshot();
        $counts = [];

        if ($docs) {
            foreach (['sales_invoices', 'estimates'] as $tableName) {
                $counts[$tableName] = DB::table($tableName)
                    ->where('tenant_id', $tenantId)->where('client_id', $client->id)
                    ->update($snapshot);
            }
        }
        if ($creditNotes) {
            $counts['credit_notes'] = DB::table('credit_notes')
                ->whereNull('deleted_at')
                ->where('tenant_id', $tenantId)->where('client_id', $client->id)
                ->update($snapshot);
        }

        Log::channel('customer')->warning('Client address propagated to documents', [
            'client_id' => $client->id, 'counts' => $counts,
        ]);
    }

    /* ── Customer admins (account managers) ───────────────────── */
    public function admins(Client $client, int $tenantId): Collection
    {
        $this->assertTenant($client, $tenantId);
        return $client->admins()->select('users.id', 'users.name', 'users.email')->get();
    }

    /** Staff users of the tenant who can be assigned as account managers. */
    public function assignableStaff(int $tenantId): Collection
    {
        return User::where('tenant_id', $tenantId)
            ->whereIn('role', ['admin', 'staff'])
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
    }

    /**
     * Names offered by the "Parent Company" picker.
     *
     * Two sources, because a parent is usually another customer in the workspace
     * but doesn't have to be — a holding company may exist only as a label on
     * its subsidiaries. So we union:
     *   1. every client's company name (the common case), and
     *   2. distinct parent_company values already saved that aren't clients,
     *      so a name typed once is reusable and spelling stays consistent.
     *
     * parent_company remains free text: it is exported/imported by name in the
     * CSV round-trip, and existing rows must keep resolving. Callers may still
     * submit a brand-new name, which is what "add a new one" means here.
     *
     * Each entry is {id, name}: `id` is the client to LINK to (parent_client_id),
     * and is null for a name-only parent that isn't a customer record.
     *
     * A company's own subsidiaries are excluded as well as itself — linking a
     * parent to its own child would make a cycle.
     *
     * @return array<int, array{id:?int, name:string}>
     */
    public function parentCompanyOptions(int $tenantId, ?int $excludeClientId = null): array
    {
        $descendantIds = $excludeClientId
            ? $this->descendantIds($tenantId, $excludeClientId)->push($excludeClientId)->all()
            : [];

        $clients = Client::forTenant($tenantId)
            ->when($descendantIds, fn ($q) => $q->whereNotIn('id', $descendantIds))
            ->whereNotNull('company')->where('company', '!=', '')
            ->orderBy('company')
            ->get(['id', 'company'])
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => trim((string) $c->company)]);

        $linkedNames = $clients->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        // Parent names already in use that are NOT customer records — keep them
        // selectable so a label-only holding company stays reusable.
        $nameOnly = Client::forTenant($tenantId)
            ->whereNotNull('parent_company')->where('parent_company', '!=', '')
            ->distinct()->pluck('parent_company')
            ->map(fn ($n) => trim((string) $n))->filter()
            ->reject(fn ($n) => in_array(mb_strtolower($n), $linkedNames, true))
            ->unique(fn ($n) => mb_strtolower($n))
            ->map(fn ($n) => ['id' => null, 'name' => $n]);

        return $clients->concat($nameOnly)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Every client beneath $clientId, so the parent picker can't create a cycle
     * (A parented to B while B is parented to A). Iterative and depth-capped:
     * legacy rows could already contain a loop, and this must not recurse forever.
     */
    private function descendantIds(int $tenantId, int $clientId): \Illuminate\Support\Collection
    {
        $found   = collect();
        $frontier = collect([$clientId]);

        for ($depth = 0; $depth < 20 && $frontier->isNotEmpty(); $depth++) {
            $children = Client::forTenant($tenantId)
                ->whereIn('parent_client_id', $frontier->all())
                ->whereNotIn('id', $found->all())
                ->pluck('id');

            if ($children->isEmpty()) {
                break;
            }

            $found    = $found->concat($children)->unique();
            $frontier = $children;
        }

        return $found->values();
    }

    public function syncAdmins(Client $client, array $userIds, int $tenantId): Collection
    {
        $this->assertTenant($client, $tenantId);

        // Payload ORDER is meaningful (primary → 2nd → 3rd fallback). whereIn
        // returns DB order, so re-sort the valid ids by their payload position
        // before syncing with a sort_order pivot value.
        $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)->pluck('id')->all();
        $ordered = array_values(array_intersect($userIds, $valid));

        $pivot = [];
        foreach ($ordered as $i => $id) {
            $pivot[$id] = ['sort_order' => $i];
        }
        $client->admins()->sync($pivot);

        Log::channel('customer')->info('Customer admins updated', [
            'client_id' => $client->id, 'admins' => $ordered,
        ]);

        return $this->admins($client, $tenantId);
    }

    /* ── Guard ────────────────────────────────────────────────── */
    private function assertTenant(Client $client, int $tenantId): void
    {
        if ($client->tenant_id !== $tenantId) {
            Log::channel('customer')->warning('Unauthorized client access', [
                'client_id' => $client->id, 'tenant_id' => $tenantId,
            ]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}
