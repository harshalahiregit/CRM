<?php

namespace App\Events\Inventory;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An Inventory asset was handed to (or taken back from) an employee.
 *
 * Inventory announces the fact and knows nothing about who cares. HR listens so
 * onboarding can advance its IT & Asset Allocation stage without HR ever holding
 * an asset record of its own.
 */
class AssetAssignedToEmployee
{
    use Dispatchable;

    public function __construct(
        public int $assetId,
        public ?int $employeeId,
        public int $tenantId,
        public ?int $actorId = null,
    ) {
    }

    /** False when the asset was handed back rather than assigned. */
    public function isAssignment(): bool
    {
        return $this->employeeId !== null;
    }
}
