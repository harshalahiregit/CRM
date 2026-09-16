<?php

namespace App\Http\Controllers\Api\Contract;

use App\Http\Controllers\Controller;
use App\Models\Contract\Contract;
use App\Services\Contract\ContractDocumentService;
use App\Services\Contract\ContractService;
use App\Services\Contract\ContractSigningService;
use App\Support\Contract\ContractParty;
use App\Exceptions\BusinessException;
use App\Support\Contract\ContractStatus;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Contract module's staff surface.
 *
 * Every bound contract is tenant-guarded through assertTenant(), which every
 * method calls — one place, so a new endpoint cannot forget it.
 */
class ContractController extends Controller
{
    public function __construct(
        private ContractService $contracts,
        private ContractSigningService $signing,
    ) {}

    /* ── Dashboard ──────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json($this->contracts->list(
            $request->user()->tenant_id,
            $request->only(['status', 'category_id', 'search', 'expiring', 'party_type', 'party_id']),
        ));
    }

    public function stats(Request $request)
    {
        return response()->json($this->contracts->stats($request->user()->tenant_id));
    }

    public function show(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contracts->find($contract->id, $request->user()->tenant_id));
    }

    /* ── Create & edit ──────────────────────────────────────────── */

    public function store(Request $request)
    {
        $data = $this->validated($request, true);

        return response()->json(
            $this->contracts->create($data, $request->user()->tenant_id, $request->user()->id),
            201,
        );
    }

    public function update(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);

