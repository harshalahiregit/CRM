<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Tpv\TpvContact;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\VendorEmployeeService;
use App\Support\Tpv\TpvContactStatus;
use Illuminate\Http\Request;

/**
 * Vendor/TPV employees — the assignee cascade's second stage (enhancement
 * #2/#9/#10). Given a vendor the admin picks, this lists that vendor's people
 * (TpvContacts) and hands back a login user id to drop into task/project
 * assignees. All tenant-scoped; a vendor id from another tenant 404s.
 */
class VendorEmployeeController extends Controller
{
    use ApiResponse;

    public function __construct(private VendorEmployeeService $employees)
    {
    }

    /** The chosen vendor's employees — the cascade list. */
    public function index(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $rows = $this->employees->listForVendor($vendor->id, $vendor->tenant_id)
            ->map(fn (TpvContact $c) => $this->present($c));

        return $this->success($rows->values(), 'Employees retrieved');
    }

    /** Add a new employee (contact) to this vendor. */
    public function store(Request $request, Vendor $vendor)
    {
        $this->assertTenant($request, $vendor);

        $data = $request->validate([
            'first_name'  => 'required|string|max:120',
            'last_name'   => 'nullable|string|max:120',
            'designation' => 'nullable|string|max:120',
            'department'  => 'nullable|string|max:120',
            'email'       => 'nullable|email|max:190',
            'mobile'      => 'nullable|string|max:40',
            'grant_login' => 'sometimes|boolean',
        ]);

        $contact = TpvContact::create([
            'tenant_id'   => $vendor->tenant_id,
            'vendor_id'   => $vendor->id,
            'created_by'  => $request->user()->id,
            'first_name'  => $data['first_name'],
            'last_name'   => $data['last_name'] ?? null,
            'designation' => $data['designation'] ?? null,
            'department'  => $data['department'] ?? null,
            'email'       => $data['email'] ?? null,
            'mobile'      => $data['mobile'] ?? null,
            'status'      => TpvContactStatus::ACTIVE,
        ]);

        if (! empty($data['grant_login']) && ! empty($data['email'])) {
            $this->employees->grantAccess($contact);
        }

        return $this->success($this->present($contact->fresh('user')), 'Employee added', 201);
    }

    /**
     * Provision (or link) a login for this employee so it becomes assignable, and
     * return the resulting employee (with its user id) — what the assignee picker
     * actually stores.
     */
    public function grantAccess(Request $request, Vendor $vendor, TpvContact $contact)
    {
        $this->assertTenant($request, $vendor);
        abort_unless($contact->vendor_id === $vendor->id && (int) $contact->tenant_id === (int) $vendor->tenant_id, 404);

        $this->employees->grantAccess($contact);

        return $this->success($this->present($contact->fresh('user')), 'Login enabled for employee', 201);
    }

    /** 404 anything that is not the caller's tenant (existence-hiding). */
    private function assertTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    private function present(TpvContact $c): array
    {
        return [
            'id'          => $c->id,
            'name'        => $c->full_name,
            'designation' => $c->designation,
            'department'  => $c->department,
            'email'       => $c->email,
            'mobile'      => $c->mobile,
            'is_primary'  => (bool) $c->is_primary,
            'is_active'   => $c->isActive(),
            'user_id'     => $c->user_id,          // the assignee id, once granted
            'assignable'  => $c->isAssignable(),
        ];
    }
}
