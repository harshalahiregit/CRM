<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\ContractService;
use Illuminate\Http\Request;

/**
 * Unauthenticated contract portal — tenant context derives ONLY from the
 * public token. The QR code on the PDF resolves here for legal verification.
 */
class PublicContractController extends Controller
{
    public function __construct(private ContractService $contractService)
    {
    }

    public function show(string $token)
    {
        $contract = $this->contractService->findByPublicToken($token);

        return response()->json($this->contractService->publicPayload($contract));
    }

    /** Client comment on the discussion thread (old-CRM parity). */
    public function comment(Request $request, string $token)
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'body' => 'required|string|max:5000',
        ]);

        $contract = $this->contractService->findByPublicToken($token);
        $this->contractService->addPublicComment($contract, $data['name'] ?? 'Client', $data['body']);

        return response()->json($this->contractService->publicPayload($contract), 201);
    }

    /** Client PDF download (old-CRM parity: public page has Download PDF). */
    public function pdf(string $token)
    {
        $contract = $this->contractService->findByPublicToken($token);
        $contract->load(['pages', 'type:id,name', 'client:id,company']);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.contract', ['contract' => $contract]);

        return $pdf->download("contract-{$contract->reference_no}.pdf");
    }

    /** Client-side signing (acceptance). Locks after the first signature. */
    public function sign(Request $request, string $token)
    {
        $data = $request->validate([
            'method' => 'required|in:draw,type,upload',
            'image'  => 'nullable|string|max:1400000',
            'name'   => 'required|string|max:255',
            'email'  => 'nullable|email|max:255',
        ]);

        $contract = $this->contractService->findByPublicToken($token);
        $signed = $this->contractService->publicSign($contract, $data, $request->ip(), $request->userAgent());

        return response()->json($this->contractService->publicPayload($signed));
    }
}
