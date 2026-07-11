<?php

namespace App\Services\Helpdesk;

use App\Models\Helpdesk\CannedResponse;

/**
 * Canned responses (saved replies). Every query is tenant-scoped so one tenant
 * never sees another's snippets.
 */
class CannedResponseService
{
    public function list(int $tenantId)
    {
        return CannedResponse::where('tenant_id', $tenantId)
            ->orderBy('category')
            ->orderBy('title')
            ->get();
    }

    public function create(array $data, int $tenantId, ?int $userId = null): CannedResponse
    {
        return CannedResponse::create([
            'tenant_id'  => $tenantId,
            'title'      => $data['title'],
            'category'   => $data['category'] ?? null,
            'shortcut'   => $data['shortcut'] ?? null,
            'content'    => $data['content'],
            'created_by' => $userId,
        ]);
    }

    public function update(int $id, array $data, int $tenantId): CannedResponse
    {
        $cr = CannedResponse::where('tenant_id', $tenantId)->findOrFail($id);
        $cr->update(array_filter([
            'title'    => $data['title'] ?? null,
            'category' => array_key_exists('category', $data) ? $data['category'] : null,
            'shortcut' => array_key_exists('shortcut', $data) ? $data['shortcut'] : null,
            'content'  => $data['content'] ?? null,
        ], fn ($v) => $v !== null));

        return $cr->fresh();
    }

    public function delete(int $id, int $tenantId): void
    {
        CannedResponse::where('tenant_id', $tenantId)->where('id', $id)->delete();
    }

    /** Increment usage when an agent inserts a snippet (best-effort analytics). */
    public function markUsed(int $id, int $tenantId): CannedResponse
    {
        $cr = CannedResponse::where('tenant_id', $tenantId)->findOrFail($id);
        $cr->increment('use_count');

        return $cr->fresh();
    }
}
