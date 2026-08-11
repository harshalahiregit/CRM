<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreProposalRequest;
use App\Http\Requests\Sales\UpdateProposalRequest;
use App\Http\Requests\Sales\UpdateProposalStatusRequest;
use App\Models\Sales\Proposal;
use App\Services\Sales\ProposalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposalService)
    {
    }

    public function index(Request $request)
    {
        $proposals = $this->proposalService->list(
            $request->user()->tenant_id,
            $request->filled('status') ? $request->status : null,
            $request->filled('search') ? $request->search : null
        );

        return response()->json($proposals);
    }

    public function store(StoreProposalRequest $request)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? [];
        unset($validated['line_items']);

        $proposal = $this->proposalService->create($validated, $lineItems, $request->user()->tenant_id, $request->user()->id);

        return response()->json($proposal, 201);
    }

    public function show(Proposal $proposal, Request $request)
    {
        $loaded = $this->proposalService->show($proposal, $request->user()->tenant_id);

        return response()->json([
            ...$loaded->toArray(),
            'can_download'  => $this->canDownload($request->user(), $loaded),
            'tax_breakdown' => $loaded->taxBreakdown(),
        ]);
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? null;
        unset($validated['line_items']);

        $updated = $this->proposalService->update(
            $proposal, $validated, $lineItems, $request->has('line_items'), $request->user()->tenant_id
        );

        return response()->json($updated);
    }

    public function destroy(Proposal $proposal, Request $request)
    {
        $this->proposalService->delete($proposal, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }

    public function send(Proposal $proposal, Request $request)
    {
        return response()->json($this->proposalService->send($proposal, $request->user()->tenant_id));
    }

    public function updateStatus(UpdateProposalStatusRequest $request, Proposal $proposal)
    {
        return response()->json(
            $this->proposalService->updateStatus(
                $proposal,
                $request->validated('status'),
                $request->user()->tenant_id,
                $request->validated('rejection_reason')
            )
        );
    }

    /**
     * POST /api/sales/proposals/{proposal}/convert-to-estimate
     */
    public function convertToEstimate(Request $request, Proposal $proposal)
    {
        $estimate = $this->proposalService->convertToEstimate($proposal, $request->user()->tenant_id, $request->user()->id);
        return response()->json($estimate, 201);
    }

    /**
     * POST /api/sales/proposals/{proposal}/convert-to-invoice
     */
    public function convertToInvoice(Request $request, Proposal $proposal)
    {
        $invoice = $this->proposalService->convertToInvoice(
            $proposal,
            $request->input('due_date'),
            $request->user()->tenant_id,
            $request->user()->id,
        );
        return response()->json($invoice, 201);
    }

    /**
     * POST /api/sales/proposals/{proposal}/generate-qr
     */
    public function generateQR(Request $request, Proposal $proposal)
    {
        $updated = $this->proposalService->generateQR($proposal, $request->getSchemeAndHttpHost(), $request->user()->tenant_id);

        return response()->json(['qr_code_data' => $updated->qr_code_data]);
    }

    /**
     * GET /api/sales/proposals/{proposal}/pdf
     */
    public function exportPDF(Request $request, Proposal $proposal)
    {
        $proposal = $this->proposalService->show($proposal, $request->user()->tenant_id);
        abort_unless($this->canDownload($request->user(), $proposal), 403, 'You are not authorized to download this proposal.');
        $pdf = Pdf::loadView('pdf.proposal', ['proposal' => $proposal]);

        return $pdf->download("proposal-{$proposal->id}.pdf");
    }

    /** Internal download restricted to owner / assignee / admin (meeting 2.3). */
    private function canDownload($user, Proposal $proposal): bool
    {
        return $user->role === 'admin'
            || $proposal->created_by === $user->id
            || $proposal->assigned_to === $user->id;
    }

    public function submit(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'subject' => 'nullable|string|max:255',
            'body'    => 'nullable|string|max:65535',
            'cc'      => 'nullable|array|max:10',
            'cc.*'    => 'email',
            // User-added attachments (base64). The proposal PDF is always attached
            // server-side regardless — these are extras.
            'attachments'        => 'nullable|array|max:5',
            'attachments.*.name' => 'required_with:attachments|string|max:200',
            'attachments.*.mime' => 'nullable|string|max:150',
            'attachments.*.data' => 'required_with:attachments|string|max:9000000', // ~6.5MB/file
        ]);

        $updated = $this->proposalService->submit(
            $proposal,
            $data,
            $request->user()->tenant_id,
            app(\App\Services\Mail\TenantMailer::class),
            config('cors.frontend_url') ?? env('FRONTEND_URL', $request->headers->get('origin') ?: 'http://localhost:5173'),
            config('app.url'),
        );

        return response()->json($updated);
    }

    public function saveAsTemplate(Request $request, Proposal $proposal)
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
        ]);

        $template = app(\App\Services\Sales\ProposalTemplateService::class)->createFromProposal(
            $proposal, $data['name'], $data['category'] ?? null,
            $request->user()->tenant_id, $request->user()->id
        );

        return response()->json($template, 201);
    }
}
