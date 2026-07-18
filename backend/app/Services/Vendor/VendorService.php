<?php

namespace App\Services\Vendor;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Vendor\VendorRepository;
use App\Support\Vendor\VendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorService
{
    public function __construct(private VendorRepository $vendorRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->vendorRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, int $tenantId): Vendor
    {
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $vendor = DB::transaction(function () use ($data, $contacts, $tenantId) {
            // Set status explicitly rather than leaning on the column default —
            // the default applies in the DB but never reaches the in-memory model,
            // so the create response would carry a null status.
            $vendor = Vendor::create([
                'status' => Status::DRAFT,
                ...$data,
                'tenant_id' => $tenantId,
            ]);
            $this->syncContacts($vendor, $contacts);

            return $vendor;
        });

        $vendor->recordAudit('Vendor Created', null, null, ['vendor_code' => $vendor->vendor_code]);

        Log::channel('vendor')->info('Vendor created', [
            'vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        // fresh(), not load() — see PurchaseRequestService::create().
        return $vendor->fresh(['contacts']);
    }

    public function update(Vendor $vendor, array $data, ?User $actor = null): Vendor
    {
        $contacts = $data['contacts'] ?? null;
        unset($data['contacts']);

        DB::transaction(function () use ($vendor, $data, $contacts) {
            $vendor->update($data);
            if ($contacts !== null) {
                $vendor->contacts()->delete();
                $this->syncContacts($vendor, $contacts);
            }
        });

        $vendor->recordAudit('Vendor Updated', $actor);

        Log::channel('vendor')->info('Vendor updated', [
            'vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id,
        ]);

        return $vendor->fresh(['contacts']);
    }

    /**
     * Admin approval — the gate that makes a vendor transactable by Purchase and
     * grants TPV site access.
     */
    public function approve(Vendor $vendor, User $actor, ?string $remarks = null): Vendor
    {
        if ($vendor->status === Status::ACTIVE) {
            throw new BusinessException('Vendor is already active.');
        }

        $vendor->update([
            'status'      => Status::ACTIVE,
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'notes'       => $remarks ?? $vendor->notes,
        ]);

        $vendor->recordAudit('Vendor Approved', $actor, $remarks, [
            'from' => Status::PENDING_APPROVAL, 'to' => Status::ACTIVE,
        ]);

        Log::channel('vendor')->info('Vendor approved', [
            'vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $vendor;
    }

    public function updateStatus(Vendor $vendor, string $status, User $actor, ?string $remarks = null): Vendor
    {
        if (! Status::isValid($status)) {
            throw new BusinessException("Unknown vendor status: {$status}");
        }

        $from = $vendor->status;
        $vendor->update(['status' => $status]);

        $vendor->recordAudit('Vendor Status Changed', $actor, $remarks, ['from' => $from, 'to' => $status]);

        Log::channel('vendor')->info('Vendor status changed', [
            'vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id,
            'from' => $from, 'to' => $status,
        ]);

        return $vendor;
    }

    public function destroy(Vendor $vendor): void
    {
        $vendor->delete();

        Log::channel('vendor')->info('Vendor deleted', [
            'vendor_id' => $vendor->id, 'tenant_id' => $vendor->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        return [
            'total'       => Vendor::forTenant($tenantId)->count(),
            'active'      => Vendor::forTenant($tenantId)->where('status', Status::ACTIVE)->count(),
            'pending'     => Vendor::forTenant($tenantId)->where('status', Status::PENDING_APPROVAL)->count(),
            'on_hold'     => Vendor::forTenant($tenantId)->where('status', Status::ON_HOLD)->count(),
            'blacklisted' => Vendor::forTenant($tenantId)->where('status', Status::BLACKLISTED)->count(),
            'by_type'     => Vendor::forTenant($tenantId)->select('vendor_type')
                ->selectRaw('count(*) as count')
                ->groupBy('vendor_type')->get(),
        ];
    }

    /** Replace the vendor's contact list, enforcing a single primary. */
    private function syncContacts(Vendor $vendor, array $contacts): void
    {
        $primarySeen = false;

        foreach ($contacts as $contact) {
            $isPrimary = ! $primarySeen && ($contact['is_primary'] ?? false);
            $primarySeen = $primarySeen || $isPrimary;

            $vendor->contacts()->create([...$contact, 'tenant_id' => $vendor->tenant_id, 'is_primary' => $isPrimary]);
        }
    }
}
