<?php

namespace App\Http\Controllers\Api\Contract;

use App\Http\Controllers\Controller;
use App\Models\Contract\Contract;
use App\Services\Contract\ContractDocumentService;
use App\Services\Contract\ContractService;
use App\Support\Contract\ContractParty;
use Illuminate\Http\Request;

/**
 * A customer's or vendor's own contracts, inside their portal.
 *
 * A contract linked to somebody has to be visible to that somebody. Until this
 * existed, the module knew who each agreement was with and showed it only to
 * staff — the other party could reach their contract solely through the signing
 * link they were e-mailed, so anyone who lost that e-mail lost the document.
 *
 * ── One controller, three portals ───────────────────────────────────────
 * The client portal, the TPV portal and the Purchase portal each resolve their
 * own identity onto the request (`portalClient`, `portalVendor`,
 * `purchaseVendor`). This reads whichever is present rather than being written
 * three times, and — importantly — it never accepts an id from the caller. The
 * party is taken from the authenticated session, so there is no id in the URL
 * to tamper with and no way to ask for somebody else's agreements.
 *
 * The existing portal controllers are untouched; these routes are declared in
 * routes/contract.php against the same middleware.
 */
class PartyContractController extends Controller
{
    /** Every contract this party is a party to. */
    public function index(Request $request)
    {
        [$class, $id] = $this->callerParty($request);

        $rows = Contract::where('tenant_id', $this->tenantOf($request))
            ->where('party_type', $class)
            ->where('party_id', $id)
            // A draft is ours until we send it. Showing the other side a
            // half-written agreement — or one we decided not to send — would be
            // worse than showing them nothing.
            ->where('status', '!=', 'draft')
            ->with(['category:id,name', 'signatures', 'pages', 'discussions.author:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $rows->map(fn ($c) => $this->row($c))->values()]);
    }

    /**
     * Comment on their own contract — the same thread staff read.
     *
     * The contract is named in the BODY, not the URL. The client portal's whole
     * security model is that no route accepts an identifier from the caller, so
     * the guarantee can be audited by scanning the route table rather than by
     * reading every controller (ClientPortalTest pins exactly that). The id is
     * still checked against the caller's own party before anything is written.
     */
    public function comment(Request $request, ContractService $contracts)
    {
        $data = $request->validate([
            'contract_id' => 'required|integer',
            'body'        => 'required|string|max:5000',
        ]);

        $contract = Contract::find($data['contract_id']);
        abort_unless($contract, 404, 'Contract not found');
        $this->assertTheirs($request, $contract);

        $contracts->comment(
            $contract, $data['body'], (int) $contract->tenant_id, null,
            $contract->party_name ?: 'Counterparty',
        );

        return response()->json(['status' => 'ok'], 201);
    }

    /* ── Internals ──────────────────────────────────────────────── */

    /**
     * Who is calling, from the session — never from the URL.
     *
     * Returns the morph class and id, matching how the contract stores its
     * counterparty.
     */
    private function callerParty(Request $request): array
    {
        foreach (['portalClient', 'portalVendor', 'purchaseVendor'] as $key) {
            if ($party = $request->attributes->get($key)) {
                return [$party::class, (int) $party->id];
            }
        }

        // Reached only if a route is added to a group whose middleware resolves
        // none of the three. Refusing beats guessing.
        abort(403, 'This area is for customer and vendor accounts.');
    }

    private function tenantOf(Request $request): int
    {
        foreach (['portalClient', 'portalVendor', 'purchaseVendor'] as $key) {
            if ($party = $request->attributes->get($key)) {
                return (int) $party->tenant_id;
            }
        }

        abort(403, 'This area is for customer and vendor accounts.');
    }

    /**
     * 404, not 403 — somebody else's contract should not be confirmed to exist.
     * Also refuses a draft, for the same reason index() hides them.
     */
    private function assertTheirs(Request $request, Contract $contract): void
    {
        [$class, $id] = $this->callerParty($request);

        abort_unless(
            (int) $contract->tenant_id === $this->tenantOf($request)
            && $contract->party_type === $class
            && (int) $contract->party_id === $id
            && $contract->status !== 'draft',
            404, 'Contract not found',
        );
    }

    /** The shape both the list and the detail agree on. */
    private function row(Contract $c): array
    {
        return [
            'id'            => $c->id,
            'reference_no'  => $c->reference_no,
            'title'         => $c->title,
            'category'      => $c->category?->name,
            'value'         => $c->value,
            'currency'      => $c->currency,
            'start_date'    => $c->start_date?->toDateString(),
            'end_date'      => $c->end_date?->toDateString(),
            'status'        => $c->status,
            'is_fully_signed' => $c->fully_signed_at !== null,
            'days_to_expiry'  => $c->days_to_expiry,
            'description'   => $c->description,
            'pages'         => $c->relationLoaded('pages')
                ? $c->pages->map(fn ($p) => ['title' => $p->title, 'content' => $p->content])->values()
                : [],
            'discussions'   => $c->relationLoaded('discussions')
                ? $c->discussions->map(fn ($d) => [
                    'author' => $d->author_name, 'is_external' => $d->is_external,
                    'body' => $d->body, 'created_at' => $d->created_at,
                ])->values()
                : [],
            // Their own document and signing page, through the token that was
            // e-mailed to them. This is the counterparty asking for their own
            // contract, which is exactly who the token was minted for — and it
            // keeps every id out of the portal's own URLs.
            'pdf_url'       => url("/api/public/contracts/{$c->public_token}/pdf"),
            'sign_url'      => rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/')
                               .'/contracts/sign/'.$c->public_token,
            'awaiting_me'   => $c->signatures
                ->firstWhere('signer_party', ContractParty::PARTY)?->signed_at === null,
            'signatures'    => $c->signatures->map(fn ($s) => [
                'party'       => $s->signer_party,
                'party_label' => $s->party_label,
                'signer_name' => $s->signer_name,
                'signed_at'   => $s->signed_at,
                'method'      => $s->method,
                'image'       => $s->image,
                'certificate_no' => $s->certificate_no,
            ])->values(),
        ];
    }
}