        return response()->json($this->contracts->update(
            $contract, $this->validated($request, false),
            $request->user()->tenant_id, $request->user()->id,
        ));
    }

    public function destroy(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);
        $contract->delete();

        return response()->json(['status' => 'ok']);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title'                => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'description'          => 'nullable|string|max:20000',
            'contract_category_id' => 'nullable|integer|exists:contract_categories,id',

            // Stable API keys, never class names — a client that could name the
            // class could point a contract at any model in the application.
            'party_type'  => ['nullable', Rule::in(array_keys(ContractService::PARTY_TYPES))],
            'party_id'    => 'nullable|integer|required_with:party_type',
            'party_name'  => 'nullable|string|max:200',
            'party_email' => 'nullable|email|max:200',

            'value'    => 'nullable|numeric|min:0|max:99999999999',
            'currency' => 'nullable|string|max:8',

            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'renewal_notice_days' => 'nullable|integer|min:0|max:365',

            'status' => ['nullable', Rule::in(ContractStatus::ALL)],

            // The long form. No page cap: the brief asks for 10+ pages and a
            // limit here would silently drop clauses off the end.
            'pages'           => 'nullable|array',
            'pages.*.title'   => 'nullable|string|max:200',
            'pages.*.content' => 'nullable|string',

            'links'                 => 'nullable|array',
            'links.*.linkable_type' => 'required_with:links|string|max:60',
            'links.*.linkable_id'   => 'required_with:links|integer',
            'links.*.label'         => 'nullable|string|max:200',
        ]);
    }

    /* ── Status, renewal ────────────────────────────────────────── */

    public function setStatus(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);
        $data = $request->validate(['status' => ['required', Rule::in(ContractStatus::ALL)]]);

        return response()->json($this->contracts->setStatus(
            $contract, $data['status'], $request->user()->tenant_id,
        ));
    }

    public function renew(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);
        $data = $request->validate([
            'title'      => 'nullable|string|max:200',
            'value'      => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
        ]);

        return response()->json($this->contracts->renew(
            $contract, $data, $request->user()->tenant_id, $request->user()->id,
        ), 201);
    }

    /* ── Signing (our side) ─────────────────────────────────────── */

    public function sign(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);

        $data = $request->validate([
            'method' => ['required', Rule::in(ContractParty::METHODS)],
            // ~1MB base64 data URL. Larger than this is a photograph, not a
            // signature, and would bloat every PDF it is embedded in.
            'image'  => 'nullable|string|max:1400000',
            'name'   => 'required|string|max:200',
            'email'  => 'nullable|email|max:200',
            'latitude'       => 'nullable|numeric|between:-90,90',
            'longitude'      => 'nullable|numeric|between:-180,180',
            'location_label' => 'nullable|string|max:200',
        ]);

        return response()->json($this->signing->sign(
            $contract, ContractParty::COMPANY, $data,
            $request->user()->id, $request->ip(), $request->userAgent(),
        ));
    }

    /* ── Discussion ─────────────────────────────────────────────── */

    public function comment(Request $request, Contract $contract)
    {
        $this->assertTenant($request, $contract);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return response()->json($this->contracts->comment(
            $contract, $data['body'], $request->user()->tenant_id, $request->user()->id,
        ), 201);
    }

    /* ── The document ───────────────────────────────────────────── */

    public function pdf(Request $request, Contract $contract, ContractDocumentService $docs)
    {
        $this->assertTenant($request, $contract);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.contract_module', $docs->renderData($contract),
        );

        // Inline, not download: the brief asks to open it in a new tab, and a
        // forced download makes "view" and "print" both go through the file
        // manager.
        return $pdf->stream("contract-{$contract->reference_no}.pdf");
    }

    /**
     * E-mail the contract to the party who has to sign it.
     *
     * Goes through TenantMailer, so it uses the tenant's own configured SMTP
     * server and From address rather than the application default — the vendor
     * should receive this from the company they are contracting with.
     *
     * Not queued, deliberately: the person pressing Send is standing there and
     * needs to be told whether it actually went. A queued send reports success
     * before the SMTP server has been spoken to, and a bad password then fails
     * silently in a log nobody reads.
     */
    public function send(Request $request, Contract $contract, ContractDocumentService $docs)
    {
        $this->assertTenant($request, $contract);

        $data = $request->validate([
            'to'      => 'required|email',
            // Ten is the practical ceiling before a mail server starts treating
            // the message as bulk.
            'cc'      => 'nullable|array|max:10',
            'cc.*'    => 'email',
            'subject' => 'nullable|string|max:200',
            'body'    => 'nullable|string|max:20000',
        ]);

        // A contract with no terms is an empty page with a signature box on it.
        if ($contract->pages()->count() === 0 && ! $contract->description) {
            throw new BusinessException(
                'This contract has no terms yet. Add them before sending it out.', 422);
        }

        $signUrl = FrontendUrl::to('/contracts/sign/'.$contract->public_token);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.contract_module', $docs->renderData($contract),
        )->output();

        $body = \App\Support\HtmlSanitizer::clean($data['body'] ?? sprintf(
            '<p>Please find the contract <strong>%s</strong> attached.</p>'
            .'<p>You can review and sign it online using the button below.</p>',
            e($contract->title),
        ));

        app(\App\Services\Mail\TenantMailer::class)->send(
            (int) $request->user()->tenant_id,
            $data['to'],
            new \App\Mail\Contract\ContractDispatchMail(
                $contract, $body, $signUrl, $pdf,
                $data['subject'] ?? "Contract for signature: {$contract->title}",
            ),
            $data['cc'] ?? [],
        );

        // Only after the send succeeds. Marking it sent first would leave a
        // contract that says it went out when the SMTP server refused it.
        $contract->forceFill([
            'sent_at' => now(),
            'status'  => $contract->status === ContractStatus::DRAFT
                ? ContractStatus::SENT
                : $contract->status,
            // Remember where it went, so the next send offers the same address
            // and the record says who was asked to sign.
            'party_email' => $contract->party_email ?: $data['to'],
        ])->save();

        $contract->discussions()->create([
            'tenant_id' => $contract->tenant_id,
            'user_id'   => $request->user()->id,
            'body'      => 'Contract sent to '.$data['to']
                .(! empty($data['cc']) ? ' (cc: '.implode(', ', $data['cc']).')' : '').'.',
        ]);

        return response()->json([
            'status'   => 'sent',
            'contract' => $this->contracts->find($contract->id, $request->user()->tenant_id),
        ]);
    }

    /**
     * The signing link for the counterparty.
     *
     * The token is hidden on the model, so this is the ONE place it is
     * disclosed — deliberately, to the staff member who is about to send it.
     */
    public function signingLink(Request $request, Contract $contract, ContractDocumentService $docs)
    {
        $this->assertTenant($request, $contract);

        return response()->json([
            'url'        => FrontendUrl::to('/contracts/sign/'.$contract->public_token),
            // The one honest answer to "why does this link say localhost". The
            // link is copied out of the UI and pasted into a chat window, so by
            // the time it fails it is a long way from anything that could
            // explain itself.
            'is_local'   => FrontendUrl::isDevFallback(),
            'verify_url' => $docs->verifyUrl($contract),
        ]);
    }

    /* ── Categories ─────────────────────────────────────────────── */

    public function categories(Request $request)
    {
        return response()->json($this->contracts->categories($request->user()->tenant_id));
    }

    /** Created inline from the form's + button. */
    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
        ]);

        return response()->json($this->contracts->createCategory(
            $data, $request->user()->tenant_id, $request->user()->id,
        ), 201);
    }

    /**
     * Contracts belonging to ONE customer or vendor.
     *
     * What the Customer and Vendor detail screens show on their Contracts tab.
     * Served from this module rather than added to theirs, so Sales, Purchase
     * and TPV keep working unchanged — they render a component and this answers
     * it. Drafts ARE included here: this is the internal side, and the person
     * looking at a customer's record should see the agreement they are still
     * drafting for them.
     */
    public function forParty(Request $request, string $partyType, int $partyId)
    {
        abort_unless(isset(ContractService::PARTY_TYPES[$partyType]), 404, 'Unknown party type');

        $rows = Contract::forTenant($request->user()->tenant_id)
            ->where('party_type', ContractService::PARTY_TYPES[$partyType])
            ->where('party_id', $partyId)
            ->with(['category:id,name', 'signatures'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    /** The counterparty pickers, so the form can offer customers and vendors. */
    public function parties(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            // A customer has no email of its own -- `clients` has no such
            // column. Selecting one here was a hard SQL error on MySQL (which
            // this picker swallows, leaving all three dropdowns blank) and on
            // SQLite quietly returned the literal string "email" under a key of
            // the same name. The address lives on the primary contact.
            'customer' => \App\Models\Customer\Client::forTenant($tenantId)
                ->with(['contacts:id,client_id,email,is_primary'])
                ->orderBy('company')->get(['id', 'company'])
                ->map(fn ($c) => [
                    'id'    => $c->id,
                    'name'  => $c->company,
                    'email' => optional($c->contacts->firstWhere('is_primary', true)
                               ?? $c->contacts->first())->email,
                ])->values(),
            'vendor' => \App\Models\Vendor\Vendor::forTenant($tenantId)
                ->orderBy('company_name')->get(['id', 'company_name as name', 'email']),
            'purchase_vendor' => \App\Models\Purchase\PurchaseVendor::forTenant($tenantId)
                ->orderBy('company_name')->get(['id', 'company_name as name', 'email']),
        ]);
    }

    private function assertTenant(Request $request, Contract $contract): void
    {
        abort_unless(
            (int) $contract->tenant_id === (int) $request->user()->tenant_id,
            404, 'Contract not found',
        );
    }
}
