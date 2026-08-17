<?php

namespace App\Repositories\Project;

use App\Models\Project\Project;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class ProjectRepository extends BaseRepository
{
    protected string $modelClass = Project::class;

    /** Filtered, tenant-scoped project list. */
    public function filtered(int $tenantId, array $filters, ?array $visibility = null): Collection
    {
        $query = Project::forTenant($tenantId)->with('creator:id,name');

        // Access barrier: a non-admin only sees projects they created or belong
        // to. TaskService passes null for admins, which skips this entirely.
        if ($visibility) {
            $uid = (int) $visibility['user_id'];
            $query->where(fn ($q) => $q->where('created_by', $uid)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $uid)));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $s)->orWhere('description', 'like', $s));
        }
        if (! empty($filters['member'])) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $filters['member']));
        }
        // A project already carries its vendor link (vendor_id + link_type); a
        // vendor screen is that existing column read one vendor at a time, so
        // there is one project list viewed several ways — no per-module store.
        //
        // `vendor_type` names a party TYPE, never a raw link_type: it is resolved
        // against Project::VENDOR_LINK_MAP inside the scope, so a caller cannot
        // pivot this filter onto another module's vendors. It defaults to
        // tpv_vendor, which is what every caller predating Purchase means.
        if (! empty($filters['vendor_id'])) {
            $query->forVendorLink(
                (int) $filters['vendor_id'],
                $filters['vendor_type'] ?? 'tpv_vendor',
            );
        }
        // Tags live in the shared taggables table — filter without joining it into
        // the model, so Project stays unaware of how tagging is stored.
        if (! empty($filters['tag']) && Schema::hasTable('taggables')) {
            $query->whereIn('id', function ($q) use ($filters, $tenantId) {
                $q->select('taggable_id')->from('taggables')
                    ->join('tags', 'tags.id', '=', 'taggables.tag_id')
                    ->where('taggables.tenant_id', $tenantId)
                    ->where('taggables.taggable_type', 'project')
                    ->where('tags.name', $filters['tag']);
            });
        }

        return $query->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?Project
    {
        return Project::forTenant($tenantId)->find($id);
    }
}
