<?php

namespace App\Services\Integration;

use App\Contracts\ProjectDirectoryContract;
use App\Models\Project\Project;

/**
 * The Projects module's own outward-facing read surface, so Customer / Sales /
 * Accounts can offer a project picker without querying the projects table
 * themselves. Lives here (not in the consuming modules) because the Projects
 * module owns the shape — mirroring how CustomerDirectoryService exposes
 * clients to Helpdesk and Projects.
 *
 * Finished and cancelled projects are excluded: you pick a project to book new
 * costs against, and a closed project shouldn't collect new spend. A project_id
 * already stored on an old expense/cheque still resolves via labelFor(), so
 * history keeps reading correctly.
 */
class ProjectDirectoryService implements ProjectDirectoryContract
{
    private const OPEN_ONLY = ['finished', 'cancelled'];

    public function listProjects(int $tenantId, ?int $customerId = null): array
    {
        return Project::where('tenant_id', $tenantId)
            ->whereNotIn('status', self::OPEN_ONLY)
            ->when($customerId, fn ($q) => $q->where('customer_id', $customerId))
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'customer_id'])
            ->map(fn (Project $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'project_code' => 'PRJ-'.str_pad((string) $p->id, 4, '0', STR_PAD_LEFT),
                'client_name'  => null,   // consumers already know their client
                'status'       => $p->status,
                'customer_id'  => $p->customer_id,
            ])
            ->values()
            ->all();
    }

    public function exists(int $projectId, int $tenantId): bool
    {
        return Project::where('tenant_id', $tenantId)->whereKey($projectId)->exists();
    }

    /** Resolves ANY project (including closed ones) so stored links keep a label. */
    public function labelFor(int $projectId, int $tenantId): ?string
    {
        $name = Project::where('tenant_id', $tenantId)->whereKey($projectId)->value('name');

        return $name ? 'PRJ-'.str_pad((string) $projectId, 4, '0', STR_PAD_LEFT).' · '.$name : null;
    }
}
