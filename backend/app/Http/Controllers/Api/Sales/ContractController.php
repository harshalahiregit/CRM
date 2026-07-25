<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreContractRequest;
use App\Http\Requests\Sales\UpdateContractRequest;
use App\Models\Sales\ContractType;
use App\Models\Sales\SalesContract;
use App\Services\Sales\ContractService;
use Illuminate\Http\Request;

class ContractController extends Controller
{
    public function __construct(private ContractService $contractService)
    {
    }

    public function index(Request $request)
    {
        $contracts = $this->contractService->list($request->user()->tenant_id, $request->only([
            'status', 'client_id', 'type_id', 'search',
        ]));
        return response()->json($contracts);
    }

    public function expiring(Request $request)
    {
        $days = (int) $request->input('days', 30);
        return response()->json($this->contractService->expiringSoon($request->user()->tenant_id, $days));
    }

    public function store(StoreContractRequest $request)
    {
        $contract = $this->contractService->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($contract, 201);
    }

    public function show(SalesContract $contract, Request $request)
    {
        return response()->json($this->contractService->show($contract, $request->user()->tenant_id));
    }

    public function update(UpdateContractRequest $request, SalesContract $contract)
    {
        return response()->json($this->contractService->update($contract, $request->validated(), $request->user()->tenant_id));
    }

    public function destroy(SalesContract $contract, Request $request)
    {
        $this->contractService->delete($contract, $request->user()->tenant_id);
        return response()->json(['message' => 'Contract deleted']);
    }

    public function updateStatus(Request $request, SalesContract $contract)
    {
        $data = $request->validate(['status' => 'required|in:draft,active,expired,terminated,renewed']);
        return response()->json($this->contractService->updateStatus($contract, $data['status'], $request->user()->tenant_id, $request->user()->id));
    }

    public function renew(Request $request, SalesContract $contract)
    {
        $data = $request->validate([
            'value'      => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
        ]);
        return response()->json($this->contractService->renew($contract, $data, $request->user()->tenant_id, $request->user()->id), 201);
    }

    public function sign(Request $request, SalesContract $contract)
    {
        $data = $request->validate([
            'method' => 'required|in:draw,type,upload',
            'image'  => 'nullable|string|max:1400000',   // ~1MB base64 data URL
            'name'   => 'required|string|max:255',
            'email'  => 'nullable|email|max:255',
        ]);

        return response()->json($this->contractService->sign(
            $contract, $data, $request->user()->tenant_id, $request->user()->id,
            $request->ip(), $request->userAgent(),
        ));
    }

    /* ── Comments ──────────────────────────────────────────────── */
    public function addComment(Request $request, SalesContract $contract)
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return response()->json(
            $this->contractService->addComment($contract, $data['body'], $request->user()->tenant_id, $request->user()->id),
            201,
        );
    }

    public function deleteComment(Request $request, SalesContract $contract, int $comment)
    {
        $this->contractService->deleteComment($contract, $comment, $request->user()->tenant_id, $request->user()->id);

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Renewal history ───────────────────────────────────────── */
    public function renewals(Request $request, SalesContract $contract)
    {
        $shown = $this->contractService->show($contract, $request->user()->tenant_id);

        return response()->json(collect($shown->renewalChain())->map(fn ($c) => [
            'id' => $c->id, 'reference_no' => $c->reference_no, 'version' => $c->version,
            'status' => $c->status, 'value' => $c->value,
            'start_date' => $c->start_date?->toDateString(), 'end_date' => $c->end_date?->toDateString(),
            'signed_at' => $c->signed_at, 'is_current' => $c->id === $contract->id,
        ])->values());
    }

    /* ── PDF + send ────────────────────────────────────────────── */
    public function exportPDF(Request $request, SalesContract $contract)
    {
        $shown = $this->contractService->show($contract, $request->user()->tenant_id);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.contract', ['contract' => $shown]);

        return $pdf->download("contract-{$shown->reference_no}.pdf");
    }

    public function send(Request $request, SalesContract $contract)
    {
        $data = $request->validate([
            'to'      => 'required|email',
            'cc'      => 'nullable|array|max:10',
            'cc.*'    => 'email',
            'subject' => 'nullable|string|max:255',
            'body'    => 'nullable|string|max:65535',
        ]);

        $shown = $this->contractService->show($contract, $request->user()->tenant_id);
        $portalUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/').'/portal/contracts/'.$shown->public_token;
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.contract', ['contract' => $shown])->output();
        $body = \App\Support\HtmlSanitizer::clean($data['body'] ?? '<p>Please find the contract attached. You can review and sign it online using the link below.</p>');

        app(\App\Services\Mail\TenantMailer::class)->send(
            $request->user()->tenant_id,
            $data['to'],
            new \App\Mail\Sales\ContractMail($shown, $body, $portalUrl, $pdf, $data['subject'] ?? "Contract: {$shown->subject}"),
            array_slice(array_values(array_unique($data['cc'] ?? [])), 0, 10),
        );

        $shown->logActivity('updated', "Contract emailed to {$data['to']}", null, null, $request->user()->id);

        return response()->json(['message' => 'Contract sent to '.$data['to']]);
    }

    /* ── Contract Types ─────────────────────────────────────────── */
    public function types(Request $request)
    {
        return response()->json($this->contractService->types($request->user()->tenant_id));
    }

    public function storeType(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);
        return response()->json($this->contractService->createType($data['name'], $request->user()->tenant_id), 201);
    }

    public function destroyType(Request $request, ContractType $contractType)
    {
        $this->contractService->deleteType($contractType, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }
}
