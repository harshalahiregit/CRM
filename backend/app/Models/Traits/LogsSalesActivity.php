<?php

namespace App\Models\Traits;

use App\Models\Sales\SalesActivity;

/**
 * Gives any sales model a polymorphic activity log on the shared
 * `sales_activities` table. Mirrors Lead::logActivity() but works across
 * entities via subject_type/subject_id (stores the FQCN, no morph map).
 */
trait LogsSalesActivity
{
    public function activities()
    {
        return $this->morphMany(SalesActivity::class, 'subject')->latest();
    }

    public function logActivity(string $type, string $description, ?string $oldVal = null, ?string $newVal = null, ?int $performedBy = null): void
    {
        $this->activities()->create([
            'tenant_id'    => $this->tenant_id,
            'type'         => $type,
            'description'  => $description,
            'old_value'    => $oldVal,
            'new_value'    => $newVal,
            'performed_by' => $performedBy ?? auth()->id(),
        ]);
    }
}
