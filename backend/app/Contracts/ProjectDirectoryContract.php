<?php

namespace App\Contracts;

/**
 * Read-only project lookup for modules OUTSIDE the Projects module.
 *
 * Mirrors CustomerServiceContract (Helpdesk -> Customer): the owning module
 * exposes a narrow read interface, and consumers depend on the interface rather
 * than on the projects table. Customer/Sales/Accounts use this to offer a
 * "which project is this for?" picker — client expenses, cheques and vendor
 * bills all carry a project_id.
 *
 * These project_id columns were deliberately created WITHOUT a foreign key
 * while the Projects module did not exist. That is now only a soft link, so a
 * consumer must tolerate an id that no longer resolves (see labelFor()).
 */
interface ProjectDirectoryContract
{
    /**
     * Active projects visible to the tenant, newest naming first.
     *
     * @param  int|null $customerId  when given, only projects for that client
     * @return array<int, array{id:int, name:string, project_code:string, client_name:?string, status:string, customer_id:?int}>
     */
    public function listProjects(int $tenantId, ?int $customerId = null): array;

    /** Whether the project exists and belongs to the tenant. */
    public function exists(int $projectId, int $tenantId): bool;

    /**
     * Display label for a stored project_id, or null when it no longer resolves
     * (soft link — the project may have been deleted).
     */
    public function labelFor(int $projectId, int $tenantId): ?string;
}
