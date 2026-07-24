<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tpv\StoreTpvContactRequest;
use App\Http\Requests\Tpv\UpdateTpvContactRequest;
use App\Models\Tpv\TpvContact;
use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvContactService;
use App\Support\Tpv\TpvContactStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vendor contacts CRUD — always scoped to a vendor via /tpv/vendors/{vendor}/contacts.
 * No hard delete: deactivation is a status change. Cross-tenant / cross-vendor
 * access returns 404 so boundaries stay invisible.
 */
class TpvContactController extends Controller
{
    public function __construct(private TpvContactService $contactService)
    {
    }

    public function index(Request $request, Vendor $vendor)
    {
        $this->assertVendorTenant($request, $vendor);

        return response()->json(
            $this->contactService->listForVendor($vendor, $request->only(['status', 'search']))
        );
    }

    public function store(StoreTpvContactRequest $request, Vendor $vendor)
    {
        $this->assertVendorTenant($request, $vendor);

        $contact = $this->contactService->create($vendor, $request->validated(), $request->user());

        return response()->json($contact, 201);
    }

    public function show(Request $request, Vendor $vendor, TpvContact $contact)
    {
        $this->assertVendorTenant($request, $vendor);
        $this->assertContact($vendor, $contact);

        return response()->json($contact->load('creator:id,name'));
    }

    public function update(UpdateTpvContactRequest $request, Vendor $vendor, TpvContact $contact)
    {
        $this->assertVendorTenant($request, $vendor);
        $this->assertContact($vendor, $contact);

        return response()->json(
            $this->contactService->update($contact, $request->validated(), $request->user())
        );
    }

    public function setStatus(Request $request, Vendor $vendor, TpvContact $contact)
    {
        $this->assertVendorTenant($request, $vendor);
        $this->assertContact($vendor, $contact);

        $data = $request->validate(['status' => ['required', Rule::in(TpvContactStatus::ALL)]]);

        return response()->json(
            $this->contactService->setStatus($contact, $data['status'], $request->user())
        );
    }

    /* ── Guards (404 on any cross-tenant / cross-vendor miss) ───────────── */

    private function assertVendorTenant(Request $request, Vendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Vendor not found');
    }

    private function assertContact(Vendor $vendor, TpvContact $contact): void
    {
        abort_unless((int) $contact->vendor_id === (int) $vendor->id, 404, 'Contact not found');
    }
}
