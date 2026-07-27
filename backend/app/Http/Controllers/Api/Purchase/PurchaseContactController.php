<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseContactRequest;
use App\Http\Requests\Purchase\UpdatePurchaseContactRequest;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseContactService;
use App\Support\Purchase\PurchaseContactStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase-vendor contacts — the procurement module's OWN contact surface, backed
 * by purchase_contacts via PurchaseContactService. Keyed to the Purchase Vendor
 * master (purchase_vendors) by purchase_vendor_id only — no shared Vendor, no TPV.
 * Cross-tenant / cross-vendor access → 404.
 */
class PurchaseContactController extends Controller
{
    public function __construct(private PurchaseContactService $contactService)
    {
    }

    public function index(Request $request, PurchaseVendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        return response()->json(
            $this->contactService->listForVendor($vendor, $request->only(['status', 'search']))
        );
    }

    public function store(StorePurchaseContactRequest $request, PurchaseVendor $vendor)
    {
        $this->assertVendor($request, $vendor);

        return response()->json($this->contactService->create($vendor, $request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseVendor $vendor, PurchaseContact $contact)
    {
        $this->assertVendor($request, $vendor);
        $this->assertContact($vendor, $contact);

        return response()->json($contact->load('creator:id,name'));
    }

    public function update(UpdatePurchaseContactRequest $request, PurchaseVendor $vendor, PurchaseContact $contact)
    {
        $this->assertVendor($request, $vendor);
        $this->assertContact($vendor, $contact);

        return response()->json($this->contactService->update($contact, $request->validated(), $request->user()));
    }

    public function setStatus(Request $request, PurchaseVendor $vendor, PurchaseContact $contact)
    {
        $this->assertVendor($request, $vendor);
        $this->assertContact($vendor, $contact);

        $data = $request->validate(['status' => ['required', Rule::in(PurchaseContactStatus::ALL)]]);

        return response()->json($this->contactService->setStatus($contact, $data['status'], $request->user()));
    }

    public function destroy(Request $request, PurchaseVendor $vendor, PurchaseContact $contact)
    {
        $this->assertVendor($request, $vendor);
        $this->assertContact($vendor, $contact);

        $this->contactService->delete($contact, $request->user());

        return response()->json(['message' => 'Contact deleted']);
    }

    private function assertVendor(Request $request, PurchaseVendor $vendor): void
    {
        abort_unless((int) $vendor->tenant_id === (int) $request->user()->tenant_id, 404, 'Purchase vendor not found');
    }

    private function assertContact(PurchaseVendor $vendor, PurchaseContact $contact): void
    {
        abort_unless((int) $contact->purchase_vendor_id === (int) $vendor->id, 404, 'Contact not found');
    }
}
