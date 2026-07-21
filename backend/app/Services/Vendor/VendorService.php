<?php

namespace App\Services\Vendor;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Vendor\VendorRepository;
use App\Support\Vendor\VendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        // Login-credential fields belong to the portal User, not the Vendor row.
        $loginName = $data['name'] ?? null;
        $password  = $data['password'] ?? null;
        unset($data['name'], $data['password'], $data['password_confirmation']);

        $vendor = DB::transaction(function () use ($data, $contacts, $tenantId, $loginName, $password) {
            // Set status explicitly rather than leaning on the column default —
            // the default applies in the DB but never reaches the in-memory model,
            // so the create response would carry a null status.
            $vendor = Vendor::create([
                'status' => Status::DRAFT,
                ...$data,
                'tenant_id' => $tenantId,
            ]);

            // Provision a self-service portal login when a password is supplied
            // (the "Add Third-party Vendor" flow). Purchase-side vendor creation
            // omits it and simply gets a profile with no login.
            if (! empty($password)) {
                $user = $this->provisionLoginUser($vendor, $loginName, $password, $tenantId);
                $vendor->update(['user_id' => $user->id]);
            }

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

        $loginName = $data['name'] ?? null;
        $password  = $data['password'] ?? null;
        unset($data['name'], $data['password'], $data['password_confirmation']);

        DB::transaction(function () use ($vendor, $data, $contacts, $loginName, $password) {
            $vendor->update($data);
            if ($contacts !== null) {
                $vendor->contacts()->delete();
                $this->syncContacts($vendor, $contacts);
            }
            $this->syncLoginUser($vendor->fresh(), $loginName, $password, $data);
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

        // Mirror the toggle onto the portal login so an Inactive vendor is locked
        // out immediately (login gate + portal middleware both key off user status).
        if ($vendor->user_id && $vendor->user) {
            $vendor->user->update(['status' => $this->loginStatusFor($status)]);
        }

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
            // "Inactive" is the binary complement of Active (anything not active).
            'inactive'    => Vendor::forTenant($tenantId)->where('status', '!=', Status::ACTIVE)->count(),
            'pending'     => Vendor::forTenant($tenantId)->where('status', Status::PENDING_APPROVAL)->count(),
            'on_hold'     => Vendor::forTenant($tenantId)->where('status', Status::ON_HOLD)->count(),
            'blacklisted' => Vendor::forTenant($tenantId)->where('status', Status::BLACKLISTED)->count(),
            'by_type'     => Vendor::forTenant($tenantId)->select('vendor_type')
                ->selectRaw('count(*) as count')
                ->groupBy('vendor_type')->get(),
        ];
    }

    /**
     * Create the vendor's portal login. The vendor's own email is the login
     * identity, so it must be present. Login status mirrors the vendor: an
     * Active vendor gets an active login; anything else is blocked until activated
     * (the login gate + EnsureVendorPortalAccess both enforce this).
     */
    private function provisionLoginUser(Vendor $vendor, ?string $name, string $password, int $tenantId): User
    {
        if (empty($vendor->email)) {
            throw new BusinessException('An email is required to create login credentials for the vendor.');
        }
        if (User::where('email', $vendor->email)->exists()) {
            throw new BusinessException("A login already exists for {$vendor->email}.");
        }

        return User::create([
            'name'      => $name ?: $vendor->company_name,
            'email'     => $vendor->email,
            'password'  => Hash::make($password),
            'role'      => 'third_party_vendor',
            'tenant_id' => $tenantId,
            'status'    => $this->loginStatusFor($vendor->status),
        ]);
    }

    /**
     * Keep the linked login in step with an edited vendor: rename, re-email,
     * mirror active/inactive, and reset the password only when a new one is given
     * (blank = keep existing). Provisions a login on the fly if the vendor never
     * had one but a password is now supplied.
     */
    private function syncLoginUser(Vendor $vendor, ?string $name, ?string $password, array $data): void
    {
        if (! $vendor->user_id) {
            if (! empty($password)) {
                $user = $this->provisionLoginUser($vendor, $name, $password, $vendor->tenant_id);
                $vendor->update(['user_id' => $user->id]);
            }

            return;
        }

        $user = $vendor->user;
        if (! $user) {
            return;
        }

        $update = [];
        if ($name) {
            $update['name'] = $name;
        }
        if (array_key_exists('email', $data) && ! empty($data['email'])) {
            $update['email'] = $data['email'];
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $this->loginStatusFor($vendor->status);
        }
        if (! empty($password)) {
            $update['password'] = Hash::make($password);
        }

        if ($update) {
            $user->update($update);
        }
    }

    /** Map a vendor lifecycle status to the login account's status. */
    private function loginStatusFor(?string $vendorStatus): string
    {
        return $vendorStatus === Status::ACTIVE ? 'active' : 'inactive';
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
