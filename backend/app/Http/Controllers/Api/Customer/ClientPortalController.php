<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Services\Customer\ClientPortalAuthService;
use App\Services\Customer\ClientPortalService;
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
