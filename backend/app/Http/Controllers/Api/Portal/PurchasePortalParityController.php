<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Helpdesk\Ticket;
use App\Models\Project\Project;
use App\Models\Project\ProjectExpense;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorViolation;
use App\Models\Task\Task;
use App\Models\Vendor\VendorAward;
use App\Models\Vendor\VendorReferral;
use App\Models\Vendor\VendorShipment;
use App\Models\Vendor\VendorShipmentPackage;
use App\Services\Helpdesk\HelpdeskService;
use App\Services\Purchase\PurchaseVendorPerformanceService;
use App\Services\StatusService;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Purchase Vendor Portal — parity endpoints mirroring the TPV portal's General
 * (Customer), Execution (Projects/Tasks/Tickets/Expenses), Performance
 * (Feedback/Penalty/Award/Referral) and Compliance (Shipments) sections, scoped
 * to the authenticated PurchaseVendor. Read/write shapes match the TPV endpoints
 * so the same portal pages render against either portal via an `api` prop.
 *
 * Projects/Tickets/Expenses reach the vendor through the shared project link
 * (projects.link_type = 'purchase_vendor'); Award/Referral/Shipment ride the
 * polymorphic Vendor tables (purchase_vendor_id column). Risk Score, PTW and
 * Incidents have no Purchase model and stay ComingSoon on this portal.
 */
class PurchasePortalParityController extends Controller
{
    private function vendor(Request $request): PurchaseVendor
    {
        $v = $request->user();
        abort_unless($v instanceof PurchaseVendor, 403, 'This area is for Purchase vendor accounts only.');

        return $v;
    }

    /** Ids of the projects linked to this purchase vendor. */
    private function projectIds(PurchaseVendor $v): array
    {
        return Project::where('tenant_id', $v->tenant_id)
            ->forVendorLink($v->id, 'purchase_vendor')
            ->pluck('id')->all();
    }

