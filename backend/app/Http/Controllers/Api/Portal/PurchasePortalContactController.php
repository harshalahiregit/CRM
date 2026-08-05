<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseContactRequest;
use App\Http\Requests\Purchase\UpdatePurchaseContactRequest;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseContactStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Purchase Vendor Portal — the vendor's own contacts. Purchase-owned; every query
 * is scoped to the authenticated PurchaseVendor ($request->user()). A contact id
 * is validated against ownership (404 existence-hiding). purchase_vendor_id is
 * always taken from the token, never the request body — no cross-vendor access.
 */
class PurchasePortalContactController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $this->vendor($request);
        $q = PurchaseContact::forTenant($vendor->tenant_id)->where('purchase_vendor_id', $vendor->id);

        if ($s = $request->query('search')) {
            $q->where(fn ($w) => $w->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }
        if ($st = $request->query('status')) {
            $q->where('status', $st);
        }

        return response()->json($q->orderByDesc('is_primary')->orderBy('first_name')->get());
    }

    public function store(StorePurchaseContactRequest $request)
    {
        $vendor = $this->vendor($request);
        $data = $request->validated();
        $data['tenant_id'] = $vendor->tenant_id;
        $data['purchase_vendor_id'] = $vendor->id;

        $contact = PurchaseContact::create($data);
        $this->syncPrimary($vendor, $contact);

        return response()->json($contact->fresh(), 201);
    }

    public function show(Request $request, int $contact)
    {
        return response()->json($this->owned($request, $contact));
    }

    public function update(UpdatePurchaseContactRequest $request, int $contact)
    {
        $vendor = $this->vendor($request);
        $model = $this->owned($request, $contact);
        $model->update($request->validated());
        $this->syncPrimary($vendor, $model->fresh());

        return response()->json($model->fresh());
    }

    public function setStatus(Request $request, int $contact)
    {
        $model = $this->owned($request, $contact);
        $data = $request->validate(['status' => ['required', Rule::in(PurchaseContactStatus::ALL)]]);
        $model->update(['status' => $data['status']]);

        return response()->json($model->fresh());
    }

    public function destroy(Request $request, int $contact)
    {
        $this->owned($request, $contact)->delete();

        return response()->json(['message' => 'Deleted']);
    }

    /* ── scoping ─────────────────────────────────────────────────────────── */

    private function vendor(Request $request): PurchaseVendor
    {
        $vendor = $request->user();
        // #45 — 403, not 401: an authenticated identity of the WRONG TYPE is a
        // permission failure, and a 401 here would clear the caller's session.
        // EnsurePurchaseVendorPortalAccess already answers this case with 403;
        // this is the same answer for the defence-in-depth check.
        abort_unless($vendor instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $vendor;
    }

    private function owned(Request $request, int $id): PurchaseContact
    {
        $vendor = $this->vendor($request);
        $contact = PurchaseContact::forTenant($vendor->tenant_id)->where('purchase_vendor_id', $vendor->id)->find($id);
        abort_unless($contact, 404, 'Contact not found');

        return $contact;
    }

    /** Keep at most one primary contact per vendor. */
    private function syncPrimary(PurchaseVendor $vendor, PurchaseContact $contact): void
    {
        if (! $contact->is_primary) {
            return;
        }
        PurchaseContact::forTenant($vendor->tenant_id)
            ->where('purchase_vendor_id', $vendor->id)
            ->where('id', '!=', $contact->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
