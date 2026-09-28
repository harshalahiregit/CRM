<?php

namespace App\Http\Controllers\Api\Shared;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\DocumentProviderRequest;
use App\Services\Shared\ProviderCallbackService;
use Illuminate\Http\Request;

/**
 * "I do not hold this document — have someone call me."
 *
 * One controller for both portals. Each portal resolves its own vendor from its
 * own token before reaching here, so a vendor can only ever raise a request for
 * itself; nothing in the payload identifies the vendor.
 *
 * Deliberately thin: this hands a lead to an outside agency and steps out. See
 * ProviderCallbackService.
 */
class ProviderCallbackController extends Controller
{
    public function __construct(private ProviderCallbackService $callbacks)
    {
    }

    /** Agencies this tenant can actually reach — the portal offers only these. */
    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->callbacks->contactable($this->tenantId($request)),
        ]);
    }

    public function store(Request $request, string $provider)
    {
        $data = $request->validate([
            'document_type'  => ['required', 'string', 'max:64'],
            'document_label' => ['nullable', 'string', 'max:160'],
            'contact_name'   => ['required', 'string', 'max:160'],
            'contact_email'  => ['required', 'email', 'max:191'],
            'contact_mobile' => ['nullable', 'string', 'max:60'],
            'company_name'   => ['nullable', 'string', 'max:191'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            // Handing a vendor's phone number to a third party needs their
            // say-so. `accepted` refuses false, "0", "" and absent alike, so
            // the request cannot be made without it.
            'consent'        => ['required', 'accepted'],
        ]);

        [$kind, $vendorId] = $this->vendor($request);

        $saved = $this->callbacks->raise(
            $this->tenantId($request),
            $kind,
            $vendorId,
            $provider,
            $data,
        );

        return response()->json([
            'id'          => $saved->id,
            'provider'    => $saved->provider_name,
            'status'      => $saved->status,
            'document'    => $saved->document_label,
            // The portal says "sent" only when it was. A Queued row means no
            // address is configured for that agency and nothing has gone out.
            'sent'        => $saved->status === DocumentProviderRequest::SENT,
        ], 201);
    }

    /**
     * Who is asking, given either portal's identity.
     *
     * The Purchase portal authenticates as a PurchaseVendor — its own model
     * with its own token, not a User — while the TPV portal authenticates as a
     * User whose vendor hangs off it.
     *
     * @return array{0:string,1:int}
     */
    private function vendor(Request $request): array
    {
        $actor = $request->user();

        if ($actor instanceof PurchaseVendor) {
            return [DocumentProviderRequest::KIND_PURCHASE, (int) $actor->id];
        }

        $vendor = $actor?->vendor;
        if (! $vendor) {
            throw new BusinessException('No vendor account is attached to this login.', 403);
        }

        return [DocumentProviderRequest::KIND_TPV, (int) $vendor->id];
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }
}