    /* ── General › Customer ──────────────────────────────────────────────── */
    public function customers(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => $v->customers()->orderByDesc('id')
                ->get(['id', 'company', 'phone', 'website', 'gst_number', 'city', 'state', 'country', 'active']),
        ]);
    }

    /* ── Execution › Projects / Tasks / Tickets / Expenses ───────────────── */
    public function projects(Request $request)
    {
        $v = $this->vendor($request);
        $rows = Project::where('tenant_id', $v->tenant_id)
            ->forVendorLink($v->id, 'purchase_vendor')
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'progress', 'deadline'])
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'status' => $p->status,
                'progress' => (int) $p->progress, 'role' => 'Purchase vendor',
                'deadline' => optional($p->deadline)->toDateString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function tasks(Request $request)
    {
        $v = $this->vendor($request);
        $tasks = Task::forTenant($v->tenant_id)
            ->where('rel_type', 'purchase_vendor')->where('rel_id', $v->id)
            ->orderByDesc('id')
            ->get(['id', 'name', 'status', 'priority', 'due_date']);

        return response()->json(['data' => $tasks->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'status' => $t->status,
            'priority' => $t->priority, 'due_date' => optional($t->due_date)->toDateString(), 'project' => null,
        ])]);
    }

    public function taskStatuses(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json(['data' => app(StatusService::class)->labels('task', (int) $v->tenant_id)]);
    }

    public function updateTaskStatus(Request $request, Task $task)
    {
        $v = $this->vendor($request);
        abort_unless($task->rel_type === 'purchase_vendor' && (int) $task->rel_id === (int) $v->id && (int) $task->tenant_id === (int) $v->tenant_id, 404, 'Task not found');

        $keys = app(StatusService::class)->keys('task', (int) $v->tenant_id);
        $data = $request->validate(['status' => ['required', Rule::in($keys)]]);
        $task->update(['status' => $data['status']]);

        return response()->json(['data' => ['id' => $task->id, 'status' => $task->status]]);
    }

    /**
     * The vendor's tickets: raised BY them, or opened against their projects.
     *
     * This used to list only tickets tied to one of the vendor's projects. That
     * was the whole story while the portal had no way to raise one - but a
     * vendor asking for help is not asking about a project, so a ticket they
     * raised themselves would have been invisible to them the moment it was
     * created. Both sources are now included, which is what the TPV portal has
     * always done.
     */
    public function tickets(Request $request)
    {
        $v = $this->vendor($request);

        $rows = Ticket::forTenant($v->tenant_id)
            ->where(fn ($q) => $this->scopeOwnTickets($q, $v))
            ->orderByDesc('id')
            ->get(['id', 'subject', 'status', 'priority'])
            ->map(fn (Ticket $t) => ['id' => $t->id, 'subject' => $t->subject, 'status' => $t->status, 'priority' => $t->priority]);

        return response()->json(['data' => $rows]);
    }

    /**
     * The vendor raises a support ticket from the portal.
     *
     * The Purchase portal had no such endpoint, and its Tickets screen was
     * mounted with `ticketWrite: false` - so the Raise Ticket button was never
     * drawn and the rows could not be opened. A Purchase vendor could see
     * tickets about their projects and had no way to ask for anything.
     *
     * The identity is the wrinkle. A TPV vendor signs in as a User, so its
     * ticket carries `created_by`. A PurchaseVendor is its own model and may
     * have no User at all, so `created_by` is set only when one exists and the
     * vendor's email is always recorded as the requester - which is both what
     * ownership is checked against and where Support's reply goes.
     */
    public function raiseTicket(Request $request, HelpdeskService $helpdesk)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'subject'  => 'required|string|max:191',
            'body'     => 'required|string|max:10000',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        abort_if(
            blank($v->email) && ! $v->user_id,
            422,
            'Your account has no email address on file, so Support would have no way to reply. Add one on your profile first.'
        );

        $ticket = $helpdesk->createTicket([
            'subject'         => $data['subject'],
            // Normalised at the boundary - see RichText.
            'description'     => RichText::fromUntrusted($data['body']),
            'created_by'      => $v->user_id,
            'requester_name'  => $v->company_name,
            'requester_email' => $v->email ?: $v->user?->email,
            'source'          => 'portal',
            'assigned_to'     => null,
            'priority'        => $data['priority'] ?? 'medium',
        ], (int) $v->tenant_id);

        return response()->json(['id' => $ticket->id, 'message' => 'Your ticket has been raised.'], 201);
    }

    /** One of the vendor's own tickets, with its reply thread. */
    public function ticket(Request $request, Ticket $ticket)
    {
        $this->assertOwnTicket($request, $ticket);

        $replies = $ticket->replies()->orderBy('id')->get(['id', 'sender_type', 'message', 'created_at'])
            ->map(fn ($r) => [
                'id'           => $r->id,
                'mine'         => $r->sender_type === 'client',
                'author'       => $r->sender_type === 'client' ? 'You' : 'Support',
                'message'      => RichText::toText($r->message),
                'message_html' => RichText::display($r->message),
                'at'           => optional($r->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'id' => $ticket->id, 'subject' => $ticket->subject, 'status' => $ticket->status,
            'priority' => $ticket->priority,
            'description'      => RichText::toText($ticket->description),
            'description_html' => RichText::display($ticket->description),
            'replies' => $replies,
        ]);
    }

    /** The vendor posts a reply on its own ticket. */
    public function replyTicket(Request $request, Ticket $ticket, HelpdeskService $helpdesk)
    {
        $this->assertOwnTicket($request, $ticket);
        $data = $request->validate(['message' => 'required|string|max:10000']);

        $helpdesk->addReply($ticket->id, [
            'sender_type' => 'client',   // the external requester - shown as the vendor
            'sender_id'   => null,
            'message'     => RichText::fromUntrusted($data['message']),
        ], (int) $ticket->tenant_id);

        return response()->json(['message' => 'Reply sent'], 201);
    }

    /**
     * 404, not 403, on someone else's ticket - a vendor should not learn that
     * another vendor's ticket exists.
     */
    private function assertOwnTicket(Request $request, Ticket $ticket): void
    {
        $v = $this->vendor($request);

        abort_unless(
            Ticket::forTenant($v->tenant_id)
                ->whereKey($ticket->id)
                ->where(fn ($q) => $this->scopeOwnTickets($q, $v))
                ->exists(),
            404,
            'Ticket not found'
        );
    }

    /**
     * What counts as this vendor's ticket: one raised by them (their User, or
     * their email as requester), or one on a project linked to them.
     *
     * A vendor with neither a User nor an email owns nothing - which must
     * resolve to no rows, never to every row, so the empty case is stated
     * rather than left to an empty where-group.
     */
    private function scopeOwnTickets($query, PurchaseVendor $v)
    {
        $ids = $this->projectIds($v);
        $matched = false;

        if ($ids) {
            $query->whereIn('project_id', $ids);
            $matched = true;
        }
        if ($v->user_id) {
            $query->orWhere('created_by', $v->user_id);
            $matched = true;
        }
        if (filled($v->email)) {
            $query->orWhere('requester_email', $v->email);
            $matched = true;
        }
        if (! $matched) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function expenses(Request $request)
    {
        $v = $this->vendor($request);
        $rows = ProjectExpense::whereIn('project_id', $this->projectIds($v) ?: [0])
            ->latest('expense_date')
            ->get(['id', 'project_id', 'title', 'category', 'amount', 'expense_date', 'note', 'billable']);

        return response()->json(['data' => $rows]);
    }

    public function storeExpense(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'project_id'   => 'required|integer',
            'title'        => 'required|string|max:200',
            'category'     => 'nullable|string|max:100',
            'amount'       => 'required|numeric|min:0',
            'expense_date' => 'nullable|date',
            'note'         => 'nullable|string|max:1000',
        ]);
        abort_unless(in_array((int) $data['project_id'], array_map('intval', $this->projectIds($v)), true), 404, 'Project not found');

        $expense = ProjectExpense::create([
            'tenant_id' => $v->tenant_id, 'project_id' => $data['project_id'], 'title' => $data['title'],
            'category' => $data['category'] ?? null, 'amount' => $data['amount'],
            'expense_date' => $data['expense_date'] ?? now()->toDateString(), 'note' => $data['note'] ?? null,
            'billable' => true, 'created_by' => null,
        ]);

        return response()->json($expense, 201);
    }

    /* ── Performance › Feedback (VPI) / Penalty ──────────────────────────── */
    public function feedback(Request $request, PurchaseVendorPerformanceService $vpi)
    {
        $v = $this->vendor($request);

        return response()->json(['live' => $vpi->compute($v)]);
    }

    public function violations(Request $request)
    {
        $v = $this->vendor($request);
        $rows = PurchaseVendorViolation::where('tenant_id', $v->tenant_id)
            ->where('purchase_vendor_id', $v->id)
            ->latest('occurred_at')
            ->get(['id', 'reference', 'type', 'severity', 'description', 'occurred_at', 'points', 'status']);

        return response()->json([
            'data' => $rows, 'total_points' => (int) $rows->sum('points'),
            'open_count' => $rows->where('status', '!=', 'Closed')->count(),
        ]);
    }

    /* ── Performance › Award / Referral ──────────────────────────────────── */
    public function awards(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => VendorAward::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->latest('awarded_on')->get(['id', 'title', 'category', 'description', 'awarded_on']),
        ]);
    }

    public function referrals(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => VendorReferral::where('tenant_id', $v->tenant_id)->where('referred_by_purchase_vendor_id', $v->id)
                ->latest('id')->get(['id', 'company_name', 'contact_name', 'contact_email', 'contact_phone', 'note', 'status', 'created_at']),
        ]);
    }

    public function storeReferral(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'company_name'  => 'required|string|max:200',
            'contact_name'  => 'nullable|string|max:150',
            'contact_email' => 'nullable|email|max:180',
            'contact_phone' => 'nullable|string|max:40',
            'note'          => 'nullable|string|max:2000',
        ]);

        $referral = VendorReferral::create(array_merge($data, [
            'tenant_id' => $v->tenant_id, 'referred_by_purchase_vendor_id' => $v->id, 'status' => 'New',
        ]));

        return response()->json($referral, 201);
    }

    /* ── Compliance › Pre Alert / Packages / Shipping ────────────────────── */
    public function shipments(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data' => VendorShipment::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->withCount('packages')->latest('id')
                ->get(['id', 'reference', 'courier', 'tracking_number', 'status', 'expected_date', 'dispatched_on', 'delivered_on', 'notes']),
            'statuses' => VendorShipment::STATUSES,
        ]);
    }

    public function storeShipment(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'courier'                => 'nullable|string|max:120',
            'tracking_number'        => 'nullable|string|max:120',
            'expected_date'          => 'nullable|date',
            'dispatched_on'          => 'nullable|date',
            'notes'                  => 'nullable|string|max:2000',
            'packages'               => 'array',
            'packages.*.description' => 'required_with:packages|string|max:255',
            'packages.*.qty'         => 'nullable|integer|min:1',
            'packages.*.weight'      => 'nullable|string|max:40',
            'packages.*.dimensions'  => 'nullable|string|max:80',
        ]);

        $shipment = DB::transaction(function () use ($v, $data) {
            $shipment = VendorShipment::create([
                'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id, 'status' => 'Pre-Alert',
                'courier' => $data['courier'] ?? null, 'tracking_number' => $data['tracking_number'] ?? null,
                'expected_date' => $data['expected_date'] ?? null, 'dispatched_on' => $data['dispatched_on'] ?? null,
                'notes' => $data['notes'] ?? null, 'created_by' => null,
            ]);
            foreach ($data['packages'] ?? [] as $p) {
                $shipment->packages()->create([
                    'tenant_id' => $v->tenant_id, 'description' => $p['description'],
                    'qty' => $p['qty'] ?? 1, 'weight' => $p['weight'] ?? null, 'dimensions' => $p['dimensions'] ?? null,
                ]);
            }

            return $shipment;
        });

        return response()->json($shipment->fresh('packages'), 201);
    }

    public function updateShipmentStatus(Request $request, VendorShipment $shipment)
    {
        $v = $this->vendor($request);
        abort_unless((int) $shipment->purchase_vendor_id === (int) $v->id && (int) $shipment->tenant_id === (int) $v->tenant_id, 404, 'Shipment not found');

        $data = $request->validate(['status' => ['required', Rule::in(VendorShipment::STATUSES)]]);
        $patch = ['status' => $data['status']];
        if ($data['status'] === 'Dispatched' && ! $shipment->dispatched_on) {
            $patch['dispatched_on'] = now()->toDateString();
        }
        if ($data['status'] === 'Delivered' && ! $shipment->delivered_on) {
            $patch['delivered_on'] = now()->toDateString();
        }
        $shipment->update($patch);

        return response()->json($shipment->fresh());
    }

    public function shipmentPackages(Request $request)
    {
        $v = $this->vendor($request);
        $rows = VendorShipmentPackage::where('tenant_id', $v->tenant_id)
            ->whereIn('vendor_shipment_id', VendorShipment::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)->select('id'))
            ->with('shipment:id,reference,status')
            ->latest('id')
            ->get(['id', 'vendor_shipment_id', 'description', 'qty', 'weight', 'dimensions']);

        return response()->json(['data' => $rows]);
    }

    /* ── Compliance & HSSE › PTW / Incidents (Purchase-native) ───────────── */
    public function permits(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data'  => \App\Models\Purchase\PurchaseWorkPermit::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->latest('id')->get(['id', 'reference', 'type', 'title', 'location', 'valid_from', 'valid_to', 'status']),
            'types' => \App\Models\Purchase\PurchaseWorkPermit::TYPES,
        ]);
    }

    public function requestPermit(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'type'        => ['required', Rule::in(\App\Models\Purchase\PurchaseWorkPermit::TYPES)],
            'title'       => 'required|string|max:200',
            'location'    => 'nullable|string|max:200',
            'description' => 'nullable|string|max:2000',
            'hazards'     => 'nullable|string|max:2000',
            'precautions' => 'nullable|string|max:2000',
            'valid_from'  => 'nullable|date',
            'valid_to'    => 'nullable|date|after_or_equal:valid_from',
        ]);

        $permit = \App\Models\Purchase\PurchaseWorkPermit::create(array_merge($data, [
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id, 'status' => 'Requested',
        ]));

        return response()->json($permit, 201);
    }

    public function incidents(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'data'       => \App\Models\Purchase\PurchaseHsseIncident::where('tenant_id', $v->tenant_id)->where('purchase_vendor_id', $v->id)
                ->latest('occurred_at')->get(['id', 'reference', 'type', 'severity', 'title', 'location', 'occurred_at', 'status']),
            'types'      => \App\Models\Purchase\PurchaseHsseIncident::TYPES,
            'severities' => \App\Models\Purchase\PurchaseHsseIncident::SEVERITIES,
        ]);
    }

    public function reportIncident(Request $request)
    {
        $v = $this->vendor($request);
        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'type'             => ['required', Rule::in(\App\Models\Purchase\PurchaseHsseIncident::TYPES)],
            'severity'         => ['required', Rule::in(\App\Models\Purchase\PurchaseHsseIncident::SEVERITIES)],
            'occurred_at'      => 'nullable|date',
            'location'         => 'nullable|string|max:200',
            'description'      => 'nullable|string|max:2000',
            'immediate_action' => 'nullable|string|max:2000',
        ]);

        $incident = \App\Models\Purchase\PurchaseHsseIncident::create(array_merge($data, [
            'tenant_id' => $v->tenant_id, 'purchase_vendor_id' => $v->id, 'status' => 'Reported',
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]));

        return response()->json($incident, 201);
    }

    /* ── Performance › Risk Score (Purchase-native, read-only) ───────────── */
    public function risk(Request $request)
    {
        $v = $this->vendor($request);

        return response()->json([
            'assessed'    => $v->risk_assessed_at !== null,
            'level'       => $v->risk_level,
            'score'       => $v->risk_score,
            'monitoring'  => null,
            'breakdown'   => [],
            'assessed_at' => $v->risk_assessed_at,
        ]);
    }
}
