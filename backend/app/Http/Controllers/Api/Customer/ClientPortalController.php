<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\Customer\ClientFeedback;
use App\Services\Customer\ClientPortalAuthService;
use App\Services\Customer\ClientPortalService;
use App\Services\Customer\Contracts\TicketIntakeContract;
use App\Services\Customer\TicketIntakeUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer portal's data surface.
 *
 * Every method reads the contact and client off the request, put there by
 * EnsureClientPortalAccess. No endpoint accepts a client id from the caller:
 * "show me customer 12" is not expressible, so cross-customer access is not a
 * bug that can be introduced by forgetting a check — it has nowhere to live.
 */
class ClientPortalController extends Controller
{
    public function __construct(
        private ClientPortalService $portal,
        private ClientPortalAuthService $auth,
    ) {
    }

    private function contact(Request $r): ClientContact
    {
        return $r->attributes->get('clientContact');
    }

    private function client(Request $r): Client
    {
        return $r->attributes->get('portalClient');
    }

    public function me(Request $r)
    {
        $c = $this->contact($r);

        return response()->json([
            'id'          => $c->id,
            'first_name'  => $c->first_name,
            'last_name'   => $c->last_name,
            'email'       => $c->email,
            'title'       => $c->title,
            'department'  => $c->department,
            'phone'       => $c->phone,
            'permissions' => is_array($c->permissions) ? $c->permissions : [],
            'company'     => $this->client($r)->company,
            // Whether Helpdesk has registered a real ticket intake yet. The
            // portal hides the "raise a ticket" form when it has not, rather
            // than offering a button that can only fail.
            'can_raise_ticket' => $this->ticketIntakeAvailable(),
        ]);
    }

    public function dashboard(Request $r)
    {
        return response()->json($this->portal->dashboard($this->contact($r), $this->client($r)));
    }

