<?php

namespace App\Http\Controllers\Api\Contract;

use App\Http\Controllers\Controller;
use App\Models\Contract\Contract;
use App\Services\Contract\ContractDocumentService;
use App\Services\Contract\ContractService;
use App\Services\Contract\ContractSigningService;
use App\Support\Contract\ContractParty;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The counterparty's surface — unauthenticated, reached by token.
 *
 * A customer or vendor has no login here, so the token IS the authority. That
 * shapes everything below:
 *
 *  - The token is looked up in full and never guessed at; ids are not accepted,
 *    because an id can be incremented and a 48-character token cannot.
 *  - The payload is assembled by hand rather than returned as a model. A model
 *    would carry the token, the internal ids, the creator and whatever column is
 *    added next, to a reader outside the company.
 *  - Opening the page records the view. That is half the audit trail the signed
 *    document later prints.
 */
class PublicContractController extends Controller
{
    public function __construct(
        private ContractService $contracts,
        private ContractSigningService $signing,
    ) {}

    /** The contract as the counterparty sees it. Records that they opened it. */
    public function show(Request $request, string $token)
    {
        $contract = $this->byToken($token);

        $this->signing->recordView($contract, ContractParty::PARTY, $request->ip());

        return response()->json($this->payload($contract->fresh(['pages', 'signatures', 'category', 'discussions.author'])));
    }

    /** The counterparty signs. */
    public function sign(Request $request, string $token)
    {
        $contract = $this->byToken($token);

        $data = $request->validate([
            'method' => ['required', Rule::in(ContractParty::METHODS)],
            'image'  => 'nullable|string|max:1400000',
            'name'   => 'required|string|max:200',
            'email'  => 'nullable|email|max:200',
            // Sent by the browser only when the visitor allows it. Never
            // required — refusing the prompt must not block a signature.
            'latitude'       => 'nullable|numeric|between:-90,90',
            'longitude'      => 'nullable|numeric|between:-180,180',
            'location_label' => 'nullable|string|max:200',
        ]);

        $signed = $this->signing->sign(
            $contract, ContractParty::PARTY, $data, null,
            $request->ip(), $request->userAgent(),
        );

        return response()->json($this->payload($signed));
    }

    /** Negotiating from the outside — the same thread the staff screen shows. */
    public function comment(Request $request, string $token)
    {
        $contract = $this->byToken($token);

        $data = $request->validate([
            'body' => 'required|string|max:5000',
            'name' => 'nullable|string|max:120',
        ]);

        $this->contracts->comment(
            $contract, $data['body'], $contract->tenant_id, null,
            $data['name'] ?? $contract->party_name,
        );

        return response()->json($this->payload($contract->fresh(['pages', 'signatures', 'category', 'discussions.author'])), 201);
    }

    /** The PDF, for a counterparty who wants to keep or print a copy. */
    public function pdf(string $token, ContractDocumentService $docs)
    {
        $contract = $this->byToken($token);

        return \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.contract_module', $docs->renderData($contract),
        )->stream("contract-{$contract->reference_no}.pdf");
    }

    /**
     * Verification, for somebody holding a printed copy.
     *
     * Deliberately thin: it confirms the document is real and says who signed it
     * and when, and nothing else. A person scanning a QR from a piece of paper
     * has proved only that they hold the paper — that is not grounds to hand
     * over the value, the terms or the negotiation thread.
     */
    public function verify(string $token)
    {
        $contract = $this->byToken($token);

        return response()->json([
            'reference_no'    => $contract->reference_no,
            'title'           => $contract->title,
            'status'          => $contract->status,
            'is_fully_signed' => $contract->fully_signed_at !== null,
            'executed_on'     => $contract->fully_signed_at?->toDateString(),
            'signatories'     => $contract->signatures->whereNotNull('signed_at')->map(fn ($s) => [
                'party'          => $s->party_label,
                'name'           => $s->signer_name,
                'signed_on'      => $s->signed_at->toDateString(),
                'certificate_no' => $s->certificate_no,
            ])->values(),
        ]);
    }

    /* ── Internals ──────────────────────────────────────────────── */

    private function byToken(string $token): Contract
    {
        $contract = Contract::where('public_token', $token)
            ->with(['pages', 'signatures', 'category:id,name', 'discussions.author:id,name'])
            ->first();

        abort_unless($contract, 404, 'Contract not found');

        return $contract;
    }

    /** Built by hand — see the class docblock. */
    private function payload(Contract $c): array
    {
        return [
            'reference_no' => $c->reference_no,
            'title'        => $c->title,
            'description'  => $c->description,
            'category'     => $c->category?->name,
            'party_name'   => $c->party_name,
            'value'        => $c->value,
            'currency'     => $c->currency,
            'start_date'   => $c->start_date?->toDateString(),
            'end_date'     => $c->end_date?->toDateString(),
            'status'       => $c->status,
            'is_fully_signed' => $c->fully_signed_at !== null,
            'pages' => $c->pages->map(fn ($p) => [
                'title' => $p->title, 'content' => $p->content,
            ])->values(),
            'signatures' => $c->signatures->map(fn ($s) => [
                'party'       => $s->signer_party,
                'party_label' => $s->party_label,
                'signer_name' => $s->signer_name,
                'signed_at'   => $s->signed_at,
                'method'      => $s->method,
                'image'       => $s->image,
                'certificate_no' => $s->certificate_no,
            ])->values(),
            // Whether it is this visitor's turn, so the page can say "waiting for
            // the other side" instead of showing a signature pad that will be
            // refused.
            'awaiting_me' => $c->signatures
                ->firstWhere('signer_party', ContractParty::PARTY)?->signed_at === null,
            'discussions' => $c->discussions->map(fn ($d) => [
                'author'      => $d->author_name,
                'is_external' => $d->is_external,
                'body'        => $d->body,
                'created_at'  => $d->created_at,
            ])->values(),
        ];
    }
}
