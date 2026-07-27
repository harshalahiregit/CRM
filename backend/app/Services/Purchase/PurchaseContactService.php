<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Repositories\Purchase\PurchaseContactRepository;
use App\Support\Purchase\PurchaseContactStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Purchase-vendor contact management — the Purchase module's OWN contact engine
 * (purchase_contacts), fully independent of TPV. Single primary per vendor is
 * enforced here (no partial-unique index). Contacts are never hard-deleted;
 * status is the soft toggle.
 */
class PurchaseContactService
{
    public function __construct(private PurchaseContactRepository $contactRepository)
    {
    }

    public function listForVendor(PurchaseVendor $vendor, array $filters): Collection
    {
        return $this->contactRepository->filtered($vendor->tenant_id, $vendor->id, $filters);
    }

    public function create(PurchaseVendor $vendor, array $data, User $actor): PurchaseContact
    {
        $tenantId = $actor->tenant_id;

        // At most one primary per vendor — demote the current one first.
        if (! empty($data['is_primary'])) {
            $this->demotePrimary($tenantId, $vendor->id);
        }

        $contact = PurchaseContact::create([
            ...$data,
            'tenant_id'          => $tenantId,
            'purchase_vendor_id' => $vendor->id,
            'created_by'         => $actor->id,
            'updated_by'         => $actor->id,
            'status'             => $data['status'] ?? Status::ACTIVE,
        ]);

        $contact->recordAudit('Contact Added', $actor, null, [
            'purchase_vendor_id' => $vendor->id, 'name' => $contact->full_name,
        ]);

        Log::channel('purchase')->info('Purchase contact added', [
            'contact_id' => $contact->id, 'purchase_vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        return $contact->fresh();
    }

    public function update(PurchaseContact $contact, array $data, User $actor): PurchaseContact
    {
        // Turning primary on demotes any other primary for this vendor.
        if (! empty($data['is_primary']) && ! $contact->is_primary) {
            $this->demotePrimary($contact->tenant_id, $contact->purchase_vendor_id, $contact->id);
        }

        $contact->update([...$data, 'updated_by' => $actor->id]);
        $contact->recordAudit('Contact Updated', $actor, null, ['name' => $contact->full_name]);

        Log::channel('purchase')->info('Purchase contact updated', [
            'contact_id' => $contact->id, 'tenant_id' => $contact->tenant_id,
        ]);

        return $contact->fresh();
    }

    public function setStatus(PurchaseContact $contact, string $status, User $actor): PurchaseContact
    {
        $contact->update(['status' => $status, 'updated_by' => $actor->id]);

        $action = $status === Status::ACTIVE ? 'Contact Activated' : 'Contact Deactivated';
        $contact->recordAudit($action, $actor, null, ['name' => $contact->full_name]);

        Log::channel('purchase')->info('Purchase contact status changed', [
            'contact_id' => $contact->id, 'status' => $status, 'tenant_id' => $contact->tenant_id,
        ]);

        return $contact->fresh();
    }

    /** Soft-delete a single vendor contact (scoped/guarded by the controller). */
    public function delete(PurchaseContact $contact, User $actor): void
    {
        $contact->recordAudit('Contact Deleted', $actor, null, [
            'purchase_vendor_id' => $contact->purchase_vendor_id, 'name' => $contact->full_name,
        ]);

        $contact->update(['updated_by' => $actor->id]);
        $contact->delete();

        Log::channel('purchase')->info('Purchase contact deleted', [
            'contact_id' => $contact->id, 'purchase_vendor_id' => $contact->purchase_vendor_id, 'tenant_id' => $contact->tenant_id,
        ]);
    }

    /** Clear is_primary on all of a vendor's contacts (optionally excluding one). */
    private function demotePrimary(int $tenantId, int $vendorId, ?int $exceptId = null): void
    {
        PurchaseContact::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_primary' => false]);
    }
}