    /** The customer's own profile fields. Company data stays read-only. */
    public function updateProfile(Request $r)
    {
        $c = $this->contact($r);
        $data = $r->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'phone'      => ['nullable', 'string', 'max:30', new \App\Rules\PhoneNumber()],
            'title'      => 'nullable|string|max:120',
        ]);

        // Email is excluded on purpose: it is the login identifier and changing
        // it here would let a contact move their own account to another mailbox.
        $c->update($data);

        return response()->json(['status' => 'success', 'data' => $c->fresh()]);
    }

    public function changePassword(Request $r)
    {
        $data = $r->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $this->auth->changePassword($this->contact($r), $data['current_password'], $data['password']);

        return response()->json(['status' => 'success', 'message' => 'Your password has been changed.']);
    }

    // ── Records ──────────────────────────────────────────────────────────
    // Each is permission-gated and scoped to the contact's own client. The
    // column lists are explicit: a `select *` here would leak whatever internal
    // field someone adds to those tables next.

    public function invoices(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'invoice');
        $c = $this->client($r);

        $q = DB::table('sales_invoices')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)
            ->whereNotIn('status', ['Draft', 'Cancelled']);

        if ($r->query('filter') === 'overdue') {
            $q->where('balance', '>', 0)->whereDate('due_date', '<', now()->toDateString());
        }

        return response()->json($q->orderByDesc('date')
            ->get(['id', 'number', 'date', 'due_date', 'total', 'paid', 'balance', 'status', 'currency']));
    }

    public function payments(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'invoice');
        $c = $this->client($r);

        return response()->json(
            DB::table('sales_payments as p')
                ->join('sales_invoices as i', 'i.id', '=', 'p.invoice_id')
                ->where('i.tenant_id', $c->tenant_id)->where('i.client_id', $c->id)
                ->orderByDesc('p.date')
                ->get(['p.id', 'p.date', 'p.amount', 'p.mode', 'i.number as invoice_number'])
        );
    }

    public function estimates(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'estimate');
        $c = $this->client($r);

        // Drafts are ours until sent — a customer seeing an unsent estimate is
        // seeing a number we have not agreed to yet.
        return response()->json(DB::table('estimates')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)
            ->where('status', '!=', 'Draft')
            ->orderByDesc('date')
            ->get(['id', 'reference', 'date', 'valid_until', 'total', 'status', 'currency']));
    }

    public function proposals(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'proposal');
        $c = $this->client($r);

        // rel_type/rel_id, not client_id — proposals is polymorphic. Querying
        // client_id returned nothing at all, so the portal always said
        // "Nothing here yet" however many proposals the customer had.
        return response()->json(DB::table('proposals')
            ->where('tenant_id', $c->tenant_id)
            ->where('rel_type', 'customer')->where('rel_id', $c->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'Draft')
            ->orderByDesc('created_at')
            ->get(['id', 'subject', 'total', 'status', 'created_at']));
    }

    public function creditNotes(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'invoice');
        $c = $this->client($r);

        return response()->json(DB::table('credit_notes')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)
            ->orderByDesc('date')
            ->get(['id', 'number', 'date', 'total', 'status']));
    }

    public function contracts(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'contract');
        $c = $this->client($r);

        return response()->json(DB::table('client_contracts')
            ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)
            ->orderByDesc('start_date')
            ->get(['id', 'subject', 'contract_type', 'value', 'start_date', 'end_date', 'status']));
    }

    public function projects(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'project');
        $c = $this->client($r);

        if (! Schema::hasTable('projects')) {
            return response()->json([]);
        }

        return response()->json(DB::table('projects')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('customer_id', $c->id)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'progress']));
    }

    public function tickets(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'support');
        $c = $this->client($r);

        if (! Schema::hasTable('tickets')) {
            return response()->json([]);
        }

        return response()->json(DB::table('tickets')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('customer_id', $c->id)
            ->whereNull('merged_into_id')
            ->orderByDesc('created_at')
            ->get(['id', 'subject', 'status', 'priority', 'created_at', 'resolved_at']));
    }

    /**
     * Raise a support ticket from the portal.
     *
     * Deliberately does NOT insert into `tickets`. The read above is a guarded
     * query builder because a SELECT cannot get a ticket wrong; a write can —
     * the number, the SLA clock, the department routing, the manager
     * notification and the acknowledgement email all live in Helpdesk's
     * createTicket(). So this hands over the facts and lets Helpdesk build it.
     *
     * client_id is never accepted from the caller: it comes off the
     * authenticated contact, like every other endpoint here.
     */
    public function raiseTicket(Request $r, TicketIntakeContract $intake)
    {
        $contact = $this->contact($r);
        $client  = $this->client($r);

        $this->portal->assertCan($contact, 'support');

        $data = $r->validate([
            'subject'  => 'required|string|max:191',
            'body'     => 'required|string|max:10000',
            // A hint only — Helpdesk caps it, so the portal cannot self-assign
            // Urgent. Anything outside the list is rejected rather than coerced.
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        $ticketId = $intake->createFromCustomerPortal(
            tenantId:        (int) $client->tenant_id,
            clientId:        (int) $client->id,
            clientContactId: (int) $contact->id,
            requesterName:   $name !== '' ? $name : ($contact->email ?? 'Customer'),
            requesterEmail:  (string) $contact->email,
            subject:         $data['subject'],
            body:            $data['body'],
            priority:        $data['priority'] ?? null,
        );

        return response()->json([
            'message' => 'Your ticket has been raised. We have emailed you a confirmation.',
            'id'      => $ticketId,
        ], 201);
    }

    /** True once Helpdesk has bound a real implementation over the placeholder. */
    private function ticketIntakeAvailable(): bool
    {
        return ! app(TicketIntakeContract::class) instanceof TicketIntakeUnavailable;
    }

    public function statement(Request $r)
    {
        $this->portal->assertCan($this->contact($r), 'invoice');
        $c = $this->client($r);

        $invoices = DB::table('sales_invoices')
            ->whereNull('deleted_at')
            ->where('tenant_id', $c->tenant_id)->where('client_id', $c->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->get(['number', 'date', 'total', 'paid', 'balance']);

        return response()->json([
            'rows'    => $invoices,
            'totals'  => [
                'invoiced'    => round((float) $invoices->sum('total'), 2),
                'paid'        => round((float) $invoices->sum('paid'), 2),
                'outstanding' => round((float) $invoices->sum('balance'), 2),
            ],
        ]);
    }

    /**
     * §10 — the customer answers a satisfaction survey.
     *
     * CSAT and NPS existed as a staff-only register: somebody typed in a score
     * they had been told on a phone call. That is a record of a conversation,
     * not a survey — the person whose opinion it is had no way to give it. The
     * legacy CRM did let customers submit feedback (its feedback module carried
     * a client-facing controller), so this was also a regression.
     *
     * Writes to client_feedback, which Customer owns, so no module boundary is
     * crossed. `collected_via` distinguishes these from staff-entered scores;
     * Health weighs them identically, but a human reading the list should be
     * able to tell a survey response from a note of a phone call.
     */
    public function submitFeedback(Request $r)
    {
        $contact = $this->contact($r);
        $client  = $this->client($r);

        // The bound depends on the metric: 9 is a valid NPS and an impossible
        // CSAT, and one rule for both would skew every average built on it.
        $metric = $r->input('metric');
        $max    = ClientFeedback::MAX[$metric] ?? 10;

        $data = $r->validate([
            'metric'   => ['required', \Illuminate\Validation\Rule::in(ClientFeedback::METRICS)],
            'score'    => "required|integer|min:0|max:{$max}",
            'comments' => 'nullable|string|max:2000',
        ]);

        // One response per metric per day. Enough to correct a mis-tap, not
        // enough for a disgruntled afternoon to bury the average.
        $recent = ClientFeedback::forTenant($client->tenant_id)
            ->where('client_id', $client->id)
            ->where('client_contact_id', $contact->id)
            ->where('metric', $data['metric'])
            ->whereDate('responded_at', now()->toDateString())
            ->first();

        $payload = [
            'tenant_id'         => $client->tenant_id,
            'client_id'         => $client->id,
            'client_contact_id' => $contact->id,
            'metric'            => $data['metric'],
            'score'             => $data['score'],
            'comments'          => $data['comments'] ?? null,
            'collected_via'     => 'portal',
            'responded_at'      => now(),
        ];

        $row = $recent ? tap($recent)->update($payload) : ClientFeedback::create($payload);

        return response()->json([
            'message' => 'Thank you — your feedback has been recorded.',
            'id'      => $row->id,
        ], $recent ? 200 : 201);
    }

    /** What this contact has already told us, so the form can show its state. */
    public function myFeedback(Request $r)
    {
        $contact = $this->contact($r);
        $client  = $this->client($r);

        return response()->json(
            ClientFeedback::forTenant($client->tenant_id)
                ->where('client_id', $client->id)
                ->where('client_contact_id', $contact->id)
                ->orderByDesc('responded_at')
                ->limit(20)
                ->get(['id', 'metric', 'score', 'comments', 'responded_at'])
        );
    }

    public function notes(Request $r)
    {
        return response()->json($this->portal->notes($this->client($r)));
    }

    public function files(Request $r)
    {
        return response()->json($this->portal->files($this->client($r)));
    }

    /** The other people at this company, so a contact knows who else has access. */
    public function contacts(Request $r)
    {
        $c = $this->client($r);

        return response()->json(
            ClientContact::forTenant($c->tenant_id)
                ->where('client_id', $c->id)
                ->where('active', true)
                ->get(['id', 'first_name', 'last_name', 'email', 'title', 'department', 'is_primary'])
        );
    }
}
