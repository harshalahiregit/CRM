<?php

namespace App\Repositories\Transport;

use App\Models\Transport\ConsignmentContainer;
use App\Models\Transport\TransportContainer;
use App\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Query layer for the container master and its attachments.
 *
 * Every method takes $tenantId and opens with ->forTenant(). Nothing here reads
 * auth(), so the repository stays usable from console commands, queued jobs and
 * tests where there is no authenticated user.
 *
 * ── SEARCHES GO THROUGH THE NORMALISED KEY, ALWAYS ───────────────────────
 * Never through `container_number`. That column holds what the operator typed
 * (CTD §7, "retain original entered value"), so searching it would miss
 * "ABCD1234567" when the row was entered as "abcd-123456-7". Every method here
 * that takes a number normalises it first.
 */
class TransportContainerRepository extends BaseRepository
{
    protected string $modelClass = TransportContainer::class;

    /**
     * @param  array{search?:string,attached?:bool,per_page?:int}  $filters
     */
    public function filtered(int $tenantId, array $filters = []): LengthAwarePaginator
    {
        $query = TransportContainer::forTenant($tenantId)
            ->withCount(['attachments as active_attachments_count' => fn ($q) => $q->whereNull('detached_at')])
            ->withCount('attachments')
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->numberContains($term))
            // "On a consignment right now" — the list's one real filter. There is
            // no status filter because there is no status (D-44).
            ->when(
                array_key_exists('attached', $filters) && $filters['attached'] !== null,
                fn ($q) => $filters['attached']
                    ? $q->whereHas('attachments', fn ($a) => $a->whereNull('detached_at'))
                    : $q->whereDoesntHave('attachments', fn ($a) => $a->whereNull('detached_at')),
            );

        // Clamped so a client cannot request an unbounded page — the same guard
        // every other Transport repository carries.
        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 200));

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /** Tenant-scoped AT the point of lookup, so a cross-tenant id never resolves. */
    public function findForTenant(int $id, int $tenantId): ?TransportContainer
    {
        return TransportContainer::forTenant($tenantId)->find($id);
    }

    /**
     * CTD-001 — find one container by any spelling of its number.
     *
     * Exact on the normalised key: a container number is an identifier, and a
     * partial match would return a different box.
     */
    public function findByNumber(string $number, int $tenantId): ?TransportContainer
    {
        return TransportContainer::forTenant($tenantId)->withNumber($number)->first();
    }

    /** The attachment that is live for this container, if any. At most one. */
    public function activeAttachment(int $containerId, int $tenantId): ?ConsignmentContainer
    {
        return ConsignmentContainer::forTenant($tenantId)
            ->forContainer($containerId)
            ->active()
            ->first();
    }

    /** STOS-CTD §7 — every attachment this container has ever had, newest first. */
    public function attachmentHistory(int $containerId, int $tenantId): Collection
    {
        return ConsignmentContainer::forTenant($tenantId)
            ->forContainer($containerId)
            ->with(['consignment:id,consignment_number,order_id'])
            ->orderByDesc('attached_at')
            ->get();
    }

    /** STOS-CTD §8 — what a consignment is carrying, and what it used to. */
    public function attachmentsForConsignment(int $consignmentId, int $tenantId, bool $activeOnly = false): Collection
    {
        return ConsignmentContainer::forTenant($tenantId)
            ->forConsignment($consignmentId)
            ->when($activeOnly, fn ($q) => $q->active())
            ->with(['container:id,container_number,container_number_normalized,container_type'])
            ->orderByDesc('attached_at')
            ->get();
    }
}
