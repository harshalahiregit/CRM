<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvContact;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Tpv\TpvContactRepository;
use App\Support\Tpv\TpvContactStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Vendor contact management — the master contact list for a TPV vendor. Single
 * primary per vendor is enforced here (the DB has no partial-unique index).
 * Contacts are never hard-deleted; status is the soft toggle.
 */
class TpvContactService
{
    public function __construct(private TpvContactRepository $contactRepository)
    {
    }

    public function listForVendor(Vendor $vendor, array $filters): Collection
    {
        return $this->contactRepository->filtered($vendor->tenant_id, $vendor->id, $filters);
    }

    public function create(Vendor $vendor, array $data, User $actor): TpvContact
    {
        $tenantId = $actor->tenant_id;

        // At most one primary per vendor — demote the current one first.
        if (! empty($data['is_primary'])) {
            $this->demotePrimary($tenantId, $vendor->id);
        }

        $contact = TpvContact::create([
            ...$data,
            'tenant_id'  => $tenantId,
            'vendor_id'  => $vendor->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'status'     => $data['status'] ?? Status::ACTIVE,
        ]);

        $contact->recordAudit('Contact Added', $actor, null, [
            'vendor_id' => $vendor->id, 'name' => $contact->full_name,
        ]);

        Log::channel('tpv')->info('TPV contact added', [
            'contact_id' => $contact->id, 'vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        return $contact->fresh();
    }

    public function update(TpvContact $contact, array $data, User $actor): TpvContact
    {
        // Turning primary on demotes any other primary for this vendor.
        if (! empty($data['is_primary']) && ! $contact->is_primary) {
            $this->demotePrimary($contact->tenant_id, $contact->vendor_id, $contact->id);
        }

        $contact->update([...$data, 'updated_by' => $actor->id]);
        $contact->recordAudit('Contact Updated', $actor, null, ['name' => $contact->full_name]);

        Log::channel('tpv')->info('TPV contact updated', [
            'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        ]);

        return $contact->fresh();
    }

    public function setStatus(TpvContact $contact, string $status, User $actor): TpvContact
    {
        $contact->update(['status' => $status, 'updated_by' => $actor->id]);

        $action = $status === Status::ACTIVE ? 'Contact Activated' : 'Contact Deactivated';
        $contact->recordAudit($action, $actor, null, ['name' => $contact->full_name]);

        Log::channel('tpv')->info('TPV contact status changed', [
            'contact_id' => $contact->id, 'status' => $status, 'tenant_id' => $contact->tenant_id,
        ]);

        return $contact->fresh();
    }

    /** Clear is_primary on all of a vendor's contacts (optionally excluding one). */
    private function demotePrimary(int $tenantId, int $vendorId, ?int $exceptId = null): void
    {
        TpvContact::forTenant($tenantId)
            ->where('vendor_id', $vendorId)
            ->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }
}
